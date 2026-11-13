<?php

namespace App\Services\Payments;

use App\Support\AdminBadges;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Đối soát booking – thanh toán – hoàn tiền. Quét các trường hợp tiền và đơn không khớp nhau, lưu thành "phát hiện" (tbl_reconciliation_findings)
 * để admin xử lý hoặc bỏ qua có ghi chú. Phát hiện tự đóng khi điều kiện không còn đúng. Chỉ ĐỌC dữ liệu đơn / tiền, không tự sửa.
 * Chạy: php artisan payments:audit (mỗi giờ) hoặc nút "Quét ngay" ở trang Đối soát.
 */
class PaymentReconciliationService
{
    /** type => [nhãn, mức nghiêm trọng mặc định, gợi ý xử lý] */
    public const TYPES = [
        'paid_cancelled_no_refund' => ['Đơn đã hủy, đã thu tiền nhưng chưa có yêu cầu hoàn', 'high', 'Tạo yêu cầu hoàn tiền hoặc ghi nhận lý do giữ lại.'],
        'refund_status_mismatch' => ['Trạng thái hoàn tiền và trạng thái thanh toán không khớp', 'high', 'Mở đơn, đối chiếu rồi sửa trạng thái cho khớp.'],
        'refund_amount_exceeds_paid' => ['Số tiền hoàn lớn hơn số tiền đã thu', 'high', 'Không chuyển tiền; kiểm tra lại hồ sơ hoàn.'],
        'total_refund_exceeds_captured' => ['Tổng hoàn (hủy đơn + điều chỉnh số khách) lớn hơn số đã thu', 'high', 'Dừng chi các khoản đang chờ; đối chiếu hai sổ hoàn của đơn.'],
        'duplicate_txn' => ['Một mã giao dịch của cổng gắn vào nhiều đơn', 'high', 'Kiểm tra từng đơn: chỉ một đơn được ghi nhận khoản thu đó.'],
        'duplicate_refund_reference' => ['Một mã giao dịch hoàn dùng cho nhiều khoản hoàn', 'high', 'Có nguy cơ ghi nhận / hoàn trùng; đối chiếu sao kê.'],
        'checkout_refund_without_row' => ['Thanh toán ở trạng thái hoàn tiền nhưng không có hồ sơ hoàn', 'medium', 'Tạo lại hồ sơ hoàn hoặc trả trạng thái thanh toán về đúng.'],
        'refund_paid_mismatch' => ['Số đã thu ghi trong hồ sơ hoàn khác số đã thu của đơn', 'medium', 'Đối chiếu số tiền thực nhận trước khi hoàn.'],
        'paid_amount_mismatch' => ['Số tiền đã thu khác tổng tiền của đơn', 'medium', 'Đối chiếu với cổng / sao kê: thu thiếu hay thừa.'],
        'online_confirmed_unpaid' => ['Đơn trả online đã xác nhận nhưng chưa ghi nhận thanh toán', 'medium', 'Bấm "Kiểm tra lại" ở trang Thanh toán trực tuyến.'],
        'payment_log_bad_amount' => ['Cổng báo thanh toán sai số tiền, đơn vẫn chưa thu', 'medium', 'Liên hệ khách và đối chiếu với cổng.'],
        'refund_overdue' => ['Yêu cầu hoàn tiền chờ quá lâu', 'medium', 'Hoàn tiền hoặc ghi lý do chậm.'],
        'gateway_refund_stuck' => ['Lệnh hoàn qua cổng xử lý quá lâu', 'medium', 'Bấm "Kiểm tra trạng thái" ở trang Hoàn tiền.'],
        'office_unpaid_near_departure' => ['Đơn trả tại văn phòng, sắp khởi hành mà chưa thu tiền', 'low', 'Nhắc khách hoặc thu tiền trước ngày đi.'],
        'adjustment_collect_overdue' => ['Khoản thu thêm do tăng số khách chưa thu, sắp khởi hành', 'medium', 'Thu thêm trước ngày đi rồi ghi nhận ở trang Điều chỉnh số khách.'],
        'adjustment_refund_overdue' => ['Hoàn phần dư do giảm số khách chờ quá lâu', 'medium', 'Hoàn tiền rồi ghi nhận ở trang Điều chỉnh số khách.'],
        'complaint_compensation_overdue' => ['Bồi hoàn khiếu nại chờ chi trả quá lâu', 'medium', 'Chi trả rồi ghi nhận ở trang Khiếu nại.'],
    ];

