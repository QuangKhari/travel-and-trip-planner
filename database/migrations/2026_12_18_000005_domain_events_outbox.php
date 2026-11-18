<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outbox sự kiện nghiệp vụ (BookingConfirmed, BookingCancelled, DepartureChanged, PlaceUnpublished): ghi CÙNG transaction với
 * thay đổi dữ liệu, xử lý qua hàng đợi (job ProcessDomainEvent) sau khi commit. Lệnh events:relay mỗi phút gửi lại sự kiện bị sót
 * (tiến trình chết giữa commit và đẩy job). Xử lý là idempotent: processedAt đánh dấu đã xong.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tbl_domain_events')) {
            Schema::create('tbl_domain_events', function (Blueprint $t) {
                $t->bigIncrements('eventId');
                $t->string('name', 40);
                $t->string('aggregate', 20);
                $t->string('aggregateId', 40);
                $t->json('payload');
                $t->timestamp('createdAt')->nullable();
                $t->timestamp('processedAt')->nullable();
                $t->unsignedSmallInteger('attempts')->default(0);
                $t->string('lastError', 255)->nullable();
                $t->index(['processedAt', 'createdAt'], 'idx_de_pending');
                $t->index(['aggregate', 'aggregateId'], 'idx_de_aggregate');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_domain_events');
    }
};
