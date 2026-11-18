<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Giá theo mùa (một phần của "engine chính sách"): quy tắc tăng / giảm % theo khoảng ngày khởi hành, cho một tour hoặc mọi tour.
 * Đơn lưu lại % và nhãn đã áp lúc đặt (seasonPct, seasonLabel): điều chỉnh số khách về sau tính theo ĐÚNG mức đã chốt với khách.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tbl_price_rules')) {
            Schema::create('tbl_price_rules', function (Blueprint $t) {
                $t->increments('ruleId');
                $t->integer('tourId')->nullable();          // null = mọi tour
                $t->date('dateFrom');
                $t->date('dateTo');
                $t->smallInteger('pct');                    // -50..100 (% trên giá người lớn / trẻ em)
                $t->string('label', 80);
                $t->boolean('isActive')->default(true);
                $t->integer('createdBy')->nullable();
                $t->timestamp('createdAt')->useCurrent();
                $t->index(['tourId', 'dateFrom', 'dateTo']);
            });
        }
        if (Schema::hasTable('tbl_booking') && !Schema::hasColumn('tbl_booking', 'seasonPct')) {
            Schema::table('tbl_booking', function (Blueprint $t) {
                $t->smallInteger('seasonPct')->default(0);
                $t->string('seasonLabel', 80)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_price_rules');
        if (Schema::hasTable('tbl_booking') && Schema::hasColumn('tbl_booking', 'seasonPct')) {
            Schema::table('tbl_booking', function (Blueprint $t) {
                $t->dropColumn(['seasonPct', 'seasonLabel']);
            });
        }
    }
};