    public static function ready(): bool
    {
        return Schema::hasTable('tbl_reconciliation_findings');
    }

    /** @return array<int, array{type:string, severity:string, bookingId:?int, refundId:?int, detail:string}> */
    public function scan(): array
    {
        $out = [];
        $add = function (string $type, ?int $bookingId, ?int $refundId, string $detail, ?string $severity = null) use (&$out) {
            $out[] = ['type' => $type, 'severity' => $severity ?? self::TYPES[$type][1], 'bookingId' => $bookingId, 'refundId' => $refundId, 'detail' => $detail];
        };
        $money = fn ($v) => number_format((float) $v, 0, ',', '.') . ' đ';
        $base = fn () => DB::table('tbl_booking as b')->join('tbl_checkout as c', 'c.bookingId', '=', 'b.bookingId');
        $hasRefunds = Schema::hasTable('tbl_refunds');

        // 1. Hủy, đã thu, chưa có hồ sơ hoàn
        if ($hasRefunds) {
            foreach ($base()->leftJoin('tbl_refunds as r', 'r.bookingId', '=', 'b.bookingId')->where('b.bookingStatus', 'c')->where('c.paymentStatus', 'y')
                ->whereNull('r.refundId')->limit(500)->get(['b.bookingId', 'b.bookingCode', 'c.amount']) as $x) {
                $add('paid_cancelled_no_refund', (int) $x->bookingId, null, "Đơn {$x->bookingCode} đã hủy, đã thu " . $money($x->amount) . ' nhưng không có yêu cầu hoàn.');
            }

            // 2. Trạng thái hoàn ≠ trạng thái thanh toán
            foreach ($base()->join('tbl_refunds as r', 'r.bookingId', '=', 'b.bookingId')->where(function ($q) {
                $q->where(fn ($w) => $w->where('r.status', 'refunded')->where('c.paymentStatus', '!=', 'refunded'))
                    ->orWhere(fn ($w) => $w->where('r.status', 'pending')->where('c.paymentStatus', '!=', 'refund_pending'));
            })->limit(500)->get(['b.bookingId', 'b.bookingCode', 'r.refundId', 'r.status as rs', 'c.paymentStatus as ps']) as $x) {
                $add('refund_status_mismatch', (int) $x->bookingId, (int) $x->refundId, "Đơn {$x->bookingCode}: hồ sơ hoàn '{$x->rs}' nhưng thanh toán '{$x->ps}'.");
            }

            // 3. Thanh toán ở trạng thái hoàn nhưng không có hồ sơ
            foreach ($base()->leftJoin('tbl_refunds as r', 'r.bookingId', '=', 'b.bookingId')->whereIn('c.paymentStatus', ['refund_pending', 'refunded'])
                ->whereNull('r.refundId')->limit(500)->get(['b.bookingId', 'b.bookingCode', 'c.paymentStatus as ps']) as $x) {
                $add('checkout_refund_without_row', (int) $x->bookingId, null, "Đơn {$x->bookingCode}: thanh toán '{$x->ps}' nhưng không có hồ sơ hoàn tiền.");
            }

            // 4. Số tiền hoàn
            foreach ($base()->join('tbl_refunds as r', 'r.bookingId', '=', 'b.bookingId')->where(function ($q) {
                $q->whereColumn('r.amount', '>', 'c.amount')->orWhereRaw('ABS(r.paidAmount - c.amount) >= 1');
            })->limit(500)->get(['b.bookingId', 'b.bookingCode', 'r.refundId', 'r.amount as ra', 'r.paidAmount as rp', 'c.amount as ca']) as $x) {
                if ((float) $x->ra > (float) $x->ca) {
                    $add('refund_amount_exceeds_paid', (int) $x->bookingId, (int) $x->refundId, "Đơn {$x->bookingCode}: hoàn " . $money($x->ra) . ' > đã thu ' . $money($x->ca) . '.');
                } else {
                    $add('refund_paid_mismatch', (int) $x->bookingId, (int) $x->refundId, "Đơn {$x->bookingCode}: hồ sơ hoàn ghi đã thu " . $money($x->rp) . ', đơn ghi ' . $money($x->ca) . '.');
                }
            }

            // 4b. Tổng hoàn của cả hai sổ (tbl_refunds + tbl_booking_adjustments) vượt số đã thu
            foreach (app(PaymentLedger::class)->violations() as $x) {
                $add('total_refund_exceeds_captured', (int) $x->bookingId, null, "Đơn {$x->bookingCode}: hoàn khi hủy " . $money($x->refundOut)
                    . ' + hoàn điều chỉnh ' . $money($x->adjustOut) . ' > đã thu ' . $money($x->captured) . '.');
            }

            // 5. Hoàn quá hạn
            $days = max(1, (int) config('payments.audit.refund_overdue_days', 3));
            foreach (DB::table('tbl_refunds as r')->join('tbl_booking as b', 'b.bookingId', '=', 'r.bookingId')->where('r.status', 'pending')
                ->where('r.createdAt', '<', now()->subDays($days))->limit(500)->get(['b.bookingId', 'b.bookingCode', 'r.refundId', 'r.amount', 'r.createdAt']) as $x) {
                $add('refund_overdue', (int) $x->bookingId, (int) $x->refundId, "Đơn {$x->bookingCode}: hoàn " . $money($x->amount) . " chờ từ {$x->createdAt} (quá {$days} ngày).");
            }

            // 6. Lệnh hoàn qua cổng kẹt
            if (Schema::hasColumn('tbl_refunds', 'gatewayStatus')) {
                $mins = max(10, (int) config('payments.audit.gateway_stuck_minutes', 60));
                foreach (DB::table('tbl_refunds as r')->join('tbl_booking as b', 'b.bookingId', '=', 'r.bookingId')->where('r.status', 'pending')->where('r.gatewayStatus', 'processing')
                    ->where('r.gatewayAt', '<', now()->subMinutes($mins))->limit(200)->get(['b.bookingId', 'b.bookingCode', 'r.refundId', 'r.gatewayAt']) as $x) {
                    $add('gateway_refund_stuck', (int) $x->bookingId, (int) $x->refundId, "Đơn {$x->bookingCode}: lệnh hoàn qua cổng gửi lúc {$x->gatewayAt} vẫn chưa có kết quả.");
                }
            }

            // 12. Mã giao dịch hoàn dùng lặp
            foreach (DB::table('tbl_refunds')->where('status', 'refunded')->whereNotNull('reference')->where('reference', '!=', '')
                ->select('method', 'reference')->groupBy('method', 'reference')->havingRaw('COUNT(*) > 1')->limit(100)->get() as $g) {
                foreach (DB::table('tbl_refunds')->where('status', 'refunded')->where('method', $g->method)->where('reference', $g->reference)->get(['refundId', 'bookingId']) as $r) {
                    $add('duplicate_refund_reference', (int) $r->bookingId, (int) $r->refundId, "Mã hoàn '{$g->reference}' ({$g->method}) được dùng cho nhiều khoản hoàn.");
                }
            }
        }

        // 7. Online đã xác nhận nhưng chưa thu
        foreach ($base()->where('b.bookingStatus', 'y')->where('c.paymentStatus', 'n')->whereIn('c.provider', ['vnpay', 'momo', 'qr'])->limit(500)
            ->get(['b.bookingId', 'b.bookingCode', 'c.provider']) as $x) {
            $add('online_confirmed_unpaid', (int) $x->bookingId, null, "Đơn {$x->bookingCode} đã xác nhận nhưng chưa ghi nhận khoản thu qua {$x->provider}.");
        }

        // 8. Trả tại văn phòng, sắp đi mà chưa thu
        foreach ($base()->where('b.bookingStatus', 'y')->where('c.paymentStatus', 'n')->whereNull('c.provider')->whereNotNull('b.departureDate')
            ->whereBetween('b.departureDate', [today()->toDateString(), today()->addDays(3)->toDateString()])->limit(500)->get(['b.bookingId', 'b.bookingCode', 'b.departureDate']) as $x) {
            $add('office_unpaid_near_departure', (int) $x->bookingId, null, "Đơn {$x->bookingCode} khởi hành {$x->departureDate} mà chưa thu tiền.");
        }

        // 9. Số đã thu ≠ tổng tiền đơn
        $mismatch = $base()->whereIn('b.bookingStatus', ['y', 'f'])->where('c.paymentStatus', 'y')->whereRaw('ABS(c.amount - b.totalPrice) >= 1');
        if (Schema::hasTable('tbl_booking_adjustments')) {   // đơn đã điều chỉnh số khách: chênh lệch có chủ ý, theo dõi ở sổ điều chỉnh
            $mismatch->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('tbl_booking_adjustments as j')->whereColumn('j.bookingId', 'b.bookingId'));
        }
        foreach ($mismatch->limit(500)->get(['b.bookingId', 'b.bookingCode', 'c.amount', 'b.totalPrice']) as $x) {
            $add('paid_amount_mismatch', (int) $x->bookingId, null, "Đơn {$x->bookingCode}: đã thu " . $money($x->amount) . ', tổng tiền đơn ' . $money($x->totalPrice) . '.');
        }

