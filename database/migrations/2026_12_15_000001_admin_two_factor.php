<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Đợt C mục 18: đăng nhập hai lớp cho tài khoản quản trị (mã 6 số từ ứng dụng xác thực + mã dự phòng). */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_admin') || Schema::hasColumn('tbl_admin', 'totpSecret')) {
            return;
        }
        Schema::table('tbl_admin', function (Blueprint $t) {
            $t->string('totpSecret', 255)->nullable();        // khóa bí mật, mã hóa AES-GCM (App\Support\AesGcm)
            $t->timestamp('totpEnabledAt')->nullable();
            $t->unsignedBigInteger('totpLastStep')->nullable(); // bước thời gian của mã dùng gần nhất: không cho dùng lại
            $t->text('totpRecovery')->nullable();              // JSON các mã dự phòng đã băm sha256
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('tbl_admin', 'totpSecret')) {
            Schema::table('tbl_admin', function (Blueprint $t) {
                $t->dropColumn(['totpSecret', 'totpEnabledAt', 'totpLastStep', 'totpRecovery']);
            });
        }
    }
};
