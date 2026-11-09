<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

/**
 * Rescue operations: consent is a prerequisite, never an implicit booking rewrite.
 *
 * Chính sách khi khách từ chối (cột declinePolicy):
 *   reject_all        một khách từ chối thì cả phương án bị hủy (hành vi cũ, mặc định).
 *   refund_decliners  ai từ chối / không trả lời đến hạn thì bị hủy đơn + hoàn 100%; những khách đồng ý vẫn được áp dụng phương án.
 * Phương án "cancel_review" (Travela hủy khởi hành) KHÔNG cần khách đồng ý: khách chỉ được thông báo, mọi đơn bị hủy đều được hoàn 100% nếu đã trả tiền.
 */
class DepartureRescueService
{
    public const POLICIES = ['reject_all', 'refund_decliners'];
    /** Trạng thái phương án hủy còn được phép thực hiện hủy khởi hành. */
    private const CANCELABLE = ['pending', 'ready', 'manual_review', 'rejected', 'expired'];

    public function ready(): bool { return Schema::hasTable('tbl_departure_rescue_plans'); }

    private function hasPolicyColumn(): bool { return Schema::hasColumn('tbl_departure_rescue_plans', 'declinePolicy'); }

    /** Giá niêm yết đã bán của đơn = tiền khách phải trả + số tiền mã giảm giá đã trừ (không tính lại theo giá hiện tại của tour). */
    private function listPrice(object $booking): int
    {
        $discount = Schema::hasTable('tbl_promotion_usage')
            ? (float) DB::table('tbl_promotion_usage')->where('bookingId', $booking->bookingId)->sum('discountAmount')
            : 0.0;

        return (int) round((float) $booking->totalPrice + $discount);
    }

