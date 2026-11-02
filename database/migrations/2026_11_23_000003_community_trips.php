<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cộng đồng hành trình (T-24…T-35).
 *   tbl_trips: isPublic / publishedAt / summary (T-24, T-33), sourceTripId + sourceType = CLONED (T-32),
 *              viewCount / likeCount / cloneCount (T-35).
 *   tbl_trip_likes: mỗi người thích một chuyến một lần (T-26).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_trips', function (Blueprint $t) {
            $cols = [
                'isPublic' => fn() => $t->boolean('isPublic')->default(false),
                'publishedAt' => fn() => $t->timestamp('publishedAt')->nullable(),
                'summary' => fn() => $t->string('summary', 300)->nullable(),
                'sourceTripId' => fn() => $t->integer('sourceTripId')->nullable(),
                'sourceType' => fn() => $t->string('sourceType', 12)->nullable(),
                'viewCount' => fn() => $t->unsignedInteger('viewCount')->default(0),
                'likeCount' => fn() => $t->unsignedInteger('likeCount')->default(0),
                'cloneCount' => fn() => $t->unsignedInteger('cloneCount')->default(0),
            ];
            foreach ($cols as $c => $add) {
                if (!Schema::hasColumn('tbl_trips', $c)) {
                    $add();
                }
            }
        });
        if (!Schema::hasIndex('tbl_trips', 'idx_trips_public')) {
            Schema::table('tbl_trips', fn(Blueprint $t) => $t->index(['isPublic', 'publishedAt'], 'idx_trips_public'));
        }
        if (!Schema::hasIndex('tbl_trips', 'idx_trips_source')) {
            Schema::table('tbl_trips', fn(Blueprint $t) => $t->index('sourceTripId', 'idx_trips_source'));
        }
        if (!Schema::hasTable('tbl_trip_likes')) {
            Schema::create('tbl_trip_likes', function (Blueprint $t) {
                $t->integer('tripId');
                $t->integer('userId');
                $t->timestamp('createdAt')->useCurrent();
                $t->primary(['tripId', 'userId']);
                $t->unique(['userId', 'tripId'], 'uniq_like_user_trip');
                $t->foreign('tripId', 'fk_like_trip')->references('tripId')->on('tbl_trips')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_trip_likes');
        Schema::table('tbl_trips', function (Blueprint $t) {
            foreach (['idx_trips_public', 'idx_trips_source'] as $i) {
                if (Schema::hasIndex('tbl_trips', $i)) {
                    $t->dropIndex($i);
                }
            }
            foreach (['isPublic', 'publishedAt', 'summary', 'sourceTripId', 'sourceType', 'viewCount', 'likeCount', 'cloneCount'] as $c) {
                if (Schema::hasColumn('tbl_trips', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
