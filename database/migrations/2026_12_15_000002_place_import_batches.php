<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Đợt C mục 19: mở rộng dữ liệu địa điểm theo lô có duyệt.
 * Tệp CSV/JSON -> tbl_import_batches + tbl_import_rows (kiểm tra từng dòng, đánh dấu trùng) -> người duyệt chọn -> tbl_external_places.
 * Không dòng nào vào kho địa điểm khi chưa có người duyệt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_import_batches')) {
            Schema::create('tbl_import_batches', function (Blueprint $t) {
                $t->id('batchId');
                $t->string('kind', 20)->default('places');
                $t->string('fileName', 160);
                $t->string('note', 255)->nullable();
                $t->integer('createdBy')->nullable();      // adminId; null = dòng lệnh
                $t->timestamp('createdAt')->useCurrent();
                $t->unsignedInteger('total')->default(0);
                $t->unsignedInteger('bad')->default(0);    // dòng có lỗi, không duyệt được
                $t->unsignedInteger('warn')->default(0);
                $t->unsignedInteger('dup')->default(0);
            });
        }
        if (!Schema::hasTable('tbl_import_rows')) {
            Schema::create('tbl_import_rows', function (Blueprint $t) {
                $t->id('rowId');
                $t->unsignedBigInteger('batchId');
                $t->unsignedInteger('line');
                $t->integer('destinationId')->nullable();
                $t->json('data');                          // giá trị đã chuẩn hóa
                $t->json('issues');                        // [{level: error|warn, code, msg}]
                $t->string('level', 10)->default('ok');    // ok | warn | error (mức nặng nhất)
                $t->unsignedBigInteger('matchPlaceId')->nullable();   // trùng với địa điểm đã có
                $t->string('status', 12)->default('pending');        // pending | created | merged | rejected
                $t->unsignedBigInteger('placeId')->nullable();
                $t->integer('decidedBy')->nullable();
                $t->timestamp('decidedAt')->nullable();
                $t->string('note', 255)->nullable();
                $t->index(['batchId', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_import_rows');
        Schema::dropIfExists('tbl_import_batches');
    }
};