    public function create(array $data): int
    {
        return DB::transaction(function () use ($data) {
            $source = DB::table('tbl_tour_availability')->where('availabilityId', $data['availabilityId'])->lockForUpdate()->first();
            if (!$source || $source->departureDate <= today()->toDateString()) throw ValidationException::withMessages(['availabilityId'=>'Ngày khởi hành không hợp lệ hoặc đã qua.']);
            if (DB::table('tbl_departure_rescue_plans')->where('availabilityId', $source->availabilityId)->whereIn('status', ['pending','ready'])->exists()) {
                throw ValidationException::withMessages(['availabilityId'=>'Đã có phương án đang xin ý kiến cho lịch này.']);
            }
            $type = $data['type'];
            $policy = (string) ($data['declinePolicy'] ?? 'reject_all');
            if (!in_array($policy, self::POLICIES, true)) {
                throw ValidationException::withMessages(['declinePolicy'=>'Chính sách khi khách từ chối không hợp lệ.']);
            }
            if ($policy === 'refund_decliners') {
                if (!in_array($type, ['date_change','partial','tour_change'], true)) {
                    throw ValidationException::withMessages(['declinePolicy'=>'Hoàn tiền cho khách từ chối chỉ áp dụng cho đổi ngày, đổi tour và ghép chặng.']);
                }
                if (!$this->hasPolicyColumn()) {
                    throw ValidationException::withMessages(['declinePolicy'=>'Chưa chạy migration 2026_11_27_000001.']);
                }
            }
            $target = null;
            if (in_array($type, ['date_change','partial','tour_change'], true)) {
                $target = DB::table('tbl_tour_availability')->where('availabilityId', $data['targetAvailabilityId'])->lockForUpdate()->first();
                if (!$target || $target->departureDate <= today()->toDateString() || $target->status !== 'open' || $target->availabilityId === $source->availabilityId) {
                    throw ValidationException::withMessages(['targetAvailabilityId'=>'Lịch đối tác không hợp lệ.']);
                }
                if ($type === 'tour_change') {
                    if ((int)$source->tourId === (int)$target->tourId) throw ValidationException::withMessages(['targetAvailabilityId'=>'Phải chọn sản phẩm tour khác.']);
                    $people=DB::table('tbl_booking')->where('availabilityId',$source->availabilityId)->whereIn('bookingStatus',['n','y'])->sum(DB::raw('numAdults + numChildren'));
                    if (DepartureService::remaining($target)<$people) throw ValidationException::withMessages(['targetAvailabilityId'=>'Tour mới không đủ số chỗ.']);
                    $newTour=DB::table('tbl_tours')->where('tourId',$target->tourId)->first();
                    foreach(DB::table('tbl_booking')->where('availabilityId',$source->availabilityId)->where('bookingStatus','y')->get() as $book) {
                        $quote=(int)round($book->numAdults*$newTour->priceAdult+$book->numChildren*$newTour->priceChild);
                        if ($quote !== $this->listPrice($book)) throw ValidationException::withMessages(['targetAvailabilityId'=>'Giá niêm yết của sản phẩm mới khác giá đã bán cho đơn '.$book->bookingCode.' (đã tính cả mã giảm giá); cần đối soát/hoàn chênh lệch thủ công, không thể đổi tour tự động.']);
                    }
                } elseif ($type === 'date_change') {
                    if ($source->tourId !== $target->tourId || $source->departureDate === $target->departureDate) throw ValidationException::withMessages(['targetAvailabilityId'=>'Chỉ chuyển sang ngày khác của chính sản phẩm tour, không đổi tour hoặc giá.']);
                    $people = DB::table('tbl_booking')->where('availabilityId', $source->availabilityId)->whereIn('bookingStatus', ['n','y'])->sum(DB::raw('numAdults + numChildren'));
                    if (DepartureService::remaining($target) < $people) throw ValidationException::withMessages(['targetAvailabilityId'=>'Không đủ chỗ cho toàn bộ khách của lịch gốc.']);
                } else {
                    if ($source->departureDate !== $target->departureDate) throw ValidationException::withMessages(['targetAvailabilityId'=>'Ghép chặng V2 chỉ hỗ trợ cùng ngày khởi hành.']);
                    $from = (int) $data['fromDay']; $to = (int) $data['toDay'];
                    $sourceTour = DB::table('tbl_tours')->where('tourId', $source->tourId)->first();
                    $partnerTour = DB::table('tbl_tours')->where('tourId', $target->tourId)->first();
                    if (!$sourceTour || !$partnerTour || $from < 1 || $to < $from || $to > min(DepartureService::durationDays($sourceTour), DepartureService::durationDays($partnerTour))) {
                        throw ValidationException::withMessages(['fromDay'=>'Phạm vi chặng vượt quá thời lượng của một trong hai tour.']);
                    }
                }
            }
            $deadline = Carbon::parse($data['responseDeadline']);
            if ($deadline->lte(now()) || $deadline->gte(Carbon::parse($source->departureDate)->startOfDay()) || ($target && $deadline->gte(Carbon::parse($target->departureDate)->startOfDay()))) throw ValidationException::withMessages(['responseDeadline'=>'Hạn trả lời phải sau hiện tại và trước ngày khởi hành của cả hai lịch.']);
            if (in_array($type, ['date_change','partial','tour_change'], true) && DB::table('tbl_departure_group_members')->whereIn('availabilityId', [$source->availabilityId, $target->availabilityId])->exists()) {
                throw ValidationException::withMessages(['availabilityId'=>'Lịch đã thuộc đoàn ghép toàn bộ V1; phải tách đoàn trước khi lập đề xuất.']);
            }
            $itineraryHash=null;
            if ($type === 'partial') {
                $itineraryHash=app(RescueOperationalService::class)->segmentHash((int)$source->tourId,(int)$target->tourId,(int)$data['fromDay'],(int)$data['toDay']);
                if (empty($data['resourceId'])) throw ValidationException::withMessages(['resourceId'=>'Phải chọn tài nguyên để xác minh sức chứa và xung đột.']);
            }
            $row = [
                'availabilityId'=>$source->availabilityId,'type'=>$type,'status'=>'pending','itineraryHash'=>$itineraryHash,
                'targetAvailabilityId'=>$target?->availabilityId,'responseDeadline'=>$deadline,
                'description'=>$data['description'],'serviceGuarantee'=>$data['serviceGuarantee'],
                'changeSummary'=>$data['changeSummary'],'createdAt'=>now(),'updatedAt'=>now(),
            ];
            if ($this->hasPolicyColumn()) $row['declinePolicy'] = $policy;
            $id = DB::table('tbl_departure_rescue_plans')->insertGetId($row);
            if ($type === 'partial') DB::table('tbl_departure_rescue_segments')->insert([
                'planId'=>$id,'partnerAvailabilityId'=>$target->availabilityId,'resourceId'=>(int)$data['resourceId'],'fromDay'=>(int)$data['fromDay'],
                'toDay'=>(int)$data['toDay'],'sharedService'=>$data['sharedService'],
                'operationalDetails'=>$data['operationalDetails'],'createdAt'=>now(),
            ]);
            // Only confirmed, paid/office-confirmed bookings receive change requests; pending holds are not silently altered.
            $affectedIds = $type === 'partial' ? [$source->availabilityId, $target->availabilityId] : [$source->availabilityId];
            $ids = DB::table('tbl_booking')->whereIn('availabilityId', $affectedIds)->where('bookingStatus', 'y')->pluck('bookingId');
            foreach ($ids as $bookingId) {
                $responseId = DB::table('tbl_departure_rescue_responses')->insertGetId(['planId'=>$id,'bookingId'=>$bookingId,'decision'=>'pending','createdAt'=>now()]);
                DB::table('tbl_departure_rescue_outbox')->insert(['responseId'=>$responseId,'event'=>'proposal','createdAt'=>now()]);
            }
            if ($ids->isEmpty()) DB::table('tbl_departure_rescue_plans')->where('planId',$id)->update(['status'=>'ready']);
            return $id;
        }, 3);
    }

