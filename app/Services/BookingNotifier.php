<?php

namespace App\Services;

use App\Mail\TemplateMail;
use App\Models\admin\BookingModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/** Email báo khách khi đơn đổi trạng thái (qua hàng đợi; lỗi gửi không làm hỏng thao tác chính). */
class BookingNotifier
{
    public function send(int $bookingId, string $event): void
    {
        try {
            $b = (new BookingModel())->getInvoiceBooking($bookingId);
            if (!$b || !filter_var($b->email, FILTER_VALIDATE_EMAIL)) {
                return;
            }
            $subject = [
                'created' => 'Travela đã nhận đơn ' . $b->bookingCode,
                'confirmed' => 'Đơn ' . $b->bookingCode . ' đã được xác nhận',
                'paid' => 'Đã nhận thanh toán cho đơn ' . $b->bookingCode,
                'cancelled' => 'Đơn ' . $b->bookingCode . ' đã bị hủy',
            ][$event] ?? null;
            if (!$subject) {
                return;
            }
            $refund = Schema::hasTable('tbl_refunds') ? DB::table('tbl_refunds')->where('bookingId', $bookingId)->first() : null;
            Mail::to($b->email)->queue(new TemplateMail('admin.emails.booking-status', ['b' => $b, 'event' => $event, 'refund' => $refund], $subject));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
