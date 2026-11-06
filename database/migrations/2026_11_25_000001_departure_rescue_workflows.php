<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tbl_departure_rescue_plans', function (Blueprint $t) {
            $t->increments('planId');
            $t->unsignedInteger('availabilityId');
            $t->string('type', 24); // partial / date_change / keep / cancel_review
            $t->string('status', 24)->default('draft'); // draft/pending/ready/applied/rejected/expired/cancelled
            $t->unsignedInteger('targetAvailabilityId')->nullable();
            $t->dateTime('responseDeadline');
            $t->text('description');
            $t->text('serviceGuarantee');
            $t->string('changeSummary', 1000);
            $t->timestamp('createdAt')->nullable()->useCurrent();
            $t->timestamp('updatedAt')->nullable();
            $t->timestamp('appliedAt')->nullable();
            $t->index(['availabilityId','status']);
            $t->foreign('availabilityId')->references('availabilityId')->on('tbl_tour_availability')->restrictOnDelete();
        });
        Schema::create('tbl_departure_rescue_segments', function (Blueprint $t) {
            $t->increments('segmentId');
            $t->unsignedInteger('planId');
            $t->unsignedInteger('partnerAvailabilityId');
            $t->unsignedTinyInteger('fromDay');
            $t->unsignedTinyInteger('toDay');
            $t->string('sharedService', 40); // transport / guide / activity
            $t->text('operationalDetails');
            $t->timestamp('createdAt')->nullable()->useCurrent();
            $t->foreign('planId')->references('planId')->on('tbl_departure_rescue_plans')->cascadeOnDelete();
            $t->foreign('partnerAvailabilityId')->references('availabilityId')->on('tbl_tour_availability')->restrictOnDelete();
        });
        Schema::create('tbl_departure_rescue_responses', function (Blueprint $t) {
            $t->increments('responseId');
            $t->unsignedInteger('planId');
            $t->integer('bookingId');
            $t->string('decision', 20)->default('pending'); // pending/accepted/declined
            $t->timestamp('respondedAt')->nullable();
            $t->timestamp('createdAt')->nullable()->useCurrent();
            $t->unique(['planId','bookingId']);
            $t->foreign('planId')->references('planId')->on('tbl_departure_rescue_plans')->cascadeOnDelete();
            $t->foreign('bookingId')->references('bookingId')->on('tbl_booking')->cascadeOnDelete();
        });
        Schema::create('tbl_departure_rescue_outbox', function (Blueprint $t) {
            $t->increments('outboxId');
            $t->unsignedInteger('responseId');
            $t->string('event', 32);
            $t->timestamp('queuedAt')->nullable();
            $t->timestamp('createdAt')->nullable()->useCurrent();
            $t->unique(['responseId','event']);
            $t->foreign('responseId')->references('responseId')->on('tbl_departure_rescue_responses')->cascadeOnDelete();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('tbl_departure_rescue_outbox');
        Schema::dropIfExists('tbl_departure_rescue_responses');
        Schema::dropIfExists('tbl_departure_rescue_segments');
        Schema::dropIfExists('tbl_departure_rescue_plans');
    }
};
