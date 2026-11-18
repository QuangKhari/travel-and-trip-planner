<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Câu đánh giá F1 do NGƯỜI KHÁC viết (bộ held-out, tài liệu v2.2 mục 8.1). Người viết nhận một đường dẫn có chữ ký,
 * chỉ thấy mô tả 7 nhóm câu (không thấy lời nhắc), tự viết câu và tự đánh dấu ý mình muốn nói (nhãn do người viết, không phải tác giả).
 * php artisan ai:peer-export ghi ra database/ai/eval_brief_peer.json.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tbl_ai_peer_cases')) {
            Schema::create('tbl_ai_peer_cases', function (Blueprint $t) {
                $t->increments('caseId');
                $t->string('writer', 40);                 // mã người viết (tên viết liền không dấu), không lưu email
                $t->string('grp', 16);                     // du | thieu | mo_ho | khong_dau | ngoai | nhieu_diem | injection
                $t->string('q', 500);
                $t->json('expect');
                $t->string('status', 10)->default('new');  // new | ok | rejected (tác giả chỉ loại câu trùng / không đọc được, không sửa nhãn)
                $t->timestamp('createdAt')->useCurrent();
                $t->index(['writer', 'createdAt']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_ai_peer_cases');
    }
};
