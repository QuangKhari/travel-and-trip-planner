<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_email_verification') && !Schema::hasColumn('tbl_email_verification', 'pendingEmail')) {
            Schema::table('tbl_email_verification', function (Blueprint $table) {
                $table->string('pendingEmail', 255)->nullable()->after('tokenHash');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tbl_email_verification') && Schema::hasColumn('tbl_email_verification', 'pendingEmail')) {
            Schema::table('tbl_email_verification', function (Blueprint $table) {
                $table->dropColumn('pendingEmail');
            });
        }
    }
};
