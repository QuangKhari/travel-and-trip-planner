<?php

namespace App\Services;

use App\Mail\TemplateMail;
use App\Services\Planner\TripPlanner;
use App\Services\Planner\TripWatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * Chuyến tự lên -> yêu cầu tour riêng: khách gửi lịch đã lên, nhân viên báo giá trọn gói cho nhóm (giá / người, bao gồm, không bao gồm, hạn báo giá).
 * Nối Trip Planner với nghiệp vụ tour mà KHÔNG trộn lịch tour vào lịch tự đi: lịch là bản chụp lúc gửi.
 */
final class TourQuotes
{
    public static function ready(): bool
    {
        return Schema::hasTable('tbl_tour_quotes');
    }

    public function snapshot(object $trip): array
    {
        $p = app(TripPlanner::class)->payload($trip);

        return ['title' => $trip->title, 'startDate' => $trip->startDate, 'days' => (int) $trip->days, 'groupSize' => (int) $trip->groupSize,
            'budget' => $trip->budget !== null ? (float) $trip->budget : null,
            'plan' => array_map(fn($d) => ['day' => $d['day'], 'date' => $d['date'], 'items' => array_map(fn($i) => ['name' => $i['name'], 'type' => $i['type'],
                'start' => $i['startMin'] ?? null], $d['items'])], $p['days'])];
    }

    public function request(object $trip, int $userId, ?string $note): int
    {
        $open = DB::table('tbl_tour_quotes')->where('tripId', $trip->tripId)->where('userId', $userId)->whereIn('status', ['open', 'quoted'])->value('quoteId');
        if ($open) {
            throw new \DomainException('Chuyến này đang có một yêu cầu báo giá chưa xong (#' . $open . ').');
        }
        $snap = $this->snapshot($trip);
        if (!array_sum(array_map(fn($d) => count($d['items']), $snap['plan']))) {
            throw new \DomainException('Lịch còn trống. Thêm vài điểm vào lịch rồi gửi yêu cầu để Travela báo giá sát hơn.');
        }
        $id = (int) DB::table('tbl_tour_quotes')->insertGetId(['tripId' => $trip->tripId, 'userId' => $userId, 'groupSize' => max(1, (int) $trip->groupSize),
            'budget' => $trip->budget, 'note' => $note, 'snapshot' => json_encode($snap, JSON_UNESCAPED_UNICODE), 'status' => 'open', 'createdAt' => now()], 'quoteId');
        TripWatch::log((int) $trip->tripId, 'quote', 'info', 'Đã gửi yêu cầu báo giá tour riêng #' . $id, 'Nhân viên Travela sẽ báo giá trọn gói cho nhóm ' . (int) $trip->groupSize . ' người.');

        return $id;
    }

    /** Nhân viên báo giá. */
    public function quote(int $id, array $v, int $adminId): void
    {
        $q = DB::table('tbl_tour_quotes')->where('quoteId', $id)->whereIn('status', ['open', 'quoted'])->first() ?? throw new \DomainException('Yêu cầu không còn mở.');
        $total = (float) $v['pricePerPerson'] * (int) $q->groupSize;
        DB::table('tbl_tour_quotes')->where('quoteId', $id)->update(['status' => 'quoted', 'pricePerPerson' => $v['pricePerPerson'], 'totalPrice' => $total,
            'includes' => $v['includes'] ?? null, 'excludes' => $v['excludes'] ?? null, 'message' => $v['message'] ?? null, 'validUntil' => $v['validUntil'],
            'quotedBy' => $adminId, 'quotedAt' => now()]);
        $money = number_format((float) $v['pricePerPerson'], 0, ',', '.') . 'đ/người';
        TripWatch::log((int) $q->tripId, 'quote', 'info', 'Travela đã báo giá tour riêng: ' . $money, 'Tổng ' . number_format($total, 0, ',', '.') . 'đ cho ' . $q->groupSize . ' người, giữ giá tới ' . date('d/m/Y', strtotime($v['validUntil'])) . '.');
        $u = DB::table('tbl_users')->where('userId', $q->userId)->first(['email', 'fullName']);
        if ($u && $u->email) {
            Mail::to($u->email)->queue(new TemplateMail('admin.emails.tour-quote', ['name' => $u->fullName, 'q' => (array) $q + ['pricePerPerson' => $v['pricePerPerson'], 'totalPrice' => $total,
                'includes' => $v['includes'] ?? null, 'validUntil' => $v['validUntil'], 'message' => $v['message'] ?? null], 'url' => route('quotes.show', ['id' => $id])], 'Travela báo giá tour riêng #' . $id));
        }
    }

    public function decide(int $id, int $userId, bool $accept): void
    {
        $q = DB::table('tbl_tour_quotes')->where('quoteId', $id)->where('userId', $userId)->where('status', 'quoted')->first() ?? throw new \DomainException('Báo giá không còn chờ bạn quyết định.');
        if ($accept && $q->validUntil && $q->validUntil < now()->toDateString()) {
            throw new \DomainException('Báo giá đã hết hạn ngày ' . date('d/m/Y', strtotime($q->validUntil)) . '. Hãy gửi lại yêu cầu để Travela báo giá mới.');
        }
        DB::table('tbl_tour_quotes')->where('quoteId', $id)->update(['status' => $accept ? 'accepted' : 'declined', 'decidedAt' => now()]);
        TripWatch::log((int) $q->tripId, 'quote', $accept ? 'info' : 'warn', $accept ? 'Bạn đã nhận báo giá tour riêng #' . $id : 'Bạn đã từ chối báo giá #' . $id,
            $accept ? 'Nhân viên Travela sẽ liên hệ để chốt dịch vụ và hướng dẫn thanh toán.' : null);
    }
}