        // 13. Điều chỉnh số khách / bồi hoàn khiếu nại: khoản chờ xử lý quá lâu
        if (Schema::hasTable('tbl_booking_adjustments')) {
            foreach (DB::table('tbl_booking_adjustments as j')->join('tbl_booking as b', 'b.bookingId', '=', 'j.bookingId')->where('j.moneyStatus', 'pending_collect')->whereNotNull('b.departureDate')
                ->where('b.departureDate', '<=', today()->addDays(3)->toDateString())->limit(300)->get(['b.bookingId', 'b.bookingCode', 'j.settleAmount', 'b.departureDate']) as $x) {
                $add('adjustment_collect_overdue', (int) $x->bookingId, null, "Đơn {$x->bookingCode}: cần thu thêm " . $money($x->settleAmount) . " trước ngày đi {$x->departureDate}.");
            }
            $days = max(1, (int) config('payments.audit.refund_overdue_days', 3));
            foreach (DB::table('tbl_booking_adjustments as j')->join('tbl_booking as b', 'b.bookingId', '=', 'j.bookingId')->where('j.moneyStatus', 'pending_refund')
                ->where('j.createdAt', '<', now()->subDays($days))->limit(300)->get(['b.bookingId', 'b.bookingCode', 'j.settleAmount', 'j.createdAt']) as $x) {
                $add('adjustment_refund_overdue', (int) $x->bookingId, null, "Đơn {$x->bookingCode}: hoàn phần dư " . $money($x->settleAmount) . " chờ từ {$x->createdAt}.");
            }
        }
        if (Schema::hasTable('tbl_complaints')) {
            $days = max(1, (int) config('payments.audit.refund_overdue_days', 3));
            foreach (DB::table('tbl_complaints as c')->join('tbl_booking as b', 'b.bookingId', '=', 'c.bookingId')->where('c.compensationStatus', 'pending')
                ->where('c.updatedAt', '<', now()->subDays($days))->limit(300)->get(['b.bookingId', 'b.bookingCode', 'c.code', 'c.compensationAmount']) as $x) {
                $add('complaint_compensation_overdue', (int) $x->bookingId, null, "Khiếu nại {$x->code} (đơn {$x->bookingCode}): bồi hoàn " . $money($x->compensationAmount) . ' chờ chi trả.');
            }
        }

