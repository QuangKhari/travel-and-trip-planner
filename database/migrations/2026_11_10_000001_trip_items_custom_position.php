<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mục tự thêm (refType = note | transport) có thể có vị trí để vẽ lên bản đồ và tính quãng đường.
 *  - latitude / longitude: tọa độ do người dùng cung cấp (NULL = chưa có vị trí). Mục lấy từ kho nội dung không dùng hai cột này
 *    (tọa độ của chúng nằm ở bảng nội dung).
 *  - source: nguồn tọa độ: 'link' (dán link Google Maps / tọa độ), 'map' (bấm chọn trên bản đồ), 'search' (dành cho tìm ngoài Travela).
 * Chạy lại an toàn: chỉ thêm cột còn thiếu.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cols = [
            'latitude' => fn(Blueprint $t) => $t->decimal('latitude', 10, 7)->nullable(),
            'longitude' => fn(Blueprint $t) => $t->decimal('longitude', 10, 7)->nullable(),
            'source' => fn(Blueprint $t) => $t->string('source', 12)->nullable(),
        ];
        foreach ($cols as $col => $add) {
            if (!Schema::hasColumn('tbl_trip_items', $col)) {
                Schema::table('tbl_trip_items', $add);
            }
        }
    }

    public function down(): void
    {
        foreach (['source', 'longitude', 'latitude'] as $col) {
            if (Schema::hasColumn('tbl_trip_items', $col)) {
                Schema::table('tbl_trip_items', fn(Blueprint $t) => $t->dropColumn($col));
            }
        }
    }
};