    public function respond(int $planId, int $bookingId, int $userId, string $decision): void
    {
        DB::transaction(function () use ($planId, $bookingId, $userId, $decision) {
            $plan = DB::table('tbl_departure_rescue_plans')->where('planId',$planId)->lockForUpdate()->first();
            $booking = DB::table('tbl_booking')->where('bookingId',$bookingId)->where('userId',$userId)->first();
            if (!$plan || !$booking || !in_array((int)$booking->availabilityId, $plan->type === 'partial' ? [(int)$plan->availabilityId,(int)$plan->targetAvailabilityId] : [(int)$plan->availabilityId], true) || $plan->status !== 'pending' || now()->greaterThan($plan->responseDeadline) || $booking->bookingStatus !== 'y') {
                throw ValidationException::withMessages(['plan'=>'Phương án không còn hiệu lực cho booking này.']);
            }
            $response = DB::table('tbl_departure_rescue_responses')->where('planId',$planId)->where('bookingId',$bookingId)->lockForUpdate()->first();
            if (!$response || $response->decision !== 'pending') throw ValidationException::withMessages(['plan'=>'Bạn đã phản hồi hoặc yêu cầu không tồn tại.']);
            DB::table('tbl_departure_rescue_responses')->where('responseId',$response->responseId)->update(['decision'=>$decision,'respondedAt'=>now()]);
            DB::table('tbl_departure_rescue_outbox')->insertOrIgnore(['responseId'=>$response->responseId,'event'=>'response_received','createdAt'=>now()]);
            $policy = $plan->declinePolicy ?? 'reject_all';
            // cancel_review chỉ là thông báo: ý kiến của khách không chặn việc Travela hủy khởi hành.
            if ($decision === 'declined' && $plan->type !== 'cancel_review' && $policy !== 'refund_decliners') {
                DB::table('tbl_departure_rescue_plans')->where('planId',$planId)->update(['status'=>'rejected','updatedAt'=>now()]);
            } elseif (!DB::table('tbl_departure_rescue_responses')->where('planId',$planId)->where('decision','pending')->exists()) {
                $anyAccepted = DB::table('tbl_departure_rescue_responses')->where('planId',$planId)->where('decision','accepted')->exists();
                $dead = $policy === 'refund_decliners' && $plan->type !== 'cancel_review' && !$anyAccepted;
                DB::table('tbl_departure_rescue_plans')->where('planId',$planId)->update(['status'=>$dead ? 'rejected' : 'ready','updatedAt'=>now()]);
            }
        }, 3);
    }

