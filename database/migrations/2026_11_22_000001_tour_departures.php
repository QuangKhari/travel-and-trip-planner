<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lịch khởi hành nhiều ngày cho một tour (đặc tả §6.3, A3 / D-02).
 *   tbl_tour_availability  mỗi dòng = một ngày khởi hành: tổng chỗ, chỗ đang giữ (đơn chờ), chỗ đã bán (đơn xác nhận / hoàn tất).
 *                          NGUỒN SỰ THẬT về số chỗ. CHECK chặn số âm và bán vượt tổng chỗ ngay ở CSDL.
 *   tbl_booking.availabilityId / departureDate   đơn thuộc ngày khởi hành nào (ngày lưu kèm để đọc nhanh, không đổi về sau).
 * tbl_tours.startDate / endDate / quantity vẫn giữ, nhưng chỉ còn là BẢN TÓM TẮT "chuyến gần nhất còn bán"
 * do DepartureService::syncTour() ghi lại, để các trang và truy vấn cũ (lọc, trang chủ, planner) chạy không phải sửa.
 *
 * Chuyển dữ liệu cũ: mỗi tour tạo 1 ngày khởi hành = startDate. quantity cũ là số chỗ CÒN LẠI nên
 * totalSlots = quantity + số khách của đơn chưa hủy. Đơn cũ được gắn availabilityId. Chạy lại an toàn.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_tour_availability')) {
            Schema::create('tbl_tour_availability', function (Blueprint $t) {
                $t->increments('availabilityId');
                $t->integer('tourId');
                $t->date('departureDate');
                $t->unsignedInteger('totalSlots');
                $t->unsignedInteger('bookedSlots')->default(0);
                $t->unsignedInteger('heldSlots')->default(0);
                $t->enum('status', ['open', 'closed'])->default('open');
                $t->string('note', 255)->nullable();
                $t->timestamp('createdAt')->nullable()->useCurrent();
                $t->timestamp('updatedAt')->nullable();
                $t->unique(['tourId', 'departureDate'], 'uniq_avail_tour_date');
                $t->index(['departureDate', 'status'], 'idx_avail_date_status');
                $t->foreign('tourId', 'fk_avail_tour')->references('tourId')->on('tbl_tours')->cascadeOnDelete();
            });
            try {
                DB::statement('ALTER TABLE tbl_tour_availability ADD CONSTRAINT chk_avail_slots CHECK (bookedSlots + heldSlots <= totalSlots)');
            } catch (\Throwable $e) {
                // CSDL không hỗ trợ CHECK (SQLite cũ): vẫn có lớp kiểm tra trong câu UPDATE có điều kiện
            }
        }

        if (Schema::hasTable('tbl_booking')) {
            Schema::table('tbl_booking', function (Blueprint $t) {
                if (!Schema::hasColumn('tbl_booking', 'availabilityId')) {
                    $t->unsignedInteger('availabilityId')->nullable()->after('tourId');
                    $t->index('availabilityId', 'idx_booking_avail');
                }
                if (!Schema::hasColumn('tbl_booking', 'departureDate')) {
                    $t->date('departureDate')->nullable()->after('availabilityId');
                }
            });
        }

        $this->backfill();
    }

    private function backfill(): void
    {
        $tours = DB::table('tbl_tours')->get(['tourId', 'startDate', 'quantity', 'availability']);
        foreach ($tours as $tour) {
            if (!$tour->startDate || DB::table('tbl_tour_availability')->where('tourId', $tour->tourId)->exists()) {
                continue;
            }
            $sum = fn(array $st) => (int) DB::table('tbl_booking')->where('tourId', $tour->tourId)
                ->whereIn('bookingStatus', $st)->sum(DB::raw('numAdults + numChildren'));
            $held = $sum(['n']);
            $booked = $sum(['y', 'f']);
            $id = DB::table('tbl_tour_availability')->insertGetId([
                'tourId' => $tour->tourId,
                'departureDate' => $tour->startDate,
                'totalSlots' => max(0, (int) $tour->quantity) + $held + $booked,
                'heldSlots' => $held,
                'bookedSlots' => $booked,
                'status' => 'open',
                'createdAt' => now(),
            ]);
            DB::table('tbl_booking')->where('tourId', $tour->tourId)->whereNull('availabilityId')
                ->update(['availabilityId' => $id, 'departureDate' => $tour->startDate]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tbl_booking')) {
            Schema::table('tbl_booking', function (Blueprint $t) {
                if (Schema::hasColumn('tbl_booking', 'availabilityId')) {
                    $t->dropIndex('idx_booking_avail');
                    $t->dropColumn('availabilityId');
                }
                if (Schema::hasColumn('tbl_booking', 'departureDate')) {
                    $t->dropColumn('departureDate');
                }
            });
        }
        Schema::dropIfExists('tbl_tour_availability');
    }
};
