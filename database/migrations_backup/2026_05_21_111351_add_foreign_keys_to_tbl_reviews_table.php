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
        Schema::table('tbl_reviews', function (Blueprint $table) {
            $table->foreign(['tourId'], 'fk_review_tour')->references(['tourId'])->on('tbl_tours')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['userId'], 'fk_review_user')->references(['userId'])->on('tbl_users')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tbl_reviews', function (Blueprint $table) {
            $table->dropForeign('fk_review_tour');
            $table->dropForeign('fk_review_user');
        });
    }
};
