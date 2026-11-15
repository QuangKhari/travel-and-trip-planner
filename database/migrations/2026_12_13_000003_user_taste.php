<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Travela nhớ gì về bạn": người dùng sửa hồ sơ sở thích đã học (PlaceSignals::profile).
 * value: 1 thích, -1 không thích, 0 bỏ qua nhóm này. grp "_pause" value 1 = tạm ngừng học từ hành vi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_user_taste')) {
            Schema::create('tbl_user_taste', function (Blueprint $t) {
                $t->integer('userId');
                $t->string('grp', 20);
                $t->tinyInteger('value');
                $t->timestamp('updatedAt')->useCurrent();
                $t->primary(['userId', 'grp']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_user_taste');
    }
};
