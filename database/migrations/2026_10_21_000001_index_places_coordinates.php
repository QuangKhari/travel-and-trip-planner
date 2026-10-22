<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A13: truy vấn "địa điểm gần đây" lọc bằng khung tọa độ rồi mới tính khoảng cách (App\Support\Geo).
 * Chỉ số (latitude, longitude) đủ cho vài chục nghìn dòng trên cả MySQL lẫn SQLite.
 * (Cột POINT + SPATIAL INDEX của MySQL 8 là bước nâng cấp khi dữ liệu tầng 2 lên hàng chục nghìn dòng.)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tbl_external_places', function (Blueprint $t) {
            $t->index(['latitude', 'longitude'], 'idx_places_latlng');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_external_places', fn(Blueprint $t) => $t->dropIndex('idx_places_latlng'));
    }
};
