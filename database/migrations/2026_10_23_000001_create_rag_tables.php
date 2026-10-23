<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Giai đoạn G (RAG): tbl_chunks (đoạn văn + vector) và tbl_chat_logs (nhật ký hỏi đáp).
 *
 * Quy ước giữ như A1: khóa chính/ngoại là integer CÓ DẤU để khớp tbl_users.userId, tbl_destinations.destinationId.
 *
 * Vector lưu dạng nhị phân float32 (pack 'g*') thay vì JSON: nhỏ hơn khoảng 5 lần và đọc nhanh hơn.
 * Độ giống (cosine) tính trong ứng dụng. Không dùng kiểu VECTOR của MySQL 9 vì bản Community
 * không có hàm tính khoảng cách (DISTANCE chỉ có ở HeatWave/Enterprise).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tbl_chunks', function (Blueprint $t) {
            $t->integer('chunkId', true);
            $t->string('refType', 20);                       // guide | destination | food | event | itinerary
            $t->integer('refId');                            // trỏ tới nhiều bảng nên không đặt khóa ngoại
            $t->unsignedSmallInteger('seq')->default(0);     // số thứ tự đoạn trong cùng bài
            $t->integer('destinationId')->nullable();
            $t->unsignedTinyInteger('month')->nullable();    // 1..12, dùng để tăng điểm (không loại bỏ)
            $t->string('title', 255);
            $t->mediumText('text');
            $t->mediumText('ftext');                         // bản không dấu, chữ thường của tiêu đề + nội dung (cho khớp từ khóa)
            $t->string('url', 255);                          // đường dẫn tương đối, vd /cam-nang/truoc-chuyen-di/esim
            $t->date('updatedOn')->nullable();
            $t->binary('embedding')->nullable();             // float32 little-endian
            $t->unsignedSmallInteger('dims')->nullable();
            $t->string('model', 80)->nullable();             // vd local-hash-512, openai:text-embedding-3-small
            $t->char('hash', 64);                            // sha256(tiêu đề + nội dung): đổi thì nhúng lại
            $t->timestamp('createdAt')->useCurrent();
            $t->timestamp('updatedAt')->nullable();
            $t->unique(['refType', 'refId', 'seq']);
            $t->index('destinationId');
            $t->index('model');
            $t->foreign('destinationId')->references('destinationId')->on('tbl_destinations')->cascadeOnDelete();
        });

        // Ép kiểu cột nhị phân lên MEDIUMBLOB trên MySQL (mặc định của binary() là BLOB 64KB, không đủ cho vector 3072 chiều)
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            \Illuminate\Support\Facades\DB::statement('ALTER TABLE tbl_chunks MODIFY embedding MEDIUMBLOB NULL');
        }

        Schema::create('tbl_chat_logs', function (Blueprint $t) {
            $t->integer('logId', true);
            $t->integer('userId')->nullable();
            $t->string('mode', 12)->default('rag_facts');    // llm | rag | rag_facts
            $t->string('question', 400);
            $t->json('chunkIds')->nullable();                // các đoạn đã đưa vào lời nhắc
            $t->json('factKeys')->nullable();                // các "hàm dữ kiện" đã gọi (season, event_dates, tours...)
            $t->text('answer')->nullable();
            $t->boolean('cited')->default(false);            // câu trả lời có dẫn nguồn hợp lệ không
            $t->boolean('refused')->default(false);          // từ chối vì thiếu dữ liệu
            $t->boolean('cached')->default(false);           // lấy từ bộ nhớ đệm (không tốn lượt)
            $t->string('engine', 12)->nullable();            // llm | extractive | none
            $t->string('error', 160)->nullable();
            $t->unsignedInteger('latencyMs')->default(0);
            $t->timestamp('createdAt')->useCurrent();
            $t->index(['userId', 'createdAt']);
            $t->index('createdAt');
            $t->foreign('userId')->references('userId')->on('tbl_users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_chat_logs');
        Schema::dropIfExists('tbl_chunks');
    }
};
