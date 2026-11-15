<?php

namespace App\Services\Planner;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quyết định chung của nhóm trước khi có lịch (học Troupe: đi nhóm vướng nhất ở quyết định, không ở xếp lịch).
 *
 *  - Ai trong chuyến cũng đề xuất được lựa chọn và bỏ phiếu (kể cả người chỉ xem: góp ý không phải là sửa lịch).
 *  - Chủ chuyến / người có quyền sửa tạo câu hỏi và CHỐT. Chốt thì áp dụng vào chuyến:
 *      ngày đi -> ngày khởi hành; điểm đến -> điểm đến chuyến; chỗ ở -> một mục "cần đặt" trong Đặt chỗ (chưa đặt thật).
 *    Áp dụng tăng phiên bản chuyến như mọi lần sửa, nên người đang mở chuyến thấy ngay (TripPresence / /phien-ban).
 *  - Ai đi / có thể / không đi: số người "đi" gợi ý cho số người của chuyến; người "không đi" không được tính khi chia chi phí mới.
 */
final class TripDecide
{
    public const KINDS = ['date' => 'Ngày đi', 'destination' => 'Điểm đến', 'stay' => 'Chỗ ở', 'other' => 'Khác'];
    public const RSVP = ['going' => 'Đi', 'maybe' => 'Chưa quyết', 'no' => 'Không đi'];

    public static function ready(): bool
    {
        return Schema::hasTable('tbl_trip_polls') && Schema::hasColumn('tbl_trip_members', 'rsvp');
    }

    /** Toàn bộ cho khối "Cùng quyết định": câu hỏi, lựa chọn, số phiếu, ai bỏ, phiếu của mình; danh sách ai đi. */
    public function state(object $trip, int $uid, string $role): array
    {
        $names = [];
        foreach (app(TripSharing::class)->members($trip, false) as $m) {
            $names[$m['userId']] = $m['name'] !== '' ? $m['name'] : 'Thành viên';
        }
        $polls = DB::table('tbl_trip_polls')->where('tripId', $trip->tripId)->orderByRaw("status = 'closed'")->orderByDesc('pollId')->get();
        $opts = $polls->isEmpty() ? collect() : DB::table('tbl_trip_poll_options')->whereIn('pollId', $polls->pluck('pollId'))->orderBy('optionId')->get()->groupBy('pollId');
        $votes = $polls->isEmpty() ? collect() : DB::table('tbl_trip_poll_votes')->whereIn('pollId', $polls->pluck('pollId'))->get();
        $out = [];
        foreach ($polls as $p) {
            $pv = $votes->where('pollId', $p->pollId);
            $options = [];
            foreach ($opts->get($p->pollId, collect()) as $o) {
                $who = $pv->where('optionId', $o->optionId)->pluck('userId')->map(fn($u) => $names[(int) $u] ?? 'Người đã rời nhóm')->values()->all();
                $options[] = ['id' => (int) $o->optionId, 'label' => (string) $o->label, 'value' => $o->value, 'votes' => count($who), 'who' => $who,
                    'by' => $names[(int) $o->createdBy] ?? null, 'chosen' => (int) $p->chosenOptionId === (int) $o->optionId];
            }
            $mine = $pv->firstWhere('userId', $uid);
            $out[] = ['id' => (int) $p->pollId, 'kind' => (string) $p->kind, 'kindLabel' => self::KINDS[$p->kind] ?? 'Khác', 'question' => (string) $p->question,
                'open' => $p->status === 'open', 'myVote' => $mine ? (int) $mine->optionId : null, 'voters' => $pv->count(), 'members' => count($names),
                'by' => $names[(int) $p->createdBy] ?? null, 'options' => $options, 'canClose' => in_array($role, ['owner', 'edit'], true)];
        }

        return ['polls' => $out, 'people' => $this->people($trip), 'canCreate' => in_array($role, ['owner', 'edit'], true)];
    }

    /** @return array{list:list<array{userId:int,name:string,rsvp:string,isOwner:bool}>,going:int,maybe:int,no:int,groupSize:int} */
    public function people(object $trip): array
    {
        $owner = DB::table('tbl_users')->where('userId', $trip->userId)->first(['userId', 'fullName']);
        $list = $owner ? [['userId' => (int) $owner->userId, 'name' => (string) $owner->fullName, 'rsvp' => 'going', 'isOwner' => true]] : [];
        foreach (DB::table('tbl_trip_members as m')->join('tbl_users as u', 'u.userId', '=', 'm.userId')->where('m.tripId', $trip->tripId)->orderBy('m.memberId')
                     ->get(['u.userId', 'u.fullName', 'm.rsvp']) as $m) {
            $list[] = ['userId' => (int) $m->userId, 'name' => (string) $m->fullName, 'rsvp' => in_array($m->rsvp, ['going', 'maybe', 'no'], true) ? $m->rsvp : 'maybe', 'isOwner' => false];
        }
        $c = array_count_values(array_column($list, 'rsvp'));
        $guests = Schema::hasTable('tbl_trip_guests') ? DB::table('tbl_trip_guests')->where('tripId', $trip->tripId)->count() : 0;

        return ['list' => $list, 'going' => $c['going'] ?? 0, 'maybe' => $c['maybe'] ?? 0, 'no' => $c['no'] ?? 0, 'guests' => $guests,
            'groupSize' => (int) $trip->groupSize];
    }

