<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tuần 1 (mục 0.1-J và mục 1 của kế hoạch v6). Mỗi bước kiểm tra trạng thái hiện tại nên CHẠY LẠI ĐƯỢC an toàn
 * (MySQL không hoàn tác DDL, nên một lần chạy dở dang để lại các bước đã xong).
 *  - tbl_trips.kind: 'tray' (khay) hay 'trip' (chuyến thật); tbl_trips.destinationId (bắt buộc với trip, NULL với tray; code kiểm tra).
 *  - tbl_trip_items: bỏ unique(tripId, refType, refId); thêm unique(tripId, dayNo, orderNo);
 *    refId nullable; refType thêm experience/transport/note; thêm slot, note, label nếu chưa có.
 */
return new class extends Migration
{
    private const OLD_UNIQUE = 'tbl_trip_items_tripid_reftype_refid_unique';
    private const NEW_UNIQUE = 'uniq_trip_item_position';

    public function up(): void
    {
        if (!Schema::hasColumn('tbl_trips', 'kind')) {
            Schema::table('tbl_trips', function (Blueprint $t) {
                $t->enum('kind', ['tray', 'trip'])->default('trip')->after('userId');
            });
        }
        if (!Schema::hasColumn('tbl_trips', 'destinationId')) {
            Schema::table('tbl_trips', function (Blueprint $t) {
                $t->integer('destinationId')->nullable()->after('title');
                $t->foreign('destinationId')->references('destinationId')->on('tbl_destinations')->nullOnDelete();
            });
        }

        // Khay hiện có (do TripTray tạo) chuyển thành kind = tray
        DB::table('tbl_trips')->where('title', 'Khay chuyến đi')->where('status', 'draft')->update(['kind' => 'tray']);

        if (!Schema::hasIndex('tbl_trips', 'idx_trips_user_kind_status')) {
            Schema::table('tbl_trips', function (Blueprint $t) {
                $t->index(['userId', 'kind', 'status'], 'idx_trips_user_kind_status');
            });
        }

        // Thêm unique mới TRƯỚC, bỏ unique cũ SAU: khóa ngoại tripId luôn còn một chỉ mục bắt đầu bằng tripId.
        if (!Schema::hasIndex('tbl_trip_items', self::NEW_UNIQUE)) {
            Schema::table('tbl_trip_items', function (Blueprint $t) {
                $t->unique(['tripId', 'dayNo', 'orderNo'], self::NEW_UNIQUE);
            });
        }
        if (Schema::hasIndex('tbl_trip_items', self::OLD_UNIQUE)) {
            Schema::table('tbl_trip_items', function (Blueprint $t) {
                $t->dropUnique(self::OLD_UNIQUE);
            });
        }

        // Đổi enum và refId nullable: chạy lại vô hại
        Schema::table('tbl_trip_items', function (Blueprint $t) {
            $t->enum('refType', ['destination', 'place', 'event', 'food', 'tour', 'experience', 'transport', 'note'])->change();
            $t->integer('refId')->nullable()->change();
        });

        // Chỉ thêm cột còn thiếu (cột `note` có thể đã tồn tại sẵn, giữ nguyên kiểu của nó)
        foreach (['slot' => 20, 'note' => 255, 'label' => 160] as $col => $len) {
            if (!Schema::hasColumn('tbl_trip_items', $col)) {
                Schema::table('tbl_trip_items', function (Blueprint $t) use ($col, $len) {
                    $t->string($col, $len)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['slot', 'label'] as $col) {   // `note` có thể không do migration này tạo nên không xóa
            if (Schema::hasColumn('tbl_trip_items', $col)) {
                Schema::table('tbl_trip_items', fn(Blueprint $t) => $t->dropColumn($col));
            }
        }

        // Mục mới không còn chỗ đứng trong enum cũ
        DB::table('tbl_trip_items')->whereIn('refType', ['experience', 'transport', 'note'])->delete();
        DB::table('tbl_trip_items')->whereNull('refId')->delete();

        Schema::table('tbl_trip_items', function (Blueprint $t) {
            $t->enum('refType', ['destination', 'place', 'event', 'food', 'tour'])->change();
            $t->integer('refId')->nullable(false)->change();
        });
        if (!Schema::hasIndex('tbl_trip_items', self::OLD_UNIQUE)) {
            Schema::table('tbl_trip_items', fn(Blueprint $t) => $t->unique(['tripId', 'refType', 'refId']));
        }
        if (Schema::hasIndex('tbl_trip_items', self::NEW_UNIQUE)) {
            Schema::table('tbl_trip_items', fn(Blueprint $t) => $t->dropUnique(self::NEW_UNIQUE));
        }

        if (Schema::hasIndex('tbl_trips', 'idx_trips_user_kind_status')) {
            Schema::table('tbl_trips', fn(Blueprint $t) => $t->dropIndex('idx_trips_user_kind_status'));
        }
        if (Schema::hasColumn('tbl_trips', 'destinationId')) {
            Schema::table('tbl_trips', fn(Blueprint $t) => $t->dropForeign(['destinationId']));
            Schema::table('tbl_trips', fn(Blueprint $t) => $t->dropColumn('destinationId'));
        }
        if (Schema::hasColumn('tbl_trips', 'kind')) {
            Schema::table('tbl_trips', fn(Blueprint $t) => $t->dropColumn('kind'));
        }
    }
};
