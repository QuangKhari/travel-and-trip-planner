<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lịch sử trạng thái đơn (máy trạng thái tường minh, App\Support\BookingState): mỗi lần đổi trạng thái là một dòng,
 * ghi cùng transaction với cập nhật có điều kiện (đổi không thành thì không có dòng lịch sử).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tbl_booking_status_history')) {
            Schema::create('tbl_booking_status_history', function (Blueprint $t) {
                $t->bigIncrements('historyId');
                $t->integer('bookingId');
                $t->char('fromStatus', 1);
                $t->char('toStatus', 1);
                $t->string('reason', 40)->nullable();   // confirm | customer | admin | expired | shortfall | auto_finish | no_show | late_payment ...
                $t->string('actor', 40)->nullable();    // system | customer | admin:{id}
                $t->timestamp('createdAt')->useCurrent();
                $t->index(['bookingId', 'historyId']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_booking_status_history');
    }
};