    public function rsvp(object $trip, int $uid, string $v): void
    {
        if ((int) $trip->userId === $uid) {
            throw new \DomainException('Chủ chuyến luôn được tính là đi.');
        }
        if (!isset(self::RSVP[$v])) {
            throw new \DomainException('Lựa chọn không hợp lệ.');
        }
        DB::table('tbl_trip_members')->where(['tripId' => $trip->tripId, 'userId' => $uid])->update(['rsvp' => $v, 'rsvpAt' => now()]) || throw new \DomainException('Bạn không ở trong chuyến này.');
    }

    /** @param list<array{label?:string,value?:string}> $options */
    public function create(object $trip, int $uid, string $kind, string $question, array $options): int
    {
        if (!isset(self::KINDS[$kind])) {
            throw new \DomainException('Loại câu hỏi không hợp lệ.');
        }
        if (DB::table('tbl_trip_polls')->where('tripId', $trip->tripId)->where('status', 'open')->count() >= 6) {
            throw new \DomainException('Đang có 6 câu hỏi chưa chốt. Chốt bớt rồi tạo câu mới.');
        }
        $question = trim(strip_tags($question)) ?: (['date' => 'Mình đi ngày nào?', 'destination' => 'Mình đi đâu?', 'stay' => 'Ở đâu?', 'other' => 'Bạn chọn gì?'][$kind]);
        $clean = [];
        foreach ($options as $o) {
            if ($x = $this->option($kind, (string) ($o['label'] ?? ''), $o['value'] ?? null)) {
                $clean[$x['label'] . '|' . $x['value']] = $x;
            }
        }
        if (count($clean) > 8) {
            throw new \DomainException('Tối đa 8 lựa chọn.');
        }

        return DB::transaction(function () use ($trip, $uid, $kind, $question, $clean) {
            $id = (int) DB::table('tbl_trip_polls')->insertGetId(['tripId' => $trip->tripId, 'kind' => $kind, 'question' => mb_substr($question, 0, 160), 'createdBy' => $uid, 'createdAt' => now()]);
            foreach ($clean as $x) {
                DB::table('tbl_trip_poll_options')->insert(['pollId' => $id, 'label' => $x['label'], 'value' => $x['value'], 'createdBy' => $uid, 'createdAt' => now()]);
            }

            return $id;
        });
    }

    public function addOption(object $trip, int $uid, int $pollId, string $label, ?string $value): void
    {
        $p = $this->poll($trip, $pollId, true);
        $x = $this->option($p->kind, $label, $value) ?? throw new \DomainException($p->kind === 'date' ? 'Ngày không hợp lệ (phải từ hôm nay).' : 'Lựa chọn trống hoặc không hợp lệ.');
        if (DB::table('tbl_trip_poll_options')->where('pollId', $pollId)->count() >= 8) {
            throw new \DomainException('Câu hỏi đã đủ 8 lựa chọn.');
        }
        if (DB::table('tbl_trip_poll_options')->where(['pollId' => $pollId, 'label' => $x['label']])->exists()) {
            throw new \DomainException('Lựa chọn này đã có.');
        }
        DB::table('tbl_trip_poll_options')->insert(['pollId' => $pollId, 'label' => $x['label'], 'value' => $x['value'], 'createdBy' => $uid, 'createdAt' => now()]);
    }

    public function vote(object $trip, int $uid, int $pollId, ?int $optionId): void
    {
        $p = $this->poll($trip, $pollId, true);
        if ($optionId === null) {
            DB::table('tbl_trip_poll_votes')->where(['pollId' => $p->pollId, 'userId' => $uid])->delete();

            return;
        }
        DB::table('tbl_trip_poll_options')->where(['pollId' => $p->pollId, 'optionId' => $optionId])->exists() || throw new \DomainException('Lựa chọn không còn.');
        DB::table('tbl_trip_poll_votes')->updateOrInsert(['pollId' => $p->pollId, 'userId' => $uid], ['optionId' => $optionId, 'createdAt' => now()]);
    }

