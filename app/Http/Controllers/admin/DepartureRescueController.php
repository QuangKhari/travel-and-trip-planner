<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Mail\TemplateMail;
use App\Services\DepartureGroupingService;
use App\Services\DepartureRescueService;
use App\Support\AdminBadges;
use App\Support\AuditLog;
use App\Support\BookingPii;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class DepartureRescueController extends Controller
{
    public function index(DepartureRescueService $service, DepartureGroupingService $grouping)
    {
        $title='Xử lý tour thiếu khách';
        $ready=$service->ready();
        $departures=collect(); $plans=collect(); $resources=collect(); $alerts=collect(); $groupAlerts=collect();
        if ($ready) {
            $resources=DB::table('tbl_rescue_resources')->where('active',true)->orderBy('name')->get();
            $departures=DB::table('tbl_tour_availability as a')->join('tbl_tours as t','t.tourId','=','a.tourId')
                ->where('a.departureDate','>',today()->toDateString())->orderBy('a.departureDate')
                ->limit(250)->get(['a.*','t.title']);
            $plans=DB::table('tbl_departure_rescue_plans as p')->join('tbl_tour_availability as a','a.availabilityId','=','p.availabilityId')
                ->join('tbl_tours as t','t.tourId','=','a.tourId')->orderByDesc('p.planId')->limit(150)
                ->get(['p.*','t.title','a.departureDate']);
            foreach($plans as $p){
                $p->counts=DB::table('tbl_departure_rescue_responses')->where('planId',$p->planId)
                    ->selectRaw('decision, COUNT(*) as total')->groupBy('decision')->pluck('total','decision');
                $p->segments=DB::table('tbl_departure_rescue_segments')->where('planId',$p->planId)->get();
            }
            // Lịch đã được lệnh departures:check-shortfall gắn cờ và chưa có phương án đang xử lý
            if (Schema::hasColumn('tbl_tour_availability','shortfallFlaggedAt')) {
                $busy=DB::table('tbl_departure_rescue_plans')->whereIn('status',['pending','ready'])->select('availabilityId');
                $alerts=DB::table('tbl_tour_availability as a')->join('tbl_tours as t','t.tourId','=','a.tourId')
                    ->whereNotNull('a.shortfallFlaggedAt')->where('a.status','open')->where('a.departureDate','>',today()->toDateString())
                    ->whereNotIn('a.availabilityId',$busy)->orderBy('a.departureDate')->limit(50)->get(['a.*','t.title']);
                foreach($alerts as $a){
                    $a->mergeStrict=count($grouping->candidates((int)$a->availabilityId,'strict'));
                    $a->mergeTitles=count($grouping->candidates((int)$a->availabilityId,'titles'));
                }
            }
            // Đoàn ghép có TỔNG khách thấp hơn mức tối thiểu của đoàn (lịch thành viên không bị gắn cờ riêng lẻ)
            if (Schema::hasColumn('tbl_departure_groups','shortfallFlaggedAt')) {
                $groupAlerts=DB::table('tbl_departure_groups')->whereNotNull('shortfallFlaggedAt')->where('status','active')
                    ->where('departureDate','>',today()->toDateString())->orderBy('departureDate')->limit(30)->get();
                foreach($groupAlerts as $g){
                    $g->stats=$grouping->groupStats((int)$g->groupId);
                    $g->members=DB::table('tbl_departure_group_members as m')->join('tbl_tour_availability as a','a.availabilityId','=','m.availabilityId')
                        ->join('tbl_tours as t','t.tourId','=','a.tourId')->where('m.groupId',$g->groupId)
                        ->get(['a.availabilityId','a.bookedSlots','a.minParticipants','a.status','t.title']);
                }
            }
        }
        return view('admin.departures.rescue',compact('title','ready','departures','plans','resources','alerts','groupAlerts'));
    }

    public function store(Request $request, DepartureRescueService $service)
    {
        $data=$request->validate([
            'availabilityId'=>'required|integer|exists:tbl_tour_availability,availabilityId',
            'type'=>'required|in:partial,date_change,tour_change,keep,cancel_review',
            'declinePolicy'=>'nullable|in:reject_all,refund_decliners',
            'targetAvailabilityId'=>'required_if:type,partial,date_change,tour_change|nullable|integer|exists:tbl_tour_availability,availabilityId',
            'fromDay'=>'required_if:type,partial|nullable|integer|min:1|max:90',
            'toDay'=>'required_if:type,partial|nullable|integer|min:1|max:90',
            'resourceId'=>'required_if:type,partial|nullable|integer|exists:tbl_rescue_resources,resourceId',
            'sharedService'=>'required_if:type,partial|nullable|in:transport,guide,activity',
            'operationalDetails'=>'required_if:type,partial|nullable|string|min:20|max:2000',
            'description'=>'required|string|min:30|max:3000',
            'serviceGuarantee'=>'required|string|min:20|max:2000',
            'changeSummary'=>'required|string|min:15|max:1000',
            'responseDeadline'=>'required|date|after:now',
        ]);
        $id=$service->create($data);
        AuditLog::record('departure.rescue.proposal','departure_rescue',$id,'Tạo phương án xử lý thiếu khách');
        AdminBadges::forget();
        return redirect()->route('admin.departures.rescue')->with('success','Đã tạo phương án #'.$id.'. Email sẽ được đưa vào hàng đợi khi chạy lệnh departures:rescue-notify.');
    }

    public function apply(int $id, DepartureRescueService $service)
    {
        $res=$service->apply($id,(int)session('adminId'));
        AuditLog::record('departure.rescue.apply','departure_rescue',$id,'Áp dụng phương án đã được khách đồng ý'.($res['declinedCancelled'] ? '; hủy '.count($res['declinedCancelled']).' đơn của khách không đồng ý và mở '.$res['refunds'].' hồ sơ hoàn 100%' : ''));
        AdminBadges::forget();
        $msg='Đã ghi nhận phương án #'.$id.'.';
        if ($res['declinedCancelled']) {
            $msg.=' Đã hủy '.count($res['declinedCancelled']).' đơn của khách không đồng ý'.($res['refunds'] ? ' và tạo '.$res['refunds'].' yêu cầu hoàn 100% (chờ kế toán, chưa chuyển tiền thật)' : ' (chưa thanh toán nên không có hoàn tiền)').'.';
        }
        return back()->with('success',$msg);
    }

    public function addResource(Request $request)
    {
        $data=$request->validate(['name'=>'required|string|max:150','kind'=>'required|in:transport,guide,activity','capacity'=>'required|integer|min:1|max:10000']);
        $id=DB::table('tbl_rescue_resources')->insertGetId($data+['active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        AuditLog::record('rescue.resource.create','rescue_resource',$id,'Đăng ký tài nguyên vận hành');
        return back()->with('success','Đã đăng ký tài nguyên #'.$id);
    }

    /** Travela hủy khởi hành vì thiếu khách: không cần khách đồng ý, hoàn 100% cho đơn đã thanh toán. */
    public function refund(int $id, DepartureRescueService $service)
    {
        $res=$service->cancelDeparture($id,(int)session('adminId'));
        AuditLog::record('rescue.refund.request','departure_rescue',$id,'Hủy khởi hành: '.$res['cancelled'].' đơn bị hủy, '.$res['refunds'].' hồ sơ hoàn 100%'.($res['groupReleased'] ? '; giải thể đoàn #'.$res['groupReleased']['groupId'] : ''));
        AdminBadges::forget();
        $this->mailCancelled($res['bookingIds'],$id);
        return back()->with('success','Đã hủy '.$res['cancelled'].' đơn và tạo '.$res['refunds'].' yêu cầu hoàn 100% chờ kế toán xử lý; chưa chuyển tiền thật.'.($res['groupReleased'] ? ' Đoàn chung #'.$res['groupReleased']['groupId'].' đã được giải thể.' : ''));
    }

    public function cancel(int $id)
    {
        DB::transaction(function()use($id){
            $p=DB::table('tbl_departure_rescue_plans')->where('planId',$id)->lockForUpdate()->first();
            abort_if(!$p || !in_array($p->status,['pending','ready'],true),422,'Không thể hủy phương án.');
            DB::table('tbl_departure_rescue_plans')->where('planId',$id)->update(['status'=>'cancelled','updatedAt'=>now()]);
        });
        AuditLog::record('departure.rescue.cancel','departure_rescue',$id,'Hủy phương án');
        AdminBadges::forget();
        return back()->with('success','Đã hủy phương án. Booking chưa thay đổi.');
    }

    /** Email "Travela hủy khởi hành" cho mọi đơn bị hủy (kể cả đơn giữ chỗ chưa có phản hồi trong phương án). */
    private function mailCancelled(array $bookingIds, int $planId): void
    {
        if ($bookingIds === []) return;
        try {
            $rows=DB::table('tbl_booking as b')->join('tbl_tours as t','t.tourId','=','b.tourId')
                ->leftJoin('tbl_refunds as r','r.bookingId','=','b.bookingId')
                ->whereIn('b.bookingId',$bookingIds)
                ->get(['b.bookingId','b.bookingCode','b.email','b.departureDate','t.title','r.amount as refundAmount','r.percent as refundPercent']);
            foreach($rows as $row){
                $row=BookingPii::decrypt($row);
                if(!filter_var($row->email,FILTER_VALIDATE_EMAIL)) continue;
                Mail::to($row->email)->queue(new TemplateMail('admin.emails.departure-cancelled',[
                    'bookingCode'=>$row->bookingCode,'tourTitle'=>$row->title,'departureDate'=>$row->departureDate,
                    'refundAmount'=>$row->refundAmount!==null ? (int)$row->refundAmount : null,'refundPercent'=>$row->refundPercent,
                    'url'=>route('my-tours'),
                ],'Travela — khởi hành '.$row->bookingCode.' bị hủy, quyền lợi hoàn tiền'));
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
