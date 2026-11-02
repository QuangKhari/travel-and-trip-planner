<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Yêu cầu tư vấn gắn với chuyến đi (T-39 Human Travel Agent).
 * Khách bấm "Yêu cầu tư vấn" ở trang chuyến đi -> tạo ticket; nhân viên nhận, trả lời (email qua hàng đợi), đóng.
 * MVP: không SLA, không phân công tự động.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_support_tickets')) {
            Schema::create('tbl_support_tickets', function (Blueprint $t) {
                $t->increments('ticketId');
                $t->string('code', 20)->unique();
                $t->integer('userId');
                $t->integer('tripId')->nullable();
                $t->string('topic', 30)->default('general');       // general | itinerary | budget | booking | other
                $t->string('subject', 160);
                $t->string('contactPhone', 20)->nullable();
                $t->enum('status', ['open', 'in_progress', 'answered', 'closed'])->default('open');
                $t->integer('assignedTo')->nullable();             // adminId
                $t->timestamp('lastMessageAt')->nullable();
                $t->boolean('customerUnread')->default(false);
                $t->timestamp('createdAt')->useCurrent();
                $t->timestamp('closedAt')->nullable();
                $t->index(['status', 'lastMessageAt'], 'idx_ticket_status');
                $t->index('userId', 'idx_ticket_user');
                $t->index('tripId', 'idx_ticket_trip');
            });
        }
        if (!Schema::hasTable('tbl_support_ticket_messages')) {
            Schema::create('tbl_support_ticket_messages', function (Blueprint $t) {
                $t->increments('messageId');
                $t->unsignedInteger('ticketId');
                $t->string('authorType', 10);                      // customer | staff
                $t->integer('authorId');
                $t->text('body');
                $t->timestamp('createdAt')->useCurrent();
                $t->index('ticketId', 'idx_ticket_msg');
                $t->foreign('ticketId', 'fk_ticket_msg')->references('ticketId')->on('tbl_support_tickets')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_support_ticket_messages');
        Schema::dropIfExists('tbl_support_tickets');
    }
};
