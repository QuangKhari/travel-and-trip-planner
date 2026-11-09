<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Danh sách chờ, điều chỉnh số khách trong booking, chi phí / lợi nhuận từng đoàn, nhà cung cấp + đánh giá, khiếu nại sau tour.
 * Tiền hoàn / thu thêm của điều chỉnh và bồi hoàn khiếu nại có sổ riêng, KHÔNG dùng tbl_refunds (UNIQUE bookingId: một đơn một hồ sơ hoàn).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_waitlist')) {
            Schema::create('tbl_waitlist', function (Blueprint $t) {
                $t->increments('waitId');
                $t->integer('availabilityId');
                $t->integer('userId');
                $t->unsignedTinyInteger('numAdults');
                $t->unsignedTinyInteger('numChildren')->default(0);
                $t->string('status', 10)->default('waiting');        // waiting | offered | converted | expired | cancelled
                $t->char('offerToken', 40)->nullable()->unique('uniq_wait_token');
                $t->timestamp('offeredAt')->nullable();
                $t->timestamp('offerExpiresAt')->nullable();
                $t->integer('bookingId')->nullable();
                $t->timestamp('createdAt')->useCurrent();
                $t->timestamp('updatedAt')->nullable();
                $t->index(['availabilityId', 'status', 'createdAt'], 'idx_wait_avail');
                $t->index(['userId', 'status'], 'idx_wait_user');
            });
        }
        if (!Schema::hasTable('tbl_booking_adjustments')) {
            Schema::create('tbl_booking_adjustments', function (Blueprint $t) {
                $t->increments('adjustId');
                $t->integer('bookingId');
                $t->string('kind', 10);                              // increase | decrease
                $t->unsignedSmallInteger('oldAdults');
                $t->unsignedSmallInteger('oldChildren');
                $t->unsignedSmallInteger('newAdults');
                $t->unsignedSmallInteger('newChildren');
                $t->integer('oldTotal');
                $t->integer('newTotal');
                $t->string('moneyStatus', 16)->default('none');     // none | pending_collect | collected | pending_refund | refunded
                $t->unsignedTinyInteger('refundPercent')->nullable();
                $t->integer('settleAmount')->default(0);             // số tiền phải thu thêm / phải hoàn
                $t->string('method', 40)->nullable();
                $t->string('reference', 80)->nullable();
                $t->string('by', 10);                                // customer | admin
                $t->integer('byId')->nullable();
                $t->string('note', 255)->nullable();
                $t->timestamp('createdAt')->useCurrent();
                $t->timestamp('settledAt')->nullable();
                $t->integer('settledBy')->nullable();
                $t->index('bookingId', 'idx_adjust_booking');
                $t->index('moneyStatus', 'idx_adjust_money');
            });
        }
        if (!Schema::hasTable('tbl_suppliers')) {
            Schema::create('tbl_suppliers', function (Blueprint $t) {
                $t->increments('supplierId');
                $t->string('name', 150);
                $t->string('kind', 14);                              // transport | hotel | guide | restaurant | attraction | insurance | other
                $t->string('phone', 20)->nullable();
                $t->string('email', 80)->nullable();
                $t->string('note', 255)->nullable();
                $t->boolean('active')->default(true);
                $t->timestamp('createdAt')->useCurrent();
                $t->index('kind', 'idx_supplier_kind');
            });
        }
        if (!Schema::hasTable('tbl_supplier_ratings')) {
            Schema::create('tbl_supplier_ratings', function (Blueprint $t) {
                $t->increments('ratingId');
                $t->unsignedInteger('supplierId');
                $t->integer('availabilityId')->nullable();
                $t->unsignedTinyInteger('rating');                   // 1..5
                $t->boolean('onTime')->nullable();
                $t->string('comment', 500)->nullable();
                $t->integer('ratedBy')->nullable();
                $t->timestamp('createdAt')->useCurrent();
                $t->unique(['supplierId', 'availabilityId'], 'uniq_rating_dep');   // mỗi nhà cung cấp một đánh giá cho mỗi đoàn
                $t->index('supplierId', 'idx_rating_supplier');
            });
        }
        if (!Schema::hasTable('tbl_departure_costs')) {
            Schema::create('tbl_departure_costs', function (Blueprint $t) {
                $t->increments('costId');
                $t->integer('availabilityId');
                $t->unsignedInteger('supplierId')->nullable();
                $t->string('category', 12);                          // transport | hotel | guide | tickets | meals | insurance | other
                $t->string('description', 200);
                $t->unsignedBigInteger('amount');
                $t->string('status', 10)->default('estimated');      // estimated | actual
                $t->integer('createdBy')->nullable();
                $t->timestamp('createdAt')->useCurrent();
                $t->timestamp('updatedAt')->nullable();
                $t->index('availabilityId', 'idx_cost_avail');
                $t->index('supplierId', 'idx_cost_supplier');
            });
        }
        if (!Schema::hasTable('tbl_complaints')) {
            Schema::create('tbl_complaints', function (Blueprint $t) {
                $t->increments('complaintId');
                $t->string('code', 12)->unique('uniq_complaint_code');
                $t->integer('bookingId');
                $t->integer('userId');
                $t->string('category', 12);                          // service | guide | hotel | transport | food | itinerary | safety | other
                $t->string('subject', 160);
                $t->string('status', 14)->default('open');           // open | investigating | resolved | rejected | closed
                $t->integer('claimedAmount')->nullable();
                $t->integer('compensationAmount')->default(0);
                $t->string('compensationStatus', 10)->default('none'); // none | pending | paid
                $t->string('compensationMethod', 40)->nullable();
                $t->string('compensationRef', 80)->nullable();
                $t->timestamp('compensationPaidAt')->nullable();
                $t->integer('compensationBy')->nullable();
                $t->text('resolution')->nullable();
                $t->unsignedInteger('supplierId')->nullable();
                $t->integer('assignedTo')->nullable();
                $t->boolean('customerUnread')->default(false);
                $t->timestamp('createdAt')->useCurrent();
                $t->timestamp('updatedAt')->nullable();
                $t->timestamp('resolvedAt')->nullable();
                $t->index(['status', 'createdAt'], 'idx_complaint_status');
                $t->index('bookingId', 'idx_complaint_booking');
                $t->index('userId', 'idx_complaint_user');
            });
        }
        if (!Schema::hasTable('tbl_complaint_messages')) {
            Schema::create('tbl_complaint_messages', function (Blueprint $t) {
                $t->increments('messageId');
                $t->unsignedInteger('complaintId');
                $t->string('authorType', 10);                        // customer | staff
                $t->integer('authorId')->nullable();
                $t->text('body');
                $t->timestamp('createdAt')->useCurrent();
                $t->index('complaintId', 'idx_complaint_msg');
            });
        }
        if (Schema::hasTable('tbl_tour_incidents') && !Schema::hasColumn('tbl_tour_incidents', 'supplierId')) {
            Schema::table('tbl_tour_incidents', fn (Blueprint $t) => $t->unsignedInteger('supplierId')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tbl_tour_incidents', 'supplierId')) {
            Schema::table('tbl_tour_incidents', fn (Blueprint $t) => $t->dropColumn('supplierId'));
        }
        foreach (['tbl_complaint_messages', 'tbl_complaints', 'tbl_departure_costs', 'tbl_supplier_ratings', 'tbl_suppliers', 'tbl_booking_adjustments', 'tbl_waitlist'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
