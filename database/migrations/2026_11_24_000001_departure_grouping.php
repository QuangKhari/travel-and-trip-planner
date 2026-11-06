<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tbl_tour_availability', function (Blueprint $t) {
            $t->unsignedInteger('minParticipants')->default(1);
        });
        Schema::create('tbl_departure_groups', function (Blueprint $t) {
            $t->increments('groupId');
            $t->date('departureDate');
            $t->string('status', 20)->default('active');
            $t->string('itineraryFingerprint', 64);
            $t->unsignedInteger('capacity');
            $t->text('note')->nullable();
            $t->timestamp('createdAt')->nullable()->useCurrent();
            $t->timestamp('updatedAt')->nullable();
        });
        Schema::create('tbl_departure_group_members', function (Blueprint $t) {
            $t->increments('memberId');
            $t->unsignedInteger('groupId');
            $t->unsignedInteger('availabilityId')->unique();
            $t->timestamp('createdAt')->nullable()->useCurrent();
            $t->foreign('groupId')->references('groupId')->on('tbl_departure_groups')->cascadeOnDelete();
            $t->foreign('availabilityId')->references('availabilityId')->on('tbl_tour_availability')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_departure_group_members');
        Schema::dropIfExists('tbl_departure_groups');
        Schema::table('tbl_tour_availability', fn (Blueprint $t) => $t->dropColumn('minParticipants'));
    }
};
