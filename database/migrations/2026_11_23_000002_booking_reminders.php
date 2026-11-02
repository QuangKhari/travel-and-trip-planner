<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** C-10: ghi lại email nhắc lịch đã gửi (3 ngày, 1 ngày trước khởi hành) để chạy lại lệnh không gửi trùng. */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_booking_reminders')) {
            Schema::create('tbl_booking_reminders', function (Blueprint $t) {
                $t->increments('reminderId');
                $t->integer('bookingId');
                $t->unsignedTinyInteger('daysBefore');
                $t->timestamp('sentAt')->useCurrent();
                $t->unique(['bookingId', 'daysBefore'], 'uniq_reminder');
                $t->foreign('bookingId', 'fk_reminder_booking')->references('bookingId')->on('tbl_booking')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_booking_reminders');
    }
};
