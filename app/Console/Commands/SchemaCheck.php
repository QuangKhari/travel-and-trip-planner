<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kiểm tra cơ sở dữ liệu đã có đủ bảng / cột / khóa của các phân hệ ghép đoàn, Departure Rescue (V1–V3), hoàn tiền, phiên bản tour,
 * đối soát và sự cố; báo các migration chưa chạy. Chạy: php artisan travela:schema-check (trả mã lỗi 1 nếu thiếu).
 */
class SchemaCheck extends Command
{
    protected $signature = 'travela:schema-check';
    protected $description = 'Kiểm tra schema của ghép đoàn, rescue, hoàn tiền, phiên bản tour, đối soát và sự cố';

    private const EXPECT = [
        'tbl_tour_availability' => ['availabilityId', 'tourId', 'departureDate', 'totalSlots', 'bookedSlots', 'heldSlots', 'status', 'minParticipants', 'shortfallFlaggedAt'],
        'tbl_departure_groups' => ['groupId', 'departureDate', 'status', 'capacity', 'itineraryFingerprint', 'note', 'createdAt', 'updatedAt', 'fingerprintMode', 'shortfallFlaggedAt'],
        'tbl_departure_group_members' => ['memberId', 'groupId', 'availabilityId', 'createdAt'],
        'tbl_departure_rescue_plans' => ['planId', 'availabilityId', 'type', 'status', 'targetAvailabilityId', 'responseDeadline', 'description', 'serviceGuarantee', 'changeSummary', 'createdAt', 'updatedAt', 'appliedAt', 'itineraryHash', 'quotedTotal', 'declinePolicy', 'incidentId'],
        'tbl_departure_rescue_segments' => ['segmentId', 'planId', 'partnerAvailabilityId', 'resourceId', 'fromDay', 'toDay', 'sharedService', 'operationalDetails', 'createdAt'],
        'tbl_departure_rescue_responses' => ['responseId', 'planId', 'bookingId', 'decision', 'respondedAt', 'createdAt'],
        'tbl_departure_rescue_outbox' => ['outboxId', 'responseId', 'event', 'queuedAt', 'createdAt'],
        'tbl_rescue_resources' => ['resourceId', 'name', 'kind', 'capacity', 'active'],
        'tbl_rescue_resource_allocations' => ['allocationId', 'resourceId', 'planId', 'startDate', 'endDate', 'seats', 'status'],
        'tbl_resource_allocations' => ['allocationId', 'resourceId', 'ownerType', 'ownerId', 'startAt', 'endAt', 'quantity', 'status'],
        'tbl_rescue_transfers' => ['transferId', 'planId', 'bookingId', 'oldTourId', 'newTourId', 'oldAvailabilityId', 'newAvailabilityId', 'oldTotal', 'newTotal', 'createdAt'],
        'tbl_refunds' => ['refundId', 'bookingId', 'paidAmount', 'percent', 'amount', 'status', 'method', 'reference', 'processedBy', 'processedAt', 'rescuePlanId',
            'gateway', 'gatewayRefundRef', 'gatewayRefundNo', 'gatewayStatus', 'gatewayCode', 'gatewayMessage', 'gatewayAttempts', 'gatewayAt'],
        'tbl_checkout' => ['provider', 'providerTxnRef', 'providerTxnNo', 'paymentStatus', 'amount'],
        'tbl_booking' => ['availabilityId', 'departureDate', 'cancelReason', 'holdExpiresAt', 'tourVersionId'],
        'tbl_tour_versions' => ['versionId', 'tourId', 'version', 'contentHash', 'snapshot', 'createdBy', 'createdAt'],
        'tbl_reconciliation_findings' => ['findingId', 'fingerprint', 'type', 'severity', 'bookingId', 'refundId', 'detail', 'status', 'note', 'firstSeenAt', 'lastSeenAt', 'resolvedAt', 'resolvedBy'],
        'tbl_tour_incidents' => ['incidentId', 'availabilityId', 'tourId', 'type', 'severity', 'status', 'title', 'description', 'confidential', 'reportedBy', 'assignedTo', 'resolution', 'rescuePlanId', 'createdAt', 'updatedAt', 'resolvedAt', 'supplierId'],
        'tbl_incident_events' => ['eventId', 'incidentId', 'adminId', 'kind', 'message', 'createdAt'],
        'tbl_waitlist' => ['waitId', 'availabilityId', 'userId', 'numAdults', 'numChildren', 'status', 'offerToken', 'offeredAt', 'offerExpiresAt', 'bookingId', 'createdAt', 'updatedAt'],
        'tbl_booking_adjustments' => ['adjustId', 'bookingId', 'kind', 'oldAdults', 'oldChildren', 'newAdults', 'newChildren', 'oldTotal', 'newTotal', 'moneyStatus', 'refundPercent', 'settleAmount', 'method', 'reference', 'by', 'byId', 'note', 'createdAt', 'settledAt', 'settledBy'],
        'tbl_suppliers' => ['supplierId', 'name', 'kind', 'phone', 'email', 'note', 'active', 'createdAt'],
        'tbl_supplier_ratings' => ['ratingId', 'supplierId', 'availabilityId', 'rating', 'onTime', 'comment', 'ratedBy', 'createdAt'],
        'tbl_departure_costs' => ['costId', 'availabilityId', 'supplierId', 'category', 'description', 'amount', 'status', 'createdBy', 'createdAt', 'updatedAt'],
        'tbl_complaints' => ['complaintId', 'code', 'bookingId', 'userId', 'category', 'subject', 'status', 'claimedAmount', 'compensationAmount', 'compensationStatus', 'compensationMethod', 'compensationRef', 'compensationPaidAt', 'compensationBy', 'resolution', 'supplierId', 'assignedTo', 'customerUnread', 'createdAt', 'updatedAt', 'resolvedAt'],
        'tbl_complaint_messages' => ['messageId', 'complaintId', 'authorType', 'authorId', 'body', 'createdAt'],
    ];

