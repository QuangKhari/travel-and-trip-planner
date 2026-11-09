<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ba phân hệ mới:
 *   tbl_tour_versions            bản chụp nội dung tour (tiêu đề, mô tả, giá, thời lượng, điểm đến, bao gồm/không bao gồm, lịch trình) theo mã băm nội dung;
 *   tbl_booking.tourVersionId    phiên bản tour khách đã đồng ý khi đặt (đơn không tự chuyển sang phiên bản mới);
 *   tbl_reconciliation_findings  kết quả đối soát booking – thanh toán – hoàn tiền (lệnh payments:audit);
 *   tbl_tour_incidents / tbl_incident_events  sự cố tour và nhật ký xử lý; tbl_departure_rescue_plans.incidentId nối sự cố với phương án Departure Rescue.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_tour_versions')) {
            Schema::create('tbl_tour_versions', function (Blueprint $t) {
                $t->increments('versionId');
                $t->integer('tourId');
                $t->unsignedInteger('version');
                $t->char('contentHash', 64);
                $t->longText('snapshot');
                $t->integer('createdBy')->nullable();
                $t->timestamp('createdAt')->useCurrent();
                $t->unique(['tourId', 'version'], 'uniq_tourver_no');
                $t->unique(['tourId', 'contentHash'], 'uniq_tourver_hash');
            });
        }
        if (Schema::hasTable('tbl_booking') && !Schema::hasColumn('tbl_booking', 'tourVersionId')) {
            Schema::table('tbl_booking', function (Blueprint $t) {
                $t->unsignedInteger('tourVersionId')->nullable();
                $t->index('tourVersionId', 'idx_booking_tourver');
            });
        }
        if (!Schema::hasTable('tbl_reconciliation_findings')) {
            Schema::create('tbl_reconciliation_findings', function (Blueprint $t) {
                $t->increments('findingId');
                $t->char('fingerprint', 40)->unique('uniq_recon_fp');
                $t->string('type', 40);
                $t->string('severity', 8);                     // high | medium | low
                $t->integer('bookingId')->nullable();
                $t->unsignedInteger('refundId')->nullable();
                $t->text('detail')->nullable();
                $t->string('status', 10)->default('open');     // open | resolved | ignored
                $t->string('note', 255)->nullable();
                $t->timestamp('firstSeenAt')->useCurrent();
                $t->timestamp('lastSeenAt')->nullable();
                $t->timestamp('resolvedAt')->nullable();
                $t->integer('resolvedBy')->nullable();
                $t->index(['status', 'severity'], 'idx_recon_status');
                $t->index('bookingId', 'idx_recon_booking');
            });
        }
        if (!Schema::hasTable('tbl_tour_incidents')) {
            Schema::create('tbl_tour_incidents', function (Blueprint $t) {
                $t->increments('incidentId');
                $t->integer('availabilityId')->nullable();
                $t->integer('tourId')->nullable();
                $t->string('type', 16);                        // vehicle | hotel | guide | weather | health | supplier | schedule | other
                $t->string('severity', 8);                     // low | medium | high | critical
                $t->string('status', 10)->default('open');     // open | handling | resolved | closed
                $t->string('title', 200);
                $t->text('description')->nullable();
                $t->boolean('confidential')->default(false);
                $t->integer('reportedBy')->nullable();
                $t->integer('assignedTo')->nullable();
                $t->text('resolution')->nullable();
                $t->unsignedInteger('rescuePlanId')->nullable();
                $t->timestamp('createdAt')->useCurrent();
                $t->timestamp('updatedAt')->nullable();
                $t->timestamp('resolvedAt')->nullable();
                $t->index(['status', 'severity'], 'idx_incident_status');
                $t->index('availabilityId', 'idx_incident_avail');
            });
        }
        if (!Schema::hasTable('tbl_incident_events')) {
            Schema::create('tbl_incident_events', function (Blueprint $t) {
                $t->bigIncrements('eventId');
                $t->unsignedInteger('incidentId');
                $t->integer('adminId')->nullable();
                $t->string('kind', 12);                        // created | status | note | notify | plan | system
                $t->text('message');
                $t->timestamp('createdAt')->useCurrent();
                $t->index(['incidentId', 'createdAt'], 'idx_incident_event');
            });
        }
        if (Schema::hasTable('tbl_departure_rescue_plans') && !Schema::hasColumn('tbl_departure_rescue_plans', 'incidentId')) {
            Schema::table('tbl_departure_rescue_plans', fn (Blueprint $t) => $t->unsignedInteger('incidentId')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tbl_departure_rescue_plans', 'incidentId')) {
            Schema::table('tbl_departure_rescue_plans', fn (Blueprint $t) => $t->dropColumn('incidentId'));
        }
        Schema::dropIfExists('tbl_incident_events');
        Schema::dropIfExists('tbl_tour_incidents');
        Schema::dropIfExists('tbl_reconciliation_findings');
        if (Schema::hasColumn('tbl_booking', 'tourVersionId')) {
            Schema::table('tbl_booking', function (Blueprint $t) {
                $t->dropIndex('idx_booking_tourver');
                $t->dropColumn('tourVersionId');
            });
        }
        Schema::dropIfExists('tbl_tour_versions');
    }
};