    /**
     * Áp dụng phương án đã đủ phản hồi. Trả về danh sách booking bị hủy + hoàn 100% vì khách từ chối (chỉ khi declinePolicy = refund_decliners)
     * để controller gửi email.
     * @return array{declinedCancelled:int[], refunds:int}
     */
    public function apply(int $planId, ?int $adminId = null): array
    {
        return DB::transaction(function () use ($planId, $adminId) {
            $plan = DB::table('tbl_departure_rescue_plans')->where('planId',$planId)->lockForUpdate()->first();
            // 'ready' nghĩa là mọi khách đã trả lời; sau hạn phản hồi vẫn áp dụng được, miễn các điều kiện vận hành bên dưới còn đúng.
            if (!$plan || $plan->status !== 'ready') throw ValidationException::withMessages(['plan'=>'Phương án chưa đủ xác nhận hoặc đã hết hạn.']);
            $refundDecliners = ($plan->declinePolicy ?? 'reject_all') === 'refund_decliners' && $plan->type !== 'cancel_review';
            if ($plan->type !== 'cancel_review') {
                $badDecision = $refundDecliners ? ['pending'] : ['declined','timeout','pending'];
                if (DB::table('tbl_departure_rescue_responses')->where('planId',$planId)->whereIn('decision',$badDecision)->exists()) throw ValidationException::withMessages(['plan'=>'Chưa có đồng ý của tất cả khách.']);
            }
            // Lock inventory BEFORE recomputing consent. New bookings must not race with application.
            $availabilityIds=$plan->type === 'partial' ? [$plan->availabilityId,$plan->targetAvailabilityId] : [$plan->availabilityId];
            if (in_array($plan->type,['date_change','tour_change'],true)) $availabilityIds[]=$plan->targetAvailabilityId;
            $availabilityIds=array_values(array_unique(array_map('intval',$availabilityIds)));
            $tourIds=DB::table('tbl_tour_availability')->whereIn('availabilityId',$availabilityIds)->pluck('tourId')->map(fn($v)=>(int)$v)->unique()->sort()->values()->all();
            DB::table('tbl_tours')->whereIn('tourId',$tourIds)->orderBy('tourId')->lockForUpdate()->get();
            DB::table('tbl_tour_availability')->whereIn('availabilityId',$availabilityIds)->orderBy('availabilityId')->lockForUpdate()->get();
            // New confirmed bookings since proposal must also agree: fail closed.
            $affectedIds=$plan->type === 'partial' ? [$plan->availabilityId,$plan->targetAvailabilityId] : [$plan->availabilityId];
            $current = DB::table('tbl_booking')->whereIn('availabilityId',$affectedIds)->where('bookingStatus','y')->pluck('bookingId')->map(fn($v)=>(int)$v)->sort()->values()->all();
            $accepted = DB::table('tbl_departure_rescue_responses')->where('planId',$planId)->where('decision','accepted')->pluck('bookingId')->map(fn($v)=>(int)$v)->all();
            $declined = DB::table('tbl_departure_rescue_responses')->where('planId',$planId)->whereIn('decision',['declined','timeout'])->pluck('bookingId')->map(fn($v)=>(int)$v)->all();
            $declinedCurrent = [];
            if ($plan->type !== 'cancel_review') {
                // Đơn đã tự hủy sau khi trả lời thì bỏ qua; mọi đơn còn lại phải có phản hồi hợp lệ (đơn mới đặt = chưa có ý kiến -> chặn).
                $covered = $refundDecliners ? array_merge($accepted, $declined) : $accepted;
                if (array_diff($current, $covered) !== []) throw ValidationException::withMessages(['plan'=>'Danh sách khách thay đổi: phải tạo đề xuất mới để lấy đủ đồng ý.']);
                if ($refundDecliners) {
                    if (array_intersect($current, $accepted) === []) throw ValidationException::withMessages(['plan'=>'Không còn khách nào đồng ý; hãy dùng phương án hủy khởi hành thay vì áp dụng.']);
                    $declinedCurrent = array_values(array_intersect($current, $declined));
                }
            }
            $summary = ['declinedCancelled' => [], 'refunds' => 0];
            if ($declinedCurrent !== []) {
                // Khách không đồng ý: hủy đơn và hoàn 100% (chưa thanh toán thì chỉ hủy). Làm TRƯỚC khi chuyển để số chỗ đã trả lại đúng.
                $rows = DB::table('tbl_booking')->whereIn('bookingId',$declinedCurrent)->where('bookingStatus','y')->orderBy('bookingId')->lockForUpdate()->get();
                foreach ($rows as $bk) {
                    $co = DB::table('tbl_checkout')->where('bookingId',$bk->bookingId)->lockForUpdate()->first();
                    $paid = $co && $co->paymentStatus === 'y';
                    app(BookingService::class)->cancelLocked($bk,!empty($plan->incidentId) ? 'incident' : 'shortfall',!$paid);
                    if ($paid) {
                        app(RefundService::class)->requestLocked($bk,'admin',$adminId,100,'Travela điều chỉnh khởi hành, khách không đồng ý — hoàn 100% (phương án #'.$planId.')',$planId);
                        $summary['refunds']++;
                    }
                    $summary['declinedCancelled'][] = (int)$bk->bookingId;
                }
            }
            if ($plan->type === 'partial') {
                $a=DB::table('tbl_tour_availability')->where('availabilityId',$plan->availabilityId)->first();
                $b=DB::table('tbl_tour_availability')->where('availabilityId',$plan->targetAvailabilityId)->first();
                if (!$a || !$b || $a->departureDate !== $b->departureDate || $a->departureDate <= today()->toDateString()) throw ValidationException::withMessages(['plan'=>'Hai lịch ghép chặng không còn tương thích.']);
                if (DB::table('tbl_departure_group_members')->whereIn('availabilityId',[$a->availabilityId,$b->availabilityId])->exists()) throw ValidationException::withMessages(['plan'=>'Lịch đã thuộc đoàn chung khác.']);
                if ($a->status !== 'closed' || $b->status !== 'closed' || (int)$a->heldSlots !== 0 || (int)$b->heldSlots !== 0) {
                    throw ValidationException::withMessages(['plan'=>'Để áp dụng ghép chặng, Admin phải đóng bán cả hai lịch và xử lý hết đơn đang giữ chỗ.']);
                }
                app(RescueOperationalService::class)->reservePartial($plan);
            }
            if ($plan->type === 'tour_change') {
                $s=DB::table('tbl_tour_availability')->where('availabilityId',$plan->availabilityId)->first();
                $t=DB::table('tbl_tour_availability')->where('availabilityId',$plan->targetAvailabilityId)->first();
                if (!$s || !$t || $s->tourId === $t->tourId || $t->status !== 'open' || $t->departureDate <= today()->toDateString()) throw ValidationException::withMessages(['plan'=>'Tour thay thế không còn hợp lệ.']);
                if (DB::table('tbl_departure_group_members')->whereIn('availabilityId',[$s->availabilityId,$t->availabilityId])->exists()) throw ValidationException::withMessages(['plan'=>'Có đoàn ghép V1 đang hoạt động.']);
                if ((int)$s->heldSlots !== 0) throw ValidationException::withMessages(['plan'=>'Còn đơn giữ chỗ tại lịch cũ.']);
                $bookings=DB::table('tbl_booking')->where('availabilityId',$s->availabilityId)->where('bookingStatus','y')->orderBy('bookingId')->lockForUpdate()->get();
                $people=(int)$bookings->sum(fn($b)=>(int)$b->numAdults+(int)$b->numChildren);
                if ($people !== (int)$s->bookedSlots || DepartureService::remaining($t)<$people) throw ValidationException::withMessages(['plan'=>'Số lượng khách hoặc sức chứa đã thay đổi.']);
                $newTour=DB::table('tbl_tours')->where('tourId',$t->tourId)->first();
                foreach($bookings as $b) {
                    $quote=(int)round($b->numAdults*$newTour->priceAdult+$b->numChildren*$newTour->priceChild);
                    // So với giá niêm yết đã bán (tiền đã trả + mã giảm giá), nên đơn có coupon vẫn chuyển được nếu giá niêm yết không đổi.
                    if ($quote !== $this->listPrice($b)) throw ValidationException::withMessages(['plan'=>'Có chênh lệch giá niêm yết; yêu cầu đối soát thủ công, không được chuyển booking.']);
                    $checkout=DB::table('tbl_checkout')->where('bookingId',$b->bookingId)->lockForUpdate()->first();
                    if (!$checkout || $checkout->paymentStatus !== 'y') throw ValidationException::withMessages(['plan'=>'Đơn chưa được đối soát thanh toán; không thể chuyển sang sản phẩm khác.']);
                    if (DB::table('tbl_refunds')->where('bookingId',$b->bookingId)->exists()) throw ValidationException::withMessages(['plan'=>'Booking đang có hồ sơ hoàn tiền.']);
                    DB::table('tbl_rescue_transfers')->insert(['planId'=>$planId,'bookingId'=>$b->bookingId,'oldTourId'=>$s->tourId,'newTourId'=>$t->tourId,'oldAvailabilityId'=>$s->availabilityId,'newAvailabilityId'=>$t->availabilityId,'oldTotal'=>$b->totalPrice,'newTotal'=>$b->totalPrice,'createdAt'=>now()]);
                    DB::table('tbl_booking')->where('bookingId',$b->bookingId)->update(['tourId'=>$t->tourId,'availabilityId'=>$t->availabilityId,'departureDate'=>$t->departureDate]);
                }
                if (TourVersionService::ready() && ($newVersionId=app(TourVersionService::class)->ensureCurrent((int)$t->tourId))) {
                    // khách đã đồng ý đổi sang tour này: nội dung họ nhận là phiên bản hiện tại của tour mới
                    DB::table('tbl_booking')->whereIn('bookingId',$bookings->pluck('bookingId')->all())->update(['tourVersionId'=>$newVersionId]);
                }
                DB::table('tbl_tour_availability')->where('availabilityId',$t->availabilityId)->increment('bookedSlots',$people,['updatedAt'=>now()]);
                DB::table('tbl_tour_availability')->where('availabilityId',$s->availabilityId)->decrement('bookedSlots',$people,['updatedAt'=>now()]);
                DB::table('tbl_tour_availability')->where('availabilityId',$s->availabilityId)->update(['status'=>'closed','updatedAt'=>now()]);
                app(DepartureService::class)->syncTour((int)$s->tourId);
                app(DepartureService::class)->syncTour((int)$t->tourId);
            }
            if ($plan->type === 'date_change') {
                // Serialize with booking operations via tour row, then both departure rows in ascending order.
                $s = DB::table('tbl_tour_availability')->where('availabilityId',$plan->availabilityId)->first();
                $t = DB::table('tbl_tour_availability')->where('availabilityId',$plan->targetAvailabilityId)->first();
                if (!$s || !$t || $s->tourId !== $t->tourId) throw ValidationException::withMessages(['plan'=>'Lịch đã thay đổi hoặc không hợp lệ.']);
                if (DB::table('tbl_departure_group_members')->whereIn('availabilityId',[$s->availabilityId,$t->availabilityId])->exists()) throw ValidationException::withMessages(['plan'=>'Ngày đã thuộc đoàn V1 khác.']);
                DB::table('tbl_tours')->where('tourId',$s->tourId)->lockForUpdate()->first();
                $rows = DB::table('tbl_tour_availability')->whereIn('availabilityId',[$s->availabilityId,$t->availabilityId])->orderBy('availabilityId')->lockForUpdate()->get()->keyBy('availabilityId');
                $source=$rows[$s->availabilityId]; $target=$rows[$t->availabilityId];
                if ($source->departureDate <= today()->toDateString() || $target->departureDate <= today()->toDateString() || $target->status !== 'open') throw ValidationException::withMessages(['plan'=>'Ngày khởi hành không còn hợp lệ.']);
                // Never move pending holds: they would need separate consent; prevent use until resolved.
                if ((int)$source->heldSlots !== 0) throw ValidationException::withMessages(['plan'=>'Còn đơn đang giữ chỗ; cần xử lý trước khi chuyển lịch.']);
                $people = DB::table('tbl_booking')->where('availabilityId',$source->availabilityId)->where('bookingStatus','y')->sum(DB::raw('numAdults + numChildren'));
                if ($people !== (int)$source->bookedSlots || DepartureService::remaining($target) < $people) throw ValidationException::withMessages(['plan'=>'Số khách hoặc sức chứa đã thay đổi.']);
                DB::table('tbl_tour_availability')->where('availabilityId',$target->availabilityId)->increment('bookedSlots',$people,['updatedAt'=>now()]);
                DB::table('tbl_tour_availability')->where('availabilityId',$source->availabilityId)->decrement('bookedSlots',$people,['updatedAt'=>now()]);
                DB::table('tbl_tour_availability')->where('availabilityId',$source->availabilityId)->update(['status'=>'closed','updatedAt'=>now()]);
                DB::table('tbl_booking')->where('availabilityId',$source->availabilityId)->where('bookingStatus','y')->update(['availabilityId'=>$target->availabilityId,'departureDate'=>$target->departureDate]);
                app(DepartureService::class)->syncTour((int)$source->tourId);
            }
            // 'partial' documents shared operating services only. It never changes customer itinerary or booking.
            // 'keep' / 'cancel_review' are decisions for operations staff; never auto-cancel or auto-refund.
            $finalStatus = $plan->type === 'cancel_review' ? 'manual_review' : 'applied';
            DB::table('tbl_departure_rescue_plans')->where('planId',$planId)->update(['status'=>$finalStatus,'appliedAt'=>now(),'updatedAt'=>now()]);
            if (!empty($plan->incidentId)) IncidentService::log((int)$plan->incidentId,$adminId,'plan','Phương án #'.$planId.' đã được áp dụng ('.$finalStatus.').');
            foreach (DB::table('tbl_departure_rescue_responses')->where('planId',$planId)->get() as $r) {
                $event = $plan->type === 'cancel_review' ? 'manual_review'
                    : (in_array((int)$r->bookingId, $summary['declinedCancelled'], true) ? 'declined_refund' : 'applied');
                DB::table('tbl_departure_rescue_outbox')->insertOrIgnore(['responseId'=>$r->responseId,'event'=>$event,'createdAt'=>now()]);
            }

            return $summary;
        }, 3);
    }

