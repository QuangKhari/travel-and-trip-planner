<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\DepartureRescueService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DepartureRescueResponseController extends Controller
{
    public function index(Request $request, DepartureRescueService $service)
    {
        abort_unless($service->ready(),404);
        $title='Phản hồi điều chỉnh chuyến đi';
        $items=DB::table('tbl_departure_rescue_responses as r')
            ->join('tbl_booking as b','b.bookingId','=','r.bookingId')
            ->join('tbl_departure_rescue_plans as p','p.planId','=','r.planId')
            ->join('tbl_tours as t','t.tourId','=','b.tourId')
            ->where('b.userId',(int)$request->session()->get('userId'))
            ->orderByDesc('p.planId')->get(array_merge(['r.*','p.type','p.status','p.description','p.serviceGuarantee','p.changeSummary','p.responseDeadline','p.targetAvailabilityId','t.title','b.bookingCode','b.departureDate'],Schema::hasColumn('tbl_departure_rescue_plans','declinePolicy') ? ['p.declinePolicy'] : []));
        return view('clients.departure-rescue',compact('title','items'));
    }

    public function respond(Request $request,int $planId,int $bookingId,DepartureRescueService $service)
    {
        $data=$request->validate(['decision'=>'required|in:accepted,declined']);
        $service->respond($planId,$bookingId,(int)$request->session()->get('userId'),$data['decision']);
        return redirect()->route('departure.rescue.responses')->with('success','Đã ghi nhận ý kiến của bạn. Chỉ khi đủ điều kiện, Admin mới áp dụng phương án.');
    }
}
