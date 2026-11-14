<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Đợt 2:
 *  - tbl_place_signals: hành vi với từng nơi (thêm, xóa, ghé, bỏ qua, áp dụng / bỏ qua gợi ý) -> hồ sơ sở thích từng người + thống kê chung
 *  - nguồn và ngày kiểm tra dữ kiện của địa điểm (giờ, giá) -> hàng chờ "cần kiểm tra lại"
 *  - tbl_place_reviews: đánh giá nơi đã ghé sau chuyến (quay lại làm dữ liệu chấm điểm)
 *  - tbl_trip_comments, tbl_trip_votes: bình luận và bỏ phiếu từng mục trong chuyến nhóm
 *  - tbl_push_subscriptions: thông báo đẩy (Web Push) cho chuông theo dõi chuyến
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_place_signals')) {
            Schema::create('tbl_place_signals', function (Blueprint $t) {
                $t->bigIncrements('signalId');
                $t->integer('userId')->nullable();
                $t->integer('tripId')->nullable();
                $t->string('refType', 20);
                $t->unsignedBigInteger('refId');
                $t->string('grp', 20)->nullable();          // nhóm loại nơi (eat, nature, culture, fun, see, food, experience)
                $t->enum('signal', ['added', 'removed', 'visited', 'skipped', 'applied', 'ignored']);
                $t->timestamp('createdAt')->useCurrent();
                $t->index(['userId', 'grp']);
                $t->index(['refType', 'refId', 'signal']);
            });
        }
        Schema::table('tbl_external_places', function (Blueprint $t) {
            if (!Schema::hasColumn('tbl_external_places', 'factsSource')) {
                $t->string('factsSource', 120)->nullable();
                $t->string('factsUrl', 255)->nullable();
                $t->timestamp('factsCheckedAt')->nullable();
            }
        });
        if (!Schema::hasTable('tbl_place_reviews')) {
            Schema::create('tbl_place_reviews', function (Blueprint $t) {
                $t->bigIncrements('reviewId');
                $t->integer('userId');
                $t->integer('tripId')->nullable();
                $t->string('refType', 20);
                $t->unsignedBigInteger('refId');
                $t->unsignedTinyInteger('stars');
                $t->unsignedSmallInteger('stayMin')->nullable();   // ở bao lâu thực tế
                $t->boolean('crowded')->nullable();
                $t->string('text', 500)->nullable();
                $t->timestamp('createdAt')->useCurrent();
                $t->unique(['userId', 'tripId', 'refType', 'refId']);
                $t->index(['refType', 'refId']);
            });
        }
        if (!Schema::hasTable('tbl_trip_comments')) {
            Schema::create('tbl_trip_comments', function (Blueprint $t) {
                $t->bigIncrements('commentId');
                $t->integer('tripId');
                $t->integer('userId');
                $t->string('itemKey', 80);                     // type:refId hoặc custom:<nhãn> — bền qua các lần lưu
                $t->string('text', 500);
                $t->timestamp('createdAt')->useCurrent();
                $t->index(['tripId', 'itemKey']);
                $t->foreign('tripId')->references('tripId')->on('tbl_trips')->cascadeOnDelete();
            });
        }
        if (!Schema::hasTable('tbl_trip_votes')) {
            Schema::create('tbl_trip_votes', function (Blueprint $t) {
                $t->integer('tripId');
                $t->integer('userId');
                $t->string('itemKey', 80);
                $t->tinyInteger('vote');                       // 1 muốn đi, -1 không muốn
                $t->timestamp('createdAt')->useCurrent();
                $t->primary(['tripId', 'userId', 'itemKey']);
                $t->foreign('tripId')->references('tripId')->on('tbl_trips')->cascadeOnDelete();
            });
        }
        if (!Schema::hasTable('tbl_push_subscriptions')) {
            Schema::create('tbl_push_subscriptions', function (Blueprint $t) {
                $t->bigIncrements('subId');
                $t->integer('userId');
                $t->string('endpoint', 500);
                $t->string('p256dh', 120);
                $t->string('auth', 60);
                $t->timestamp('createdAt')->useCurrent();
                $t->timestamp('failedAt')->nullable();
                $t->unique('endpoint');
                $t->index('userId');
            });
        }
    }

    public function down(): void
    {
        foreach (['tbl_push_subscriptions', 'tbl_trip_votes', 'tbl_trip_comments', 'tbl_place_reviews', 'tbl_place_signals'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('tbl_external_places', function (Blueprint $t) {
            foreach (['factsSource', 'factsUrl', 'factsCheckedAt'] as $c) {
                if (Schema::hasColumn('tbl_external_places', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