    /**
     * Chốt: chọn lựa chọn đưa vào (hoặc lựa chọn nhiều phiếu nhất; bằng phiếu thì phải chọn tay), áp dụng vào chuyến.
     * @return array{label:string,applied:string}
     */
    public function close(object $trip, int $uid, int $pollId, ?int $optionId): array
    {
        $p = $this->poll($trip, $pollId, true);
        if ($optionId === null) {
            $count = DB::table('tbl_trip_poll_votes')->where('pollId', $pollId)->select('optionId', DB::raw('COUNT(*) as n'))->groupBy('optionId')->orderByDesc('n')->get();
            if ($count->isEmpty()) {
                throw new \DomainException('Chưa ai bỏ phiếu. Chọn một lựa chọn để chốt.');
            }
            if ($count->count() > 1 && (int) $count[0]->n === (int) $count[1]->n) {
                throw new \DomainException('Đang bằng phiếu. Chọn lựa chọn để chốt.');
            }
            $optionId = (int) $count[0]->optionId;
        }
        $o = DB::table('tbl_trip_poll_options')->where(['pollId' => $pollId, 'optionId' => $optionId])->first() ?? throw new \DomainException('Lựa chọn không còn.');

        return DB::transaction(function () use ($trip, $uid, $p, $o) {
            $applied = '';
            $tripPatch = [];
            if ($p->kind === 'date' && $o->value) {
                $tripPatch['startDate'] = $o->value;
                $applied = 'Ngày khởi hành của chuyến đổi thành ' . date('d/m/Y', strtotime($o->value)) . '.';
            } elseif ($p->kind === 'destination' && $o->value) {
                $tripPatch['destinationId'] = (int) $o->value;
                $applied = 'Điểm đến của chuyến đổi thành ' . $o->label . '.';
            } elseif ($p->kind === 'stay' && Schema::hasTable('tbl_trip_external')) {
                $row = ['tripId' => $trip->tripId, 'kind' => 'hotel', 'provider' => 'manual', 'title' => mb_substr($o->label, 0, 190), 'payload' => '{}', 'createdAt' => now()];
                if (Schema::hasColumn('tbl_trip_external', 'status')) {
                    $row += ['status' => 'planned', 'bookUrl' => $o->value && preg_match('#^https?://#i', $o->value) ? $o->value : null, 'note' => 'Nhóm đã chọn qua bình chọn'];
                }
                DB::table('tbl_trip_external')->insert($row);
                $applied = 'Đã thêm “' . $o->label . '” vào Đặt chỗ (cần đặt).';
            }
            DB::table('tbl_trips')->where('tripId', $trip->tripId)->update($tripPatch + ['rev' => DB::raw('rev + 1'), 'lastEditorId' => $uid, 'updatedAt' => now()]);
            DB::table('tbl_trip_polls')->where('pollId', $p->pollId)->update(['status' => 'closed', 'chosenOptionId' => $o->optionId, 'closedAt' => now()]);

            return ['label' => (string) $o->label, 'applied' => $applied];
        });
    }

    public function delete(object $trip, int $pollId): void
    {
        $p = $this->poll($trip, $pollId, false);
        DB::table('tbl_trip_poll_votes')->where('pollId', $p->pollId)->delete();
        DB::table('tbl_trip_poll_options')->where('pollId', $p->pollId)->delete();
        DB::table('tbl_trip_polls')->where('pollId', $p->pollId)->delete();
    }

    private function poll(object $trip, int $pollId, bool $mustBeOpen): object
    {
        $p = DB::table('tbl_trip_polls')->where(['pollId' => $pollId, 'tripId' => $trip->tripId])->first() ?? throw new \DomainException('Không tìm thấy câu hỏi.');
        if ($mustBeOpen && $p->status !== 'open') {
            throw new \DomainException('Câu hỏi đã chốt.');
        }

        return $p;
    }

    /** Kiểm và chuẩn hóa một lựa chọn theo loại câu hỏi. @return array{label:string,value:?string}|null */
    private function option(string $kind, string $label, $value): ?array
    {
        $label = trim(strip_tags($label));
        $value = $value === null ? null : trim((string) $value);
        if ($kind === 'date') {
            $d = $value ?: $label;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || strtotime($d) === false || $d < now()->toDateString()) {
                return null;
            }

            return ['label' => date('d/m/Y', strtotime($d)) . ' (' . ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'][(int) date('w', strtotime($d))] . ')', 'value' => $d];
        }
        if ($kind === 'destination') {
            $id = (int) $value;
            $name = $id ? DB::table('tbl_destinations')->where('destinationId', $id)->where('isActive', 1)->value('name') : null;

            return $name ? ['label' => (string) $name, 'value' => (string) $id] : null;
        }
        if ($label === '') {
            return null;
        }
        $url = $kind === 'stay' && $value && preg_match('#^https?://#i', $value) ? mb_substr($value, 0, 255) : null;

        return ['label' => mb_substr($label, 0, 160), 'value' => $url];
    }
}
