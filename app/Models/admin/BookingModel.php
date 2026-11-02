<?php

namespace App\Models\admin;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\BookingService;
use App\Support\BookingPii;

class BookingModel extends Model
{
    use HasFactory;

    protected $table = 'tbl_booking';

    private function base()
    {
        $q = DB::table('tbl_booking')
            ->join('tbl_tours', 'tbl_tours.tourId', '=', 'tbl_booking.tourId')
            ->join('tbl_checkout', 'tbl_booking.bookingId', '=', 'tbl_checkout.bookingId')
            ->select(
                'tbl_booking.*',
                'tbl_tours.title', 'tbl_tours.time', 'tbl_tours.destination', 'tbl_tours.priceAdult', 'tbl_tours.priceChild',
                'tbl_tours.startDate', 'tbl_tours.endDate', 'tbl_tours.durationDays', 'tbl_tours.domain',
                'tbl_checkout.checkoutId', 'tbl_checkout.paymentMethod', 'tbl_checkout.paymentDate', 'tbl_checkout.amount',
                'tbl_checkout.paymentStatus', 'tbl_checkout.transactionId'
            );
        if (Schema::hasColumn('tbl_checkout', 'providerTxnRef')) {
            $q->addSelect('tbl_checkout.providerTxnRef', 'tbl_checkout.providerTxnNo', 'tbl_checkout.bankCode', 'tbl_checkout.responseCode', 'tbl_checkout.paidAt', 'tbl_checkout.attempts');
        }
        if (Schema::hasTable('tbl_refunds')) {
            $q->leftJoin('tbl_refunds', 'tbl_refunds.bookingId', '=', 'tbl_booking.bookingId')
                ->addSelect('tbl_refunds.refundId', 'tbl_refunds.amount as refundAmount', 'tbl_refunds.percent as refundPercent', 'tbl_refunds.status as refundStatus');
        }
        if (Schema::hasTable('tbl_promotion_usage')) {
            $q->leftJoin('tbl_promotion_usage', 'tbl_promotion_usage.bookingId', '=', 'tbl_booking.bookingId')
                ->leftJoin('tbl_promotion', 'tbl_promotion.promotionId', '=', 'tbl_promotion_usage.promotionId')
                ->addSelect('tbl_promotion_usage.discountAmount', 'tbl_promotion.code as couponCode');
        }

        return $q;
    }

    /** Ngày đi / ngày về thật của đơn (theo ngày khởi hành đã đặt) */
    private function withTrip($row)
    {
        if ($row) {
            $row->tripStart = BookingService::tripStartDate($row);
            $row->tripEnd = BookingService::tripEndDate($row);
        }

        return $row;
    }

    public function getBooking()
    {
        $rows = BookingPii::decryptAll($this->base()->orderByDesc('tbl_booking.bookingDate')->orderByDesc('tbl_booking.bookingId')->get());

        return collect($rows)->map(fn($r) => $this->withTrip($r));
    }

    public function updateBooking($bookingId, $data)
    {
        return DB::table($this->table)
            ->where('bookingId', $bookingId)
            ->update($data);
    }

    public function getInvoiceBooking($bookingId)
    {
        $invoice = $this->base()->where('tbl_booking.bookingId', (int) $bookingId)->first();

        return $this->withTrip(BookingPii::decrypt($invoice));
    }

    public function updateCheckout($bookingId, $data)
    {
        return DB::table('tbl_checkout')
            ->where('bookingId', $bookingId)
            ->update($data);
    }
}