    /**
     * Travela hủy khởi hành vì thiếu khách. KHÔNG cần khách đồng ý (hủy + hoàn 100% không làm khách thiệt):
     *   - mọi đơn n (đang giữ chỗ) và y của lịch đều bị hủy, lý do 'shortfall';
     *   - đơn đã thanh toán -> hồ sơ hoàn 100% ở trạng thái chờ (CHƯA chuyển tiền thật); đơn chưa thanh toán -> chỉ hủy;
     *   - lịch bị đóng, nếu đang thuộc đoàn ghép thì đoàn được giải thể, cờ thiếu khách được xóa.
     * @return array{planId:int, cancelled:int, refunds:int, bookingIds:int[], groupReleased:?array}
     */
    public function cancelDeparture(int $planId, int $adminId): array
    {
        return DB::transaction(function () use ($planId, $adminId) {
            $plan=DB::table('tbl_departure_rescue_plans')->where('planId',$planId)->lockForUpdate()->first();
            if (!$plan || $plan->type !== 'cancel_review' || !in_array($plan->status, self::CANCELABLE, true)) throw ValidationException::withMessages(['plan'=>'Chỉ thực hiện được với phương án "Hủy/hoàn tiền" chưa xử lý.']);
            $source=DB::table('tbl_tour_availability')->where('availabilityId',$plan->availabilityId)->first();
            if (!$source || $source->departureDate <= today()->toDateString()) throw ValidationException::withMessages(['plan'=>'Không hủy theo luồng thiếu khách khi tour đã khởi hành.']);
            DB::table('tbl_tours')->where('tourId',$source->tourId)->lockForUpdate()->first();
            DB::table('tbl_tour_availability')->where('availabilityId',$source->availabilityId)->lockForUpdate()->first();
            $rows=DB::table('tbl_booking')->where('availabilityId',$source->availabilityId)->whereIn('bookingStatus',['n','y'])->orderBy('bookingId')->lockForUpdate()->get();
            $refunds=0; $ids=[];
            foreach($rows as $booking) {
                $checkout=DB::table('tbl_checkout')->where('bookingId',$booking->bookingId)->lockForUpdate()->first();
                $paid=$checkout && $checkout->paymentStatus === 'y';
                app(BookingService::class)->cancelLocked($booking,!empty($plan->incidentId) ? 'incident' : 'shortfall',!$paid);
                if ($paid) {
                    app(RefundService::class)->requestLocked($booking,'admin',$adminId,100,(!empty($plan->incidentId) ? 'Travela hủy khởi hành do sự cố — phương án #' : 'Travela hủy khởi hành do không đủ khách — phương án #').$planId,$planId);
                    $refunds++;
                }
                $ids[]=(int)$booking->bookingId;
            }
            $close=['status'=>'closed','updatedAt'=>now()];
            if (Schema::hasColumn('tbl_tour_availability','shortfallFlaggedAt')) $close['shortfallFlaggedAt']=null;
            DB::table('tbl_tour_availability')->where('availabilityId',$source->availabilityId)->update($close);
            $group=app(DepartureGroupingService::class)->releaseMembership((int)$source->availabilityId);
            app(WaitlistService::class)->closeForAvailability((int)$source->availabilityId);
            app(DepartureService::class)->syncTour((int)$source->tourId);
            DB::table('tbl_departure_rescue_plans')->where('planId',$planId)->update(['status'=>$refunds>0 ? 'refund_pending' : 'completed','appliedAt'=>now(),'updatedAt'=>now()]);
            if (!empty($plan->incidentId)) IncidentService::log((int)$plan->incidentId,$adminId,'plan','Đã hủy khởi hành theo phương án #'.$planId.': '.count($ids).' đơn bị hủy, '.$refunds.' hồ sơ hoàn 100% chờ kế toán.');

            return ['planId'=>$planId,'cancelled'=>count($ids),'refunds'=>$refunds,'bookingIds'=>$ids,'groupReleased'=>$group];
        },3);
    }

