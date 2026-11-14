<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Số việc đang chờ, hiện thành chấm vàng ở menu trái và khung "Việc cần xử lý" của dashboard.
 * Cache 45 giây: menu hiện ở MỌI trang admin nên không truy vấn lại mỗi lần. Lỗi DB/bảng chưa có -> trả 0, không làm sập trang.
 */
final class AdminBadges
{
    private const KEY = 'admin.badges.v3';

    /**
     * shortfall: lịch + đoàn ghép sắp thiếu khách chưa có phương án; rescue: phương án đã đủ phản hồi chờ admin áp dụng / xử lý;
     * refundFail: yêu cầu hoàn bị cổng thanh toán từ chối, cần thử lại hoặc hoàn tay.
     * @return array{booking:int, unpaid:int, contact:int, reviewed:int, draft:int, pending:int, refund:int, support:int, shortfall:int, rescue:int, refundFail:int, incident:int, reconcile:int, waitlist:int, adjust:int, complaint:int}
     */
    public static function counts(): array
    {
        return Cache::remember(self::KEY, 45, function () {
            $c = ['booking' => 0, 'unpaid' => 0, 'contact' => 0, 'reviewed' => 0, 'draft' => 0, 'pending' => 0, 'refund' => 0, 'support' => 0, 'shortfall' => 0, 'rescue' => 0, 'refundFail' => 0, 'incident' => 0, 'reconcile' => 0, 'waitlist' => 0, 'adjust' => 0, 'complaint' => 0, 'placeReports' => 0, 'quotes' => 0];
            try {
                $c['booking'] = (int) DB::table('tbl_booking')->where('bookingStatus', 'n')->count();
                $c['unpaid'] = (int) DB::table('tbl_booking')
                    ->join('tbl_checkout', 'tbl_checkout.bookingId', '=', 'tbl_booking.bookingId')
                    ->where('tbl_booking.bookingStatus', 'y')
                    ->where('tbl_checkout.paymentStatus', 'n')
                    ->count();
                if (Schema::hasTable('tbl_refunds')) {
                    $c['refund'] = (int) DB::table('tbl_refunds')->where('status', 'pending')->count();
                }
                if (Schema::hasTable('tbl_refunds') && Schema::hasColumn('tbl_refunds', 'gatewayStatus')) {
                    $c['refundFail'] = (int) DB::table('tbl_refunds')->where('status', 'pending')->where('gatewayStatus', 'failed')->count();
                }
                if (Schema::hasTable('tbl_departure_rescue_plans')) {
                    $c['rescue'] = (int) DB::table('tbl_departure_rescue_plans')
                        ->where(fn ($q) => $q->where('status', 'ready')->orWhere(fn ($w) => $w->where('type', 'cancel_review')->where('status', 'manual_review')))
                        ->count();
                    if (Schema::hasColumn('tbl_tour_availability', 'shortfallFlaggedAt')) {
                        $busy = DB::table('tbl_departure_rescue_plans')->whereIn('status', ['pending', 'ready'])->select('availabilityId');
                        $c['shortfall'] = (int) DB::table('tbl_tour_availability')->whereNotNull('shortfallFlaggedAt')->where('status', 'open')
                            ->where('departureDate', '>', today()->toDateString())->whereNotIn('availabilityId', $busy)->count();
                    }
                    if (Schema::hasTable('tbl_departure_groups') && Schema::hasColumn('tbl_departure_groups', 'shortfallFlaggedAt')) {
                        $c['shortfall'] += (int) DB::table('tbl_departure_groups')->whereNotNull('shortfallFlaggedAt')->where('status', 'active')
                            ->where('departureDate', '>', today()->toDateString())->count();
                    }
                }
                if (Schema::hasTable('tbl_waitlist')) {
                    $c['waitlist'] = (int) DB::table('tbl_waitlist')->where('status', 'waiting')->count();
                }
                if (Schema::hasTable('tbl_booking_adjustments')) {
                    $c['adjust'] = (int) DB::table('tbl_booking_adjustments')->whereIn('moneyStatus', ['pending_collect', 'pending_refund'])->count();
                }
                if (Schema::hasTable('tbl_tour_quotes')) {
                    $c['quotes'] = (int) DB::table('tbl_tour_quotes')->where('status', 'open')->count();
                }
                if (Schema::hasTable('tbl_place_reports')) {
                    $c['placeReports'] = (int) DB::table('tbl_place_reports')->where('status', 'open')->count();
                }
                if (Schema::hasTable('tbl_complaints')) {
                    $c['complaint'] = (int) DB::table('tbl_complaints')->whereIn('status', ['open', 'investigating'])->count();
                }
                if (Schema::hasTable('tbl_tour_incidents')) {
                    $c['incident'] = (int) DB::table('tbl_tour_incidents')->whereIn('status', ['open', 'handling'])->count();
                }
                if (Schema::hasTable('tbl_reconciliation_findings')) {
                    $c['reconcile'] = (int) DB::table('tbl_reconciliation_findings')->where('status', 'open')->whereIn('severity', ['high', 'medium'])->count();
                }
                if (Schema::hasTable('tbl_support_tickets')) {
                    $c['support'] = (int) DB::table('tbl_support_tickets')->whereIn('status', ['open', 'in_progress'])->count();
                }
                if (Schema::hasTable('tbl_contact_messages')) {
                    $c['contact'] = (int) DB::table('tbl_contact_messages')->where('status', 'new')->count();
                }
                foreach (['tbl_foods', 'tbl_experiences', 'tbl_events', 'tbl_guides', 'tbl_itineraries'] as $t) {
                    if (!Schema::hasTable($t) || !Schema::hasColumn($t, 'reviewStatus')) {
                        continue;
                    }
                    $c['reviewed'] += (int) DB::table($t)->where('reviewStatus', 'reviewed')->count();
                    $c['draft'] += (int) DB::table($t)->where('reviewStatus', 'draft')->count();
                }
            } catch (\Throwable $e) {
                report($e);
            }
            // Bước "Đã kiểm tra" không còn trong giao diện: mọi bài chưa xuất bản đều tính là chờ xử lý.
            $c['pending'] = $c['draft'] + $c['reviewed'];

            return $c;
        });
    }

    public static function forget(): void
    {
        Cache::forget(self::KEY);
    }
}
