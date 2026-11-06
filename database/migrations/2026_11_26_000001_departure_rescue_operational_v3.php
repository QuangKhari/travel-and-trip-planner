<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private function indexExists(string $table, string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }

    private function foreignKeyExists(string $table, string $column): bool
    {
        return DB::table('information_schema.key_column_usage')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->whereNotNull('referenced_table_name')
            ->exists();
    }

    public function up(): void
    {
        if (!Schema::hasColumn('tbl_departure_rescue_plans', 'itineraryHash')) {
            Schema::table('tbl_departure_rescue_plans', function (Blueprint $t) {
                $t->string('itineraryHash', 64)->nullable();
            });
        }
        if (!Schema::hasColumn('tbl_departure_rescue_plans', 'quotedTotal')) {
            Schema::table('tbl_departure_rescue_plans', function (Blueprint $t) {
                $t->decimal('quotedTotal', 14, 0)->nullable();
            });
        }

        if (!Schema::hasTable('tbl_rescue_resources')) {
            Schema::create('tbl_rescue_resources', function (Blueprint $t) {
                $t->increments('resourceId');
                $t->string('name', 150);
                $t->string('kind', 30);
                $t->unsignedInteger('capacity');
                $t->boolean('active')->default(true);
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('tbl_rescue_resource_allocations')) {
            Schema::create('tbl_rescue_resource_allocations', function (Blueprint $t) {
                $t->increments('allocationId');
                $t->unsignedInteger('resourceId');
                $t->unsignedInteger('planId');
                $t->date('startDate');
                $t->date('endDate');
                $t->unsignedInteger('seats');
                $t->string('status', 20)->default('active');
                $t->timestamps();
            });
        }
        // The first failed attempt may have left this table in place but without
        // its date index and foreign keys. Reconcile those separately.
        if (!$this->indexExists('tbl_rescue_resource_allocations', 'uq_rescue_resource_plan')) {
            Schema::table('tbl_rescue_resource_allocations', function (Blueprint $t) {
                $t->unique(['resourceId', 'planId'], 'uq_rescue_resource_plan');
            });
        }
        if (!$this->indexExists('tbl_rescue_resource_allocations', 'idx_rescue_resource_dates')) {
            Schema::table('tbl_rescue_resource_allocations', function (Blueprint $t) {
                $t->index(['resourceId', 'startDate', 'endDate'], 'idx_rescue_resource_dates');
            });
        }
        if (!$this->foreignKeyExists('tbl_rescue_resource_allocations', 'resourceId')) {
            Schema::table('tbl_rescue_resource_allocations', function (Blueprint $t) {
                $t->foreign('resourceId', 'fk_rescue_alloc_resource')
                    ->references('resourceId')->on('tbl_rescue_resources')->restrictOnDelete();
            });
        }
        if (!$this->foreignKeyExists('tbl_rescue_resource_allocations', 'planId')) {
            Schema::table('tbl_rescue_resource_allocations', function (Blueprint $t) {
                $t->foreign('planId', 'fk_rescue_alloc_plan')
                    ->references('planId')->on('tbl_departure_rescue_plans')->restrictOnDelete();
            });
        }

        if (!Schema::hasColumn('tbl_departure_rescue_segments', 'resourceId')) {
            Schema::table('tbl_departure_rescue_segments', function (Blueprint $t) {
                $t->unsignedInteger('resourceId')->nullable();
            });
        }

        if (!Schema::hasTable('tbl_rescue_transfers')) {
            Schema::create('tbl_rescue_transfers', function (Blueprint $t) {
                $t->increments('transferId');
                $t->unsignedInteger('planId');
                $t->integer('bookingId');
                $t->integer('oldTourId');
                $t->integer('newTourId');
                $t->unsignedInteger('oldAvailabilityId');
                $t->unsignedInteger('newAvailabilityId');
                $t->decimal('oldTotal', 14, 0);
                $t->decimal('newTotal', 14, 0);
                $t->timestamp('createdAt')->useCurrent();
            });
        }
        if (!$this->indexExists('tbl_rescue_transfers', 'uq_rescue_transfer_plan_booking')) {
            Schema::table('tbl_rescue_transfers', function (Blueprint $t) {
                $t->unique(['planId', 'bookingId'], 'uq_rescue_transfer_plan_booking');
            });
        }
        if (!$this->foreignKeyExists('tbl_rescue_transfers', 'planId')) {
            Schema::table('tbl_rescue_transfers', function (Blueprint $t) {
                $t->foreign('planId', 'fk_rescue_transfer_plan')
                    ->references('planId')->on('tbl_departure_rescue_plans')->restrictOnDelete();
            });
        }
        if (!$this->foreignKeyExists('tbl_rescue_transfers', 'bookingId')) {
            Schema::table('tbl_rescue_transfers', function (Blueprint $t) {
                $t->foreign('bookingId', 'fk_rescue_transfer_booking')
                    ->references('bookingId')->on('tbl_booking')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_rescue_transfers');
        Schema::dropIfExists('tbl_rescue_resource_allocations');
        Schema::dropIfExists('tbl_rescue_resources');
        if (Schema::hasColumn('tbl_departure_rescue_segments', 'resourceId')) {
            Schema::table('tbl_departure_rescue_segments', fn(Blueprint $t) => $t->dropColumn('resourceId'));
        }
        foreach (['itineraryHash', 'quotedTotal'] as $column) {
            if (Schema::hasColumn('tbl_departure_rescue_plans', $column)) {
                Schema::table('tbl_departure_rescue_plans', fn(Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
