<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Làm giàu địa điểm (hết cảm giác "ghi chú"): ảnh có ghi nguồn + giấy phép, nguồn của mô tả,
 * và hàng chờ ảnh đề xuất từ Wikimedia Commons (chỉ giấy phép mở) để quản trị viên duyệt trước khi dùng.
 * Không lưu ảnh Google Places (điều khoản không cho lưu lâu dài).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_external_places', function (Blueprint $t) {
            if (!Schema::hasColumn('tbl_external_places', 'coverImage')) {
                $t->string('coverImage', 255)->nullable();        // đường dẫn trong disk media: discover/places/{placeId}.webp
                $t->string('imageCredit', 255)->nullable();       // "Tác giả / CC BY-SA 4.0" hoặc "Ảnh tự chụp: …"
                $t->string('imageLicense', 60)->nullable();
                $t->string('imageSourceUrl', 255)->nullable();    // trang mô tả tệp trên Commons, nếu có
            }
            if (!Schema::hasColumn('tbl_external_places', 'descriptionSource')) {
                $t->string('descriptionSource', 120)->nullable();
            }
        });

        if (!Schema::hasTable('tbl_place_media_candidates')) {
            Schema::create('tbl_place_media_candidates', function (Blueprint $t) {
                $t->bigIncrements('candidateId');
                $t->unsignedBigInteger('placeId');
                $t->string('file', 255);                 // tên tệp Commons, không có tiền tố "File:"
                $t->string('thumbUrl', 500);
                $t->string('pageUrl', 255)->nullable();
                $t->string('license', 60);
                $t->string('artist', 255);
                $t->unsignedInteger('distanceM')->nullable();   // khoảng cách tới tọa độ địa điểm (tìm theo vị trí)
                $t->string('via', 10)->default('geo');          // geo | name
                $t->enum('status', ['new', 'used', 'rejected'])->default('new');
                $t->timestamp('createdAt')->useCurrent();
                $t->unique(['placeId', 'file']);
                $t->index(['status', 'placeId']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_place_media_candidates');
        Schema::table('tbl_external_places', function (Blueprint $t) {
            foreach (['coverImage', 'imageCredit', 'imageLicense', 'imageSourceUrl', 'descriptionSource'] as $c) {
                if (Schema::hasColumn('tbl_external_places', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
