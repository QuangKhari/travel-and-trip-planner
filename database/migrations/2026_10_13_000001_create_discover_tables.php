<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Khám phá theo cảm hứng": cảm hứng (inspirations) -> điểm đến (destinations) -> tour.
 *
 * - tbl_inspirations            : 8 nhóm cảm hứng hiển thị ở Home (Trốn ra biển, Thiên nhiên, ...)
 * - tbl_destinations            : điểm đến toàn Việt Nam (không phụ thuộc có tour hay không)
 * - tbl_destination_inspiration : nối N-N, có `score` (0-100) để xếp hạng "hợp cảm hứng nào nhất"
 * - tbl_tours.destinationId     : (nullable) gắn tour vào điểm đến để card hiện "N tour"
 *
 * Kiểu cột id dùng integer có dấu để khớp tbl_tours.tourId (khóa ngoại).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tbl_inspirations', function (Blueprint $table) {
            $table->integer('inspirationId', true);
            $table->string('slug', 60)->unique();
            $table->string('name', 80);
            $table->string('tagline', 160);   // dòng phụ trên card: "Biển · đảo · hoàng hôn"
            $table->string('headline', 200);  // câu mở đầu ở trang /explore
            $table->string('icon', 40);       // class Font Awesome, vd "fa-water"
            $table->string('coverImage')->nullable(); // đường dẫn trong disk public, vd discover/inspirations/bien.webp
            $table->unsignedTinyInteger('displayOrder')->default(0);
            $table->boolean('isActive')->default(true);
        });

        Schema::create('tbl_destinations', function (Blueprint $table) {
            $table->integer('destinationId', true);
            $table->string('slug', 80)->unique();
            $table->string('name', 120);
            $table->string('province', 120);
            $table->enum('domain', ['b', 't', 'n'])->comment("'b': Mien Bac, 't': Mien Trung, 'n': Mien Nam");
            $table->text('description');
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            $table->string('coverImage')->nullable(); // vd discover/destinations/ly-son.webp
            $table->boolean('isActive')->default(true);
        });

        Schema::create('tbl_destination_inspiration', function (Blueprint $table) {
            $table->integer('destinationId');
            $table->integer('inspirationId');
            $table->unsignedTinyInteger('score')->default(50);
            $table->primary(['destinationId', 'inspirationId']);
            $table->index(['inspirationId', 'score']);
            $table->foreign('destinationId')->references('destinationId')->on('tbl_destinations')->cascadeOnDelete();
            $table->foreign('inspirationId')->references('inspirationId')->on('tbl_inspirations')->cascadeOnDelete();
        });

        Schema::table('tbl_tours', function (Blueprint $table) {
            $table->integer('destinationId')->nullable()->after('destination');
            $table->index('destinationId');
            $table->foreign('destinationId')->references('destinationId')->on('tbl_destinations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tbl_tours', function (Blueprint $table) {
            $table->dropForeign(['destinationId']);
            $table->dropIndex(['destinationId']);
            $table->dropColumn('destinationId');
        });
        Schema::dropIfExists('tbl_destination_inspiration');
        Schema::dropIfExists('tbl_destinations');
        Schema::dropIfExists('tbl_inspirations');
    }
};
