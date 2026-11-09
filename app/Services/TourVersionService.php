<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phiên bản nội dung tour đã bán. Mỗi booking trỏ tới phiên bản khách đã đồng ý lúc đặt (tbl_booking.tourVersionId);
 * admin sửa giá / lịch trình / dịch vụ sau đó chỉ tạo phiên bản MỚI cho khách mới, đơn cũ vẫn xem đúng bản cũ.
 *
 * Phiên bản được định danh bằng mã băm nội dung (tourId + hash duy nhất): sửa rồi sửa lại y như cũ thì dùng lại bản cũ.
 * Không lưu ảnh / video (không ảnh hưởng quyền lợi đã cam kết). Đơn tạo trước khi có tính năng (tourVersionId NULL) được gắn vào
 * phiên bản tồn tại ngay TRƯỚC lần sửa tour đầu tiên (beforeEdit), vì đó là nội dung gần nhất khách có thể đã thấy.
 */
class TourVersionService
{
    /** Cột nội dung của tbl_tours được chụp (chỉ lấy cột thực sự có). */
    private const FIELDS = ['title', 'time', 'description', 'destination', 'domain', 'priceAdult', 'priceChild', 'durationDays', 'includes', 'excludes'];
    private const MONEY = ['priceAdult', 'priceChild'];
    private const LABELS = [
        'title' => 'Tên tour', 'time' => 'Thời lượng ghi trên tour', 'description' => 'Mô tả', 'destination' => 'Điểm đến', 'domain' => 'Khu vực',
        'priceAdult' => 'Giá người lớn', 'priceChild' => 'Giá trẻ em', 'durationDays' => 'Số ngày', 'includes' => 'Giá đã bao gồm', 'excludes' => 'Giá không bao gồm',
    ];

    public static function ready(): bool
    {
        return Schema::hasTable('tbl_tour_versions') && Schema::hasColumn('tbl_booking', 'tourVersionId');
    }

    /** Nội dung chuẩn hóa của một dòng tbl_tours + lịch trình hiện tại. */
    public function snapshotOf(object $tour): array
    {
        $snap = [];
        foreach (self::FIELDS as $f) {
            if (!property_exists($tour, $f)) {
                continue;
            }
            $v = $tour->{$f};
            $snap[$f] = in_array($f, self::MONEY, true) ? (int) round((float) $v) : ($f === 'durationDays' ? (int) $v : trim((string) $v));
        }
        $snap['timeline'] = DB::table('tbl_timeline')->where('tourId', $tour->tourId)->orderBy('timeLineId')->get(['title', 'description'])
            ->map(fn ($r) => ['title' => trim((string) $r->title), 'description' => trim((string) $r->description)])->all();

        return $snap;
    }