    /** Giữ tên cũ cho code/test đang gọi: trả về số đơn đã hủy. */
    public function cancelAndRequestRefunds(int $planId, int $adminId): int
    {
        return $this->cancelDeparture($planId,$adminId)['cancelled'];
    }

    /**
     * Đóng các phương án quá hạn:
     *   - refund_decliners: khách chưa trả lời bị coi là không đồng ý (decision 'timeout'); còn khách đồng ý thì phương án sang 'ready', không thì 'expired';
     *   - reject_all: 'pending' quá hạn -> 'expired'. Phương án 'ready' (đã đủ phản hồi) không bị hết hạn nữa.
     */
    public function expire(): int
    {
        $n = 0;
        if ($this->hasPolicyColumn()) {
            $due = DB::table('tbl_departure_rescue_plans')->where('status','pending')->where('declinePolicy','refund_decliners')
                ->where('responseDeadline','<',now())->pluck('planId');
            foreach ($due as $pid) {
                $n += DB::transaction(function () use ($pid) {
                    $plan = DB::table('tbl_departure_rescue_plans')->where('planId',$pid)->lockForUpdate()->first();
                    if (!$plan || $plan->status !== 'pending' || !now()->greaterThan($plan->responseDeadline)) return 0;
                    $pending = DB::table('tbl_departure_rescue_responses')->where('planId',$pid)->where('decision','pending')->pluck('responseId')->all();
                    if ($pending !== []) {
                        DB::table('tbl_departure_rescue_responses')->whereIn('responseId',$pending)->update(['decision'=>'timeout','respondedAt'=>now()]);
                    }
                    $anyAccepted = DB::table('tbl_departure_rescue_responses')->where('planId',$pid)->where('decision','accepted')->exists();
                    DB::table('tbl_departure_rescue_plans')->where('planId',$pid)->update(['status'=>$anyAccepted ? 'ready' : 'expired','updatedAt'=>now()]);

                    return 1;
                }, 3);
            }
        }

        return $n + DB::table('tbl_departure_rescue_plans')->where('status','pending')->where('responseDeadline','<',now())->update(['status'=>'expired','updatedAt'=>now()]);
    }
}
