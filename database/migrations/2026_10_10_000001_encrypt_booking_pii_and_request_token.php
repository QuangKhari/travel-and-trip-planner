<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bản mã dài hơn bản rõ (email đang là varchar(50)) nên phải nới cột
        Schema::table('tbl_booking', function (Blueprint $table) {
            $table->text('fullName')->change();
            $table->text('email')->change();
            $table->text('phoneNumber')->change();
            $table->text('address')->change();
        });

        if (!Schema::hasColumn('tbl_booking', 'requestToken')) {
            Schema::table('tbl_booking', function (Blueprint $table) {
                $table->char('requestToken', 36)->nullable()->after('bookingCode');
                $table->unique(['userId', 'requestToken'], 'uniq_booking_user_token');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tbl_booking', 'requestToken')) {
            Schema::table('tbl_booking', function (Blueprint $table) {
                $table->dropUnique('uniq_booking_user_token');
                $table->dropColumn('requestToken');
            });
        }
    }
};
