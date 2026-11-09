<?php

namespace App\Console\Commands;

use App\Services\Payments\PaymentLedger;
use App\Services\ResourceAllocationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kiểm tra các bất biến dữ liệu quan trọng (CHỈ ĐỌC). Chạy trên bản sao production trước mỗi lần phát hành lớn:
 *   php artisan integrity:audit            in bảng kết quả, mã thoát 1 nếu có vi phạm
 *   php artisan integrity:audit --json     xuất JSON (để lưu vào báo cáo / CI)
 *
 *  1. seats.overbooked      bookedSlots + heldSlots > totalSlots trên một ngày khởi hành
 *  2. seats.counter_drift   số khách thực của các đơn (y, f) > totalSlots (bộ đếm lệch so với đơn)
 *  3. money.over_refund     tổng hoàn (hủy + điều chỉnh, kể cả đang chờ) > số đã thu
 *  4. resource.over_capacity hai phân bổ trùng giờ trên cùng tài nguyên vượt sức chứa
 *  5. trip.revision_ahead   lịch sử phiên bản có số lớn hơn rev hiện tại của chuyến (ghi lịch sử sai)
 */
class IntegrityAudit extends Command
{
    protected $signature = 'integrity:audit {--json : xuất JSON}';

    protected $description = 'Kiểm tra bất biến dữ liệu: bán quá chỗ, hoàn vượt số đã thu, trùng tài nguyên, lịch sử phiên bản';

    public function handle(PaymentLedger $ledger, ResourceAllocationService $resources): int
    {
        $out = [];

        if (Schema::hasTable('tbl_tour_availability')) {
            foreach (DB::table('tbl_tour_availability')->whereRaw('bookedSlots + heldSlots > totalSlots')->limit(200)
                ->get(['availabilityId', 'tourId', 'departureDate', 'totalSlots', 'bookedSlots', 'heldSlots']) as $a) {
                $out[] = ['check' => 'seats.overbooked', 'ref' => 'availability#' . $a->availabilityId,
                    'detail' => "Tour {$a->tourId} ngày {$a->departureDate}: đã bán {$a->bookedSlots} + giữ {$a->heldSlots} > {$a->totalSlots} chỗ"];
            }
            foreach (DB::table('tbl_booking as b')->join('tbl_tour_availability as a', 'a.availabilityId', '=', 'b.availabilityId')
                ->whereIn('b.bookingStatus', ['y', 'f'])->groupBy('a.availabilityId', 'a.totalSlots')
                ->havingRaw('SUM(b.numAdults + b.numChildren) > a.totalSlots')->limit(200)
                ->get(['a.availabilityId', 'a.totalSlots', DB::raw('SUM(b.numAdults + b.numChildren) AS sold')]) as $a) {
                $out[] = ['check' => 'seats.counter_drift', 'ref' => 'availability#' . $a->availabilityId,
                    'detail' => "Đơn đã xác nhận có {$a->sold} khách > {$a->totalSlots} chỗ"];
            }
        }

        foreach ($ledger->violations(200) as $v) {
            $out[] = ['check' => 'money.over_refund', 'ref' => 'booking#' . $v->bookingId,
                'detail' => "Đơn {$v->bookingCode}: hoàn hủy " . (int) $v->refundOut . ' + hoàn điều chỉnh ' . (int) $v->adjustOut . ' > đã thu ' . (int) $v->captured];
        }

        foreach ($resources->overlaps(200) as $o) {
            $out[] = ['check' => 'resource.over_capacity', 'ref' => 'resource#' . $o->resourceId,
                'detail' => "Phân bổ #{$o->a} và #{$o->b} trùng giờ, tổng {$o->total} > sức chứa {$o->capacity}"];
        }

        if (Schema::hasTable('tbl_trip_revisions')) {
            foreach (DB::table('tbl_trip_revisions as r')->join('tbl_trips as t', 't.tripId', '=', 'r.tripId')
                ->groupBy('r.tripId', 't.rev')->havingRaw('MAX(r.revisionNo) > t.rev')->limit(200)
                ->get(['r.tripId', 't.rev', DB::raw('MAX(r.revisionNo) AS maxRev')]) as $t) {
                $out[] = ['check' => 'trip.revision_ahead', 'ref' => 'trip#' . $t->tripId, 'detail' => "Lịch sử có bản {$t->maxRev} > rev hiện tại {$t->rev}"];
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode(['ok' => $out === [], 'findings' => $out], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } elseif ($out === []) {
            $this->info('Không phát hiện vi phạm bất biến nào.');
        } else {
            $this->table(['Kiểm tra', 'Đối tượng', 'Chi tiết'], array_map(fn($x) => [$x['check'], $x['ref'], $x['detail']], $out));
            $this->error(count($out) . ' vi phạm.');
        }

        return $out === [] ? self::SUCCESS : self::FAILURE;
    }
}
