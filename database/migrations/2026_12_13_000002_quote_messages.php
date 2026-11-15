<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Báo giá tour riêng thành luồng trao đổi hai chiều (học TourRadar): khách và nhân viên nhắn qua lại trên trang báo giá
 * tới khi chốt; mỗi lần nhân viên báo giá lại là một "phiên bản" ghi vào luồng. Đếm tin chưa đọc cho từng phía.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_tour_quote_messages')) {
            Schema::create('tbl_tour_quote_messages', function (Blueprint $t) {
                $t->bigIncrements('messageId');
                $t->unsignedInteger('quoteId')->index();
                $t->string('sender', 10);              // customer | staff | system
                $t->integer('senderId')->nullable();
                $t->string('body', 1000);
                $t->timestamp('createdAt')->useCurrent();
            });
        }
        if (Schema::hasTable('tbl_tour_quotes') && !Schema::hasColumn('tbl_tour_quotes', 'version')) {
            Schema::table('tbl_tour_quotes', function (Blueprint $t) {
                $t->unsignedSmallInteger('version')->default(0);
                $t->unsignedSmallInteger('staffUnread')->default(0);
                $t->unsignedSmallInteger('customerUnread')->default(0);
                $t->timestamp('lastMessageAt')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_tour_quote_messages');
        if (Schema::hasColumn('tbl_tour_quotes', 'version')) {
            Schema::table('tbl_tour_quotes', fn(Blueprint $t) => $t->dropColumn(['version', 'staffUnread', 'customerUnread', 'lastMessageAt']));
        }
    }
};
