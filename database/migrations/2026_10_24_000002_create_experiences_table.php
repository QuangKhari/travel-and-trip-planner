<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Trải nghiệm (/trai-nghiem): cùng khuôn với tbl_foods (gắn điểm đến, 3 trường pháp lý, reviewStatus). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_experiences')) {
            return;
        }
        Schema::create('tbl_experiences', function (Blueprint $t) {
            $t->integer('experienceId', true);
            $t->string('slug', 100)->unique();
            $t->string('name', 160);
            $t->integer('destinationId')->nullable();
            $t->string('category', 40)->nullable();      // loại: thuyền & thiên nhiên, lớp học & làng nghề, biểu diễn...
            $t->string('duration', 60)->nullable();      // "Nửa ngày", "1 đến 2 giờ"... (chữ, không hứa giờ giấc cụ thể)
            $t->text('description')->nullable();
            $t->string('coverImage', 255)->nullable();
            $t->boolean('isMustTry')->default(false);
            $t->string('sourceUrl', 255)->nullable();
            $t->string('license', 80)->nullable();
            $t->string('imageCredit', 255)->nullable();
            $t->enum('reviewStatus', ['draft', 'reviewed', 'published'])->default('draft');
            $t->timestamp('createdAt')->useCurrent();
            $t->timestamp('updatedAt')->nullable();
            $t->index('reviewStatus');
            $t->index('destinationId');
            $t->foreign('destinationId')->references('destinationId')->on('tbl_destinations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_experiences');
    }
};