        // 10. Một mã giao dịch của cổng cho nhiều đơn
        foreach (DB::table('tbl_checkout')->whereNotNull('providerTxnNo')->where('providerTxnNo', '!=', '')->select('provider', 'providerTxnNo')
            ->groupBy('provider', 'providerTxnNo')->havingRaw('COUNT(*) > 1')->limit(100)->get() as $g) {
            foreach (DB::table('tbl_checkout as c')->join('tbl_booking as b', 'b.bookingId', '=', 'c.bookingId')->where('c.provider', $g->provider)->where('c.providerTxnNo', $g->providerTxnNo)->get(['b.bookingId', 'b.bookingCode']) as $x) {
                $add('duplicate_txn', (int) $x->bookingId, null, "Mã giao dịch {$g->provider} {$g->providerTxnNo} gắn với nhiều đơn (có {$x->bookingCode}).");
            }
        }

        // 11. Cổng báo sai số tiền, đơn vẫn chưa thu
        if (Schema::hasTable('tbl_payment_logs')) {
            foreach (DB::table('tbl_payment_logs as l')->join('tbl_checkout as c', 'c.bookingId', '=', 'l.bookingId')->join('tbl_booking as b', 'b.bookingId', '=', 'l.bookingId')
                ->where('l.result', 'bad_amount')->where('l.createdAt', '>=', now()->subDays(14))->where('c.paymentStatus', 'n')->groupBy('b.bookingId', 'b.bookingCode')
                ->limit(200)->get(['b.bookingId', 'b.bookingCode']) as $x) {
                $add('payment_log_bad_amount', (int) $x->bookingId, null, "Đơn {$x->bookingCode}: cổng báo thanh toán sai số tiền trong 14 ngày qua, đơn vẫn chưa thu.");
            }
        }