    public static function hash(array $snap): string
    {
        ksort($snap);

        return hash('sha256', json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Phiên bản ứng với nội dung HIỆN TẠI của tour (tạo nếu chưa có). Trả về versionId, null nếu tour không tồn tại. */
    public function ensureCurrent(int $tourId, ?int $adminId = null): ?int
    {
        return DB::transaction(function () use ($tourId, $adminId) {
            $tour = DB::table('tbl_tours')->where('tourId', $tourId)->lockForUpdate()->first();
            if (!$tour) {
                return null;
            }
            $snap = $this->snapshotOf($tour);
            $hash = self::hash($snap);
            $row = DB::table('tbl_tour_versions')->where('tourId', $tourId)->where('contentHash', $hash)->first();
            if ($row) {
                return (int) $row->versionId;
            }
            $next = (int) DB::table('tbl_tour_versions')->where('tourId', $tourId)->max('version') + 1;

            return (int) DB::table('tbl_tour_versions')->insertGetId([
                'tourId' => $tourId, 'version' => $next, 'contentHash' => $hash,
                'snapshot' => json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'createdBy' => $adminId, 'createdAt' => now(),
            ]);
        });
    }

    /**
     * Gọi TRƯỚC khi lưu thay đổi tour: chụp nội dung đang bán và gắn các đơn cũ chưa có phiên bản vào đó.
     * @return int|null versionId trước khi sửa
     */
    public function beforeEdit(int $tourId, ?int $adminId = null): ?int
    {
        if (!self::ready()) {
            return null;
        }
        $vid = $this->ensureCurrent($tourId, $adminId);
        if ($vid) {
            DB::table('tbl_booking')->where('tourId', $tourId)->whereNull('tourVersionId')->update(['tourVersionId' => $vid]);
        }

        return $vid;
    }

    /**
     * Gọi SAU khi lưu thay đổi tour.
     * @return array{changed:bool, version:?int, previous:?int, changes:string[], affected:int}
     *   affected = số đơn đang giữ chỗ / đã xác nhận vẫn dùng phiên bản cũ (không bị đổi nội dung)
     */
    public function afterEdit(int $tourId, ?int $beforeId, ?int $adminId = null): array
    {
        $none = ['changed' => false, 'version' => null, 'previous' => null, 'changes' => [], 'affected' => 0];
        if (!self::ready() || !$beforeId) {
            return $none;
        }
        $afterId = $this->ensureCurrent($tourId, $adminId);
        if (!$afterId || $afterId === $beforeId) {
            return $none + ['version' => $this->number($afterId)];
        }
        $old = $this->decode($beforeId);
        $new = $this->decode($afterId);
        $affected = (int) DB::table('tbl_booking')->where('tourId', $tourId)->where('tourVersionId', $beforeId)->whereIn('bookingStatus', ['n', 'y'])->count();

        return ['changed' => true, 'version' => $this->number($afterId), 'previous' => $this->number($beforeId),
            'changes' => $this->diff($old, $new), 'affected' => $affected];
    }

    /** Nhãn các phần khác nhau giữa hai bản chụp. @return string[] */
    public function diff(array $old, array $new): array
    {
        $out = [];
        foreach (self::LABELS as $f => $label) {
            if (($old[$f] ?? null) !== ($new[$f] ?? null)) {
                $out[] = $label;
            }
        }
        $ot = (array) ($old['timeline'] ?? []);
        $nt = (array) ($new['timeline'] ?? []);
        if (count($ot) !== count($nt)) {
            $out[] = 'Lịch trình (số ngày ' . count($ot) . ' → ' . count($nt) . ')';
        } else {
            foreach ($nt as $i => $day) {
                if (($ot[$i] ?? null) !== $day) {
                    $out[] = 'Lịch trình ngày ' . ($i + 1);
                }
            }
        }

        return $out;
    }

    private function decode(int $versionId): array
    {
        return (array) json_decode((string) DB::table('tbl_tour_versions')->where('versionId', $versionId)->value('snapshot'), true);
    }

    private function number(?int $versionId): ?int
    {
        return $versionId ? (int) DB::table('tbl_tour_versions')->where('versionId', $versionId)->value('version') : null;
    }

    /**
     * Nội dung tour theo đúng phiên bản của đơn, kèm so sánh với nội dung đang bán.
     * @return array{version:int, snapshot:array, outdated:bool, changes:string[]}|null  null = đơn chưa gắn phiên bản
     */
    public function forBooking(object $booking): ?array
    {
        if (!self::ready() || empty($booking->tourVersionId)) {
            return null;
        }
        $row = DB::table('tbl_tour_versions')->where('versionId', $booking->tourVersionId)->first();
        if (!$row) {
            return null;
        }
        $snap = (array) json_decode((string) $row->snapshot, true);
        $changes = [];
        $tour = DB::table('tbl_tours')->where('tourId', $booking->tourId)->first();
        if ($tour) {
            $now = $this->snapshotOf($tour);
            if (self::hash($now) !== $row->contentHash) {
                $changes = $this->diff($snap, $now);
            }
        }

        return ['version' => (int) $row->version, 'snapshot' => $snap, 'outdated' => $changes !== [], 'changes' => $changes];
    }
}
