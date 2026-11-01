<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bản chụp dữ liệu OpenStreetMap (khách sạn, quán cà phê, nhà hàng...) quanh một điểm, để trang lập kế hoạch
 * KHÔNG phụ thuộc mạng lúc demo. Khác cache: không mất khi `php artisan cache:clear`.
 *  - snapKey : cùng khóa với cache của PlaceFinder (nhóm + tọa độ làm tròn + bán kính)
 *  - payload : JSON danh sách mục đã chuẩn hóa
 * Nạp sẵn bằng `php artisan planner:warm-osm`; mỗi lần Overpass trả lời thành công cũng tự cập nhật.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_osm_snapshots')) {
            return;
        }
        Schema::create('tbl_osm_snapshots', function (Blueprint $t) {
            $t->id('snapId');
            $t->string('snapKey', 120)->unique();
            $t->string('cat', 20);
            $t->decimal('latitude', 9, 6);
            $t->decimal('longitude', 9, 6);
            $t->unsignedInteger('radiusM');
            $t->unsignedInteger('itemCount')->default(0);
            $t->longText('payload');
            $t->timestamp('fetchedAt')->nullable();
            $t->index(['cat', 'latitude', 'longitude']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_osm_snapshots');
    }
};
