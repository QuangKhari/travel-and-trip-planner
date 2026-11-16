<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Đợt F: buổi thử người dùng — người thử, từng nhiệm vụ (tự bấm giờ, tự chấm), phiếu SUS. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_study_participants', function (Blueprint $t) {
            $t->increments('participantId');
            $t->string('code', 16)->unique();
            $t->unsignedTinyInteger('round')->default(1);
            $t->integer('userId')->unique();
            $t->integer('fixTripId')->nullable();
            $t->string('device', 16)->nullable();          // desktop | mobile (ghi lúc đồng ý)
            $t->dateTime('consentAt')->nullable();
            $t->dateTime('finishedAt')->nullable();
            $t->string('note', 255)->nullable();
            $t->dateTime('createdAt')->nullable();
        });
        Schema::create('tbl_study_tasks', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('participantId');
            $t->string('task', 32);
            $t->unsignedTinyInteger('position');
            $t->dateTime('startedAt')->nullable();
            $t->dateTime('endedAt')->nullable();
            $t->unsignedInteger('seconds')->nullable();
            $t->enum('outcome', ['success', 'skip'])->nullable();
            $t->unsignedSmallInteger('checks')->default(0);  // số lần bấm "Xong" (lần chưa đạt = vấp)
            $t->unsignedSmallInteger('clicks')->nullable();
            $t->unsignedSmallInteger('pages')->nullable();
            $t->json('baseline')->nullable();
            $t->unique(['participantId', 'task']);
            $t->index('task');
        });
        Schema::create('tbl_study_sus', function (Blueprint $t) {
            $t->unsignedInteger('participantId')->primary();
            for ($i = 1; $i <= 10; $i++) {
                $t->unsignedTinyInteger('q' . $i);
            }
            $t->decimal('score', 5, 1);
            $t->text('comment')->nullable();
            $t->dateTime('createdAt')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_study_sus');
        Schema::dropIfExists('tbl_study_tasks');
        Schema::dropIfExists('tbl_study_participants');
    }
};
