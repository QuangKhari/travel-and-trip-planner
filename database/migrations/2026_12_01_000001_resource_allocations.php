<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lịch tài nguyên chung (xe, hướng dẫn viên, phòng, hoạt động có sức chứa) theo KHOẢNG GIỜ [startAt, endAt).
 * Dùng chung tbl_rescue_resources làm danh mục tài nguyên; mọi phân bổ (đoàn thường, phương án ghép một phần...) đi qua
 * App\Services\ResourceAllocationService: khóa dòng tài nguyên rồi cộng sức chứa của các phân bổ giao nhau.
 * Bảng cũ tbl_rescue_resource_allocations (theo ngày) được giữ để đọc dữ liệu đã có; không ghi thêm.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_resource_allocations')) {
            Schema::create('tbl_resource_allocations', function (Blueprint $t) {
                $t->increments('allocationId');
                $t->unsignedInteger('resourceId');
                $t->string('ownerType', 32);                 // departure | rescue_plan | ...
                $t->unsignedInteger('ownerId');
                $t->dateTime('startAt');
                $t->dateTime('endAt');                       // không gồm: [startAt, endAt)
                $t->unsignedInteger('quantity')->default(1); // số chỗ (xe) / 1 (hướng dẫn viên)
                $t->string('status', 12)->default('confirmed'); // held | confirmed | released
                $t->unsignedInteger('createdBy')->nullable();
                $t->string('note', 255)->nullable();
                $t->timestamp('createdAt')->useCurrent();
                $t->timestamp('updatedAt')->nullable();
                $t->index(['resourceId', 'status', 'startAt', 'endAt'], 'idx_alloc_window');
                $t->index(['ownerType', 'ownerId'], 'idx_alloc_owner');
                $t->foreign('resourceId', 'fk_alloc_resource')->references('resourceId')->on('tbl_rescue_resources');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_resource_allocations');
    }
};