    /** Khóa duy nhất / chỉ mục quan trọng: bảng => [tên chỉ mục]. Thiếu nghĩa là mất ràng buộc chống trùng. */
    private const INDEXES = [
        'tbl_tour_versions' => ['uniq_tourver_no', 'uniq_tourver_hash'],
        'tbl_reconciliation_findings' => ['uniq_recon_fp'],
        'tbl_waitlist' => ['uniq_wait_token', 'idx_wait_avail'],
        'tbl_supplier_ratings' => ['uniq_rating_dep'],
        'tbl_complaints' => ['uniq_complaint_code'],
        'tbl_refunds' => ['idx_refund_gateway', 'idx_refund_plan'],
    ];

    public function handle(): int
    {
        $bad = 0;
        foreach (self::EXPECT as $table => $cols) {
            if (!Schema::hasTable($table)) {
                $this->error("✗ Thiếu bảng {$table}");
                $bad++;
                continue;
            }
            $missing = array_values(array_filter($cols, fn ($c) => !Schema::hasColumn($table, $c)));
            if ($missing) {
                $this->error("✗ {$table}: thiếu cột " . implode(', ', $missing));
                $bad++;
            } else {
                $this->line("✓ {$table} (" . count($cols) . ' cột)');
            }
        }
        foreach (self::INDEXES as $table => $names) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            foreach ($names as $name) {
                if (!Schema::hasIndex($table, $name)) {
                    $this->error("✗ {$table}: thiếu chỉ mục {$name}");
                    $bad++;
                }
            }
        }
        if (Schema::hasTable('tbl_refunds') && !$this->hasUniqueOn('tbl_refunds', 'bookingId')) {
            $this->error('✗ tbl_refunds: bookingId không còn UNIQUE, có thể tạo trùng hồ sơ hoàn cho một đơn');
            $bad++;
        }
        // Migration chưa chạy
        if (Schema::hasTable('migrations')) {
            $ran = DB::table('migrations')->pluck('migration')->all();
            $pending = [];
            foreach (glob(database_path('migrations/*.php')) as $f) {
                $name = basename($f, '.php');
                if (!in_array($name, $ran, true)) {
                    $pending[] = $name;
                }
            }
            if ($pending) {
                $this->warn('! Migration chưa chạy: ' . implode(', ', $pending));
                $bad++;
            }
        }
        $bad === 0 ? $this->info('Schema đầy đủ.') : $this->error("Có {$bad} vấn đề, chạy php artisan migrate rồi kiểm tra lại.");

        return $bad === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function hasUniqueOn(string $table, string $column): bool
    {
        foreach (Schema::getIndexes($table) as $idx) {
            if (!empty($idx['unique']) && ($idx['columns'] ?? []) === [$column]) {
                return true;
            }
        }

        return false;
    }
}
