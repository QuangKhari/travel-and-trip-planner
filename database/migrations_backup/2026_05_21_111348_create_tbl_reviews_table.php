<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tbl_reviews', function (Blueprint $table) {
            $table->integer('reviewId', true);
            $table->integer('tourId')->index('fk_review_tour');
            $table->integer('userId')->index('fk_review_user');
            $table->float('rating');
            $table->string('comment')->nullable();
            $table->timestamp('timestamp')->useCurrentOnUpdate()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tbl_reviews');
    }
};