        return $out;
    }

    /** Quét và đồng bộ vào bảng. @return array{found:int, new:int, resolved:int, open:int} */
    public function sync(): array
    {
        $found = $this->scan();
        $seen = [];
        $new = 0;
        foreach ($found as $x) {
            $fp = sha1($x['type'] . '|' . ($x['bookingId'] ?? 0) . '|' . ($x['refundId'] ?? 0));
            if (isset($seen[$fp])) {
                continue;
            }
            $seen[$fp] = true;
            $row = DB::table('tbl_reconciliation_findings')->where('fingerprint', $fp)->first();
            if (!$row) {
                DB::table('tbl_reconciliation_findings')->insert(['fingerprint' => $fp, 'type' => $x['type'], 'severity' => $x['severity'], 'bookingId' => $x['bookingId'],
                    'refundId' => $x['refundId'], 'detail' => $x['detail'], 'status' => 'open', 'firstSeenAt' => now(), 'lastSeenAt' => now()]);
                $new++;
                continue;
            }
            $upd = ['lastSeenAt' => now(), 'detail' => $x['detail'], 'severity' => $x['severity']];
            if ($row->status === 'resolved') {   // đã tự đóng nhưng lỗi quay lại
                $upd += ['status' => 'open', 'resolvedAt' => null, 'note' => null];
                $new++;
            }
            DB::table('tbl_reconciliation_findings')->where('findingId', $row->findingId)->update($upd);
        }
        $gone = [];
        foreach (DB::table('tbl_reconciliation_findings')->where('status', 'open')->pluck('fingerprint', 'findingId') as $id => $fp) {
            if (!isset($seen[$fp])) {
                $gone[] = $id;
            }
        }
        foreach (array_chunk($gone, 500) as $chunk) {
            DB::table('tbl_reconciliation_findings')->whereIn('findingId', $chunk)->update(['status' => 'resolved', 'resolvedAt' => now(), 'note' => 'Tự đóng: không còn phát hiện.']);
        }
        AdminBadges::forget();

        return ['found' => count($seen), 'new' => $new, 'resolved' => count($gone), 'open' => (int) DB::table('tbl_reconciliation_findings')->where('status', 'open')->count()];
    }

    public function ignore(int $findingId, int $adminId, string $note): bool
    {
        $n = DB::table('tbl_reconciliation_findings')->where('findingId', $findingId)->whereIn('status', ['open', 'resolved'])
            ->update(['status' => 'ignored', 'note' => mb_substr($note, 0, 255), 'resolvedBy' => $adminId, 'resolvedAt' => now()]);
        AdminBadges::forget();

        return $n > 0;
    }

    public function reopen(int $findingId): bool
    {
        $n = DB::table('tbl_reconciliation_findings')->where('findingId', $findingId)->where('status', 'ignored')
            ->update(['status' => 'open', 'resolvedAt' => null, 'resolvedBy' => null]);
        AdminBadges::forget();

        return $n > 0;
    }
}
