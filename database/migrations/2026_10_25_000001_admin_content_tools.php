<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin hoàn thiện:
 *  - 5 bảng nội dung: lưu ai duyệt, lúc nào, lý do từ chối (reviewedBy / reviewedAt / reviewNote)
 *  - tbl_content_review_log: nhật ký mọi lần đổi trạng thái / sửa / xóa
 *  - isFeatured (món, điểm đến) và isHero (điểm đến): chọn tay thay cho xoay ngẫu nhiên
 *  - tbl_contact_messages: hộp thư liên hệ
 *  - tbl_blog_posts: bài blog quản lý trong admin
 * Có guard hasTable/hasColumn để chạy lại an toàn.
 */
return new class extends Migration {
    private const CONTENT_TABLES = ['tbl_foods', 'tbl_events', 'tbl_guides', 'tbl_itineraries', 'tbl_external_places'];

    public function up(): void
    {
        foreach (self::CONTENT_TABLES as $t) {
            if (!Schema::hasTable($t)) {
                continue;
            }
            Schema::table($t, function (Blueprint $b) use ($t) {
                if (!Schema::hasColumn($t, 'reviewedBy')) {
                    $b->integer('reviewedBy')->nullable();
                }
                if (!Schema::hasColumn($t, 'reviewedAt')) {
                    $b->timestamp('reviewedAt')->nullable();
                }
                if (!Schema::hasColumn($t, 'reviewNote')) {
                    $b->string('reviewNote', 255)->nullable();
                }
            });
        }

        foreach (['tbl_foods', 'tbl_destinations'] as $t) {
            if (Schema::hasTable($t) && !Schema::hasColumn($t, 'isFeatured')) {
                Schema::table($t, fn(Blueprint $b) => $b->boolean('isFeatured')->default(false));
            }
        }
        if (!Schema::hasColumn('tbl_destinations', 'isHero')) {
            Schema::table('tbl_destinations', fn(Blueprint $b) => $b->boolean('isHero')->default(false));
        }

        if (!Schema::hasTable('tbl_content_review_log')) {
            Schema::create('tbl_content_review_log', function (Blueprint $t) {
                $t->bigIncrements('logId');
                $t->string('kind', 20);                       // foods | events | guides | itineraries | places
                $t->integer('refId');
                $t->string('action', 20);                     // status | reject | create | update | delete
                $t->string('fromStatus', 12)->nullable();
                $t->string('toStatus', 12)->nullable();
                $t->integer('adminId')->nullable();
                $t->string('note', 255)->nullable();
                $t->timestamp('createdAt')->useCurrent();
                $t->index(['kind', 'refId']);
                $t->index('createdAt');
            });
        }

        if (!Schema::hasTable('tbl_contact_messages')) {
            Schema::create('tbl_contact_messages', function (Blueprint $t) {
                $t->integer('messageId', true);
                $t->integer('userId')->nullable();
                $t->string('name', 100);
                $t->string('phone', 20);
                $t->string('email', 255);
                $t->string('topic', 20)->default('other');
                $t->string('bookingCode', 40)->nullable();
                $t->text('message');
                $t->enum('status', ['new', 'read', 'replied', 'closed'])->default('new');
                $t->boolean('mailSent')->default(false);     // thư báo cho nhân viên đã gửi được chưa
                $t->text('adminNote')->nullable();
                $t->integer('handledBy')->nullable();
                $t->timestamp('handledAt')->nullable();
                $t->timestamp('createdAt')->useCurrent();
                $t->index(['status', 'createdAt']);
            });
        }

        if (!Schema::hasTable('tbl_blog_posts')) {
            Schema::create('tbl_blog_posts', function (Blueprint $t) {
                $t->integer('postId', true);
                $t->string('slug', 120)->unique();
                $t->string('title', 200);
                $t->string('excerpt', 400)->nullable();
                $t->string('category', 60);
                $t->string('image', 160)->nullable();         // tên tệp trong public/clients/assets/images/blog/
                $t->date('publishedOn');
                $t->unsignedTinyInteger('readMinutes')->default(3);
                $t->boolean('featured')->default(false);
                $t->text('intro')->nullable();
                $t->string('quote', 255)->nullable();
                $t->json('sections');                          // [[tiêu đề, [đoạn, đoạn...]], ...] như config/blog.php
                $t->boolean('isPublished')->default(false);
                $t->timestamp('createdAt')->useCurrent();
                $t->timestamp('updatedAt')->nullable();
                $t->index(['isPublished', 'publishedOn']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_blog_posts');
        Schema::dropIfExists('tbl_contact_messages');
        Schema::dropIfExists('tbl_content_review_log');
        foreach (['tbl_foods', 'tbl_destinations'] as $t) {
            if (Schema::hasColumn($t, 'isFeatured')) {
                Schema::table($t, fn(Blueprint $b) => $b->dropColumn('isFeatured'));
            }
        }
        if (Schema::hasColumn('tbl_destinations', 'isHero')) {
            Schema::table('tbl_destinations', fn(Blueprint $b) => $b->dropColumn('isHero'));
        }
        foreach (self::CONTENT_TABLES as $t) {
            if (Schema::hasTable($t)) {
                Schema::table($t, function (Blueprint $b) use ($t) {
                    foreach (['reviewedBy', 'reviewedAt', 'reviewNote'] as $c) {
                        if (Schema::hasColumn($t, $c)) {
                            $b->dropColumn($c);
                        }
                    }
                });
            }
        }
    }
};
