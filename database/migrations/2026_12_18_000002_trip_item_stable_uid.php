<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Định danh ổn định cho mục lịch (tbl_trip_items.itemUid, ULID 26 ký tự): lưu chuyến theo THAY ĐỔI (sửa đúng dòng, thêm dòng mới,
 * xóa dòng bị bỏ) thay vì xóa hết rồi ghi lại, nên itemId / itemUid không đổi qua các lần lưu. Mỗi lần ghé một nơi là một mục riêng
 * (cùng địa điểm có thể xuất hiện ở nhiều ngày).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tbl_trip_items') || Schema::hasColumn('tbl_trip_items', 'itemUid')) {
            return;
        }
        Schema::table('tbl_trip_items', function (Blueprint $t) {
            $t->char('itemUid', 26)->nullable();
            $t->unique(['tripId', 'itemUid'], 'uq_trip_item_uid');
        });
        DB::table('tbl_trip_items')->whereNull('itemUid')->orderBy('itemId')->select('itemId')->chunkById(500, function ($rows) {
            foreach ($rows as $r) {
                DB::table('tbl_trip_items')->where('itemId', $r->itemId)->update(['itemUid' => (string) Str::ulid()]);
            }
        }, 'itemId');
    }

    public function down(): void
    {
        if (Schema::hasColumn('tbl_trip_items', 'itemUid')) {
            Schema::table('tbl_trip_items', function (Blueprint $t) {
                $t->dropUnique('uq_trip_item_uid');
                $t->dropColumn('itemUid');
            });
        }
    }
};
