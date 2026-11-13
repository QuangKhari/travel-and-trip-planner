<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cảnh báo điểm đến do Travela nhập (đường sạt lở, điểm tạm đóng, bão, sự kiện cấm đường...).
 * La bàn đối chiếu với điểm đến / địa điểm / khoảng ngày của chuyến để cảnh báo và gợi ý đổi lịch.
 * Không tự lấy từ nguồn ngoài: người nhập ghi nguồn (sourceUrl) để người dùng kiểm tra.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_travel_alerts')) {
            return;
        }
        Schema::create('tbl_travel_alerts', function (Blueprint $t) {
            $t->increments('alertId');
            $t->integer('destinationId')->nullable()->index();
            $t->integer('placeId')->nullable()->index();            // tbl_external_places: chỉ đúng một địa điểm (vd tạm đóng cửa)
            $t->string('kind', 16)->default('other');              // weather | closure | traffic | crowd | other
            $t->string('level', 8)->default('warn');               // info | warn | bad
            $t->string('title', 160);
            $t->string('detail', 600)->nullable();
            $t->string('advice', 300)->nullable();                 // nên làm gì (hiện ở thẻ cảnh báo)
            $t->date('startsOn');
            $t->date('endsOn')->nullable();                         // null = chưa biết ngày kết thúc
            $t->string('sourceUrl', 255)->nullable();
            $t->boolean('isActive')->default(true);
            $t->integer('createdBy')->nullable();
            $t->timestamps();
            $t->index(['isActive', 'startsOn']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_travel_alerts');
    }
};
