<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_email_verification')) {
            Schema::create('tbl_email_verification', function (Blueprint $table) {
                $table->integer('verificationId', true);
                $table->integer('userId')->unique();
                $table->char('tokenHash', 64)->unique();
                $table->dateTime('expiresAt');
                $table->dateTime('createdAt')->useCurrent();

                $table->foreign('userId')
                    ->references('userId')
                    ->on('tbl_users')
                    ->cascadeOnDelete();
            });
        }

        // Tài khoản đã tồn tại trước khi triển khai email verification
        // được xem là đã xác thực.
        DB::table('tbl_users')
            ->where('isActive', 'n')
            ->update([
                'isActive' => 'y',
            ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_email_verification');
    }
};
