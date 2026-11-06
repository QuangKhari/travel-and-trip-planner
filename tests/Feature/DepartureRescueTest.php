<?php

namespace Tests\Feature;

use App\Services\DepartureRescueService;
use App\Services\DepartureService;
use App\Support\BookingPii;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\TemplateMail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DepartureRescueTest extends TestCase
{
    use RefreshDatabase;

    private function departure(int $offset, string $suffix): array
    {
        $date=now()->addDays($offset)->toDateString();
        $tourId=DB::table('tbl_tours')->insertGetId([
            'title'=>'Tour cứu đoàn '.$suffix,'time'=>'3 ngày','description'=>'Hành trình thử nghiệm',
            'quantity'=>10,'priceAdult'=>1000000,'priceChild'=>500000,
            'destination'=>'Sa Pa','domain'=>'b','availability'=>1,
            'startDate'=>$date,'endDate'=>now()->addDays($offset+2)->toDateString(),'durationDays'=>3,
        ]);
        foreach (range(1,3) as $d) DB::table('tbl_timeline')->insert([
            'tourId'=>$tourId,'title'=>'Ngày '.$d, 'description'=>'Lộ trình kiểm thử giống nhau ngày '.$d,
        ]);
        app(DepartureService::class)->ensureForTour($tourId);
        return [$tourId,(int)DB::table('tbl_tour_availability')->where('tourId',$tourId)->value('availabilityId')];
    }

    private function booking(int $tourId,int $availabilityId): array
    {
        $userId=DB::table('tbl_users')->insertGetId(['username'=>'rescue'.uniqid(),'email'=>'rescue'.uniqid().'@example.com','password'=>'x','fullName'=>'Khách','isActive'=>'y']);
        $id=DB::table('tbl_booking')->insertGetId(BookingPii::encrypt([
            'tourId'=>$tourId,'userId'=>$userId,'availabilityId'=>$availabilityId,
            'departureDate'=>DB::table('tbl_tour_availability')->where('availabilityId',$availabilityId)->value('departureDate'),
            'fullName'=>'Khách','email'=>'test@example.com','phoneNumber'=>'0900000000','address'=>'Hà Nội',
            'numAdults'=>2,'numChildren'=>0,'totalPrice'=>2000000,'bookingStatus'=>'y',
            'bookingCode'=>'RS'.uniqid(),
        ]));
        DB::table('tbl_tour_availability')->where('availabilityId',$availabilityId)->increment('bookedSlots',2);
        return [$userId,$id];
    }

    private function proposal(int $source,string $type='date_change', ?int $target=null): array
    {
        return [
            'availabilityId'=>$source,'targetAvailabilityId'=>$target,'type'=>$type,
            'description'=>'Đề xuất thay đổi ngày khởi hành để đảm bảo đủ số lượng khách.',
            'serviceGuarantee'=>'Giữ nguyên chất lượng dịch vụ, mức giá, quyền lợi và loại phòng.',
            'changeSummary'=>'Chuyển sang lịch khác cùng tour, giá và quyền lợi không đổi.',
            'responseDeadline'=>now()->addDays(4)->toDateTimeString(),
            'resourceId'=>DB::table('tbl_rescue_resources')->insertGetId([
                'name'=>'Xe demo '.uniqid(),'kind'=>'transport','capacity'=>50,'active'=>true,
                'created_at'=>now(),'updated_at'=>now(),
            ]),
            'fromDay'=>1,'toDay'=>2,'sharedService'=>'transport',
            'operationalDetails'=>'Dùng chung xe trên đoạn đường đã xác minh sức chứa và lịch chạy.',
        ];
    }

    public function test_changes_departure_only_after_consented_and_never_changes_price(): void
    {
        [$tour,$from]=$this->departure(15,'A');
        $date=now()->addDays(18)->toDateString();
        $to=DB::table('tbl_tour_availability')->insertGetId([
            'tourId'=>$tour,'departureDate'=>$date,'totalSlots'=>10,'bookedSlots'=>0,'heldSlots'=>0,'status'=>'open','createdAt'=>now(),
        ]);
        [$user,$booking]=$this->booking($tour,$from);
        $service=app(DepartureRescueService::class);
        $plan=$service->create($this->proposal($from,'date_change',$to));
        $this->assertSame('pending',DB::table('tbl_departure_rescue_plans')->where('planId',$plan)->value('status'));
        try { $service->apply($plan); $this->fail('Cannot apply without consent'); } catch(ValidationException $e) { $this->assertNotEmpty($e->errors()); }
        $service->respond($plan,$booking,$user,'accepted');
        $this->assertSame('ready',DB::table('tbl_departure_rescue_plans')->where('planId',$plan)->value('status'));
        $service->apply($plan);
        $b=DB::table('tbl_booking')->where('bookingId',$booking)->first();
        $this->assertSame((int)$to,(int)$b->availabilityId);
        $this->assertSame('2000000',(string)(int)$b->totalPrice);
        $this->assertSame(0,(int)DB::table('tbl_tour_availability')->where('availabilityId',$from)->value('bookedSlots'));
        $this->assertSame(2,(int)DB::table('tbl_tour_availability')->where('availabilityId',$to)->value('bookedSlots'));
    }

    public function test_decline_prevents_automatic_changes(): void
    {
        [$tour,$from]=$this->departure(16,'B');
        $to=DB::table('tbl_tour_availability')->insertGetId(['tourId'=>$tour,'departureDate'=>now()->addDays(19)->toDateString(),'totalSlots'=>10,'bookedSlots'=>0,'heldSlots'=>0,'status'=>'open','createdAt'=>now()]);
        [$user,$booking]=$this->booking($tour,$from);
        $service=app(DepartureRescueService::class);
        $plan=$service->create($this->proposal($from,'date_change',$to));
        $service->respond($plan,$booking,$user,'declined');
        $this->assertSame('rejected',DB::table('tbl_departure_rescue_plans')->where('planId',$plan)->value('status'));
        $this->assertSame($from,(int)DB::table('tbl_booking')->where('bookingId',$booking)->value('availabilityId'));
    }

    public function test_partial_segment_requires_both_tours_customer_consent(): void
    {
        [$tourA,$first]=$this->departure(17,'C');
        [$tourB,$second]=$this->departure(17,'D');
        [$userA,$bookingA]=$this->booking($tourA,$first);
        [$userB,$bookingB]=$this->booking($tourB,$second);
        $service=app(DepartureRescueService::class);
        $plan=$service->create($this->proposal($first,'partial',$second));
        $this->assertSame(2,DB::table('tbl_departure_rescue_responses')->where('planId',$plan)->count());
        $service->respond($plan,$bookingA,$userA,'accepted');
        $this->assertSame('pending',DB::table('tbl_departure_rescue_plans')->where('planId',$plan)->value('status'));
        $service->respond($plan,$bookingB,$userB,'accepted');
        DB::table('tbl_tour_availability')->whereIn('availabilityId',[$first,$second])->update(['status'=>'closed']);
        $service->apply($plan);
        $this->assertSame($first,(int)DB::table('tbl_booking')->where('bookingId',$bookingA)->value('availabilityId'));
        $this->assertSame($second,(int)DB::table('tbl_booking')->where('bookingId',$bookingB)->value('availabilityId'));
    }

    public function test_email_is_queued_once_by_outbox(): void
    {
        Mail::fake();
        [$tour,$from]=$this->departure(18,'E');
        [$user,$booking]=$this->booking($tour,$from);
        $plan=app(DepartureRescueService::class)->create($this->proposal($from,'keep'));
        $this->artisan('departures:rescue-notify')->assertSuccessful();
        $this->artisan('departures:rescue-notify')->assertSuccessful();
        Mail::assertQueued(TemplateMail::class,1);
    }
    public function test_tour_change_moves_only_consented_paid_booking_without_changing_money(): void
    {
        [$a,$from]=$this->departure(20,'transfer-A');
        [$b,$to]=$this->departure(21,'transfer-B');
        [$user,$booking]=$this->booking($a,$from);
        DB::table('tbl_checkout')->insert(['bookingId'=>$booking,'paymentMethod'=>'office','amount'=>2000000,'paymentStatus'=>'y']);
        $service=app(DepartureRescueService::class);
        $plan=$service->create($this->proposal($from,'tour_change',$to));
        $service->respond($plan,$booking,$user,'accepted');
        $service->apply($plan);
        $row=DB::table('tbl_booking')->where('bookingId',$booking)->first();
        $this->assertSame($b,(int)$row->tourId);
        $this->assertSame($to,(int)$row->availabilityId);
        $this->assertSame(2000000,(int)$row->totalPrice);
        $this->assertSame(1,DB::table('tbl_rescue_transfers')->where('bookingId',$booking)->count());
    }

    public function test_tour_change_rejects_price_difference(): void
    {
        [$a,$from]=$this->departure(20,'transfer-C');
        [$b,$to]=$this->departure(21,'transfer-D');
        DB::table('tbl_tours')->where('tourId',$b)->update(['priceAdult'=>1200000]);
        $this->booking($a,$from);
        $this->expectException(ValidationException::class);
        app(DepartureRescueService::class)->create($this->proposal($from,'tour_change',$to));
    }

    public function test_partial_itinerary_mismatch_is_blocked(): void
    {
        [$a,$from]=$this->departure(20,'partial-A');
        [$b,$to]=$this->departure(20,'partial-B');
        DB::table('tbl_timeline')->where('tourId',$b)->orderBy('timeLineId')->limit(1)->update(['description'=>'Khác hành trình']);
        $this->expectException(ValidationException::class);
        app(DepartureRescueService::class)->create($this->proposal($from,'partial',$to));
    }

    public function test_resource_conflict_blocks_second_partial_merge(): void
    {
        [$a,$from]=$this->departure(22,'resource-A');
        [$b,$to]=$this->departure(22,'resource-B');
        [$c,$third]=$this->departure(22,'resource-C');
        [$d,$fourth]=$this->departure(22,'resource-D');
        [$u1,$b1]=$this->booking($a,$from);
        [$u2,$b2]=$this->booking($b,$to);
        [$u3,$b3]=$this->booking($c,$third);
        [$u4,$b4]=$this->booking($d,$fourth);
        $service=app(DepartureRescueService::class);
        $firstData=$this->proposal($from,'partial',$to);
        $resource=$firstData['resourceId'];
        DB::table('tbl_rescue_resources')->where('resourceId',$resource)->update(['capacity'=>4]);
        $plan=$service->create($firstData);
        foreach([[$u1,$b1],[$u2,$b2]] as [$user,$booking]) $service->respond($plan,$booking,$user,'accepted');
        DB::table('tbl_tour_availability')->whereIn('availabilityId',[$from,$to])->update(['status'=>'closed']);
        $service->apply($plan);
        $second=$this->proposal($third,'partial',$fourth);
        $second['resourceId']=$resource;
        $p2=$service->create($second);
        foreach([[$u3,$b3],[$u4,$b4]] as [$user,$booking]) $service->respond($p2,$booking,$user,'accepted');
        DB::table('tbl_tour_availability')->whereIn('availabilityId',[$third,$fourth])->update(['status'=>'closed']);
        $this->expectException(ValidationException::class);
        $service->apply($p2);
    }

    public function test_cancel_review_requests_refund_without_marking_paid_out(): void
    {
        [$tour,$from]=$this->departure(20,'refund');
        [$user,$booking]=$this->booking($tour,$from);
        DB::table('tbl_checkout')->insert(['bookingId'=>$booking,'paymentMethod'=>'office','amount'=>2000000,'paymentStatus'=>'y']);
        $service=app(DepartureRescueService::class);
        $plan=$service->create($this->proposal($from,'cancel_review'));
        $service->respond($plan,$booking,$user,'accepted');
        $service->apply($plan);
        $this->assertSame(1,$service->cancelAndRequestRefunds($plan,1));
        $this->assertSame('c',DB::table('tbl_booking')->where('bookingId',$booking)->value('bookingStatus'));
        $this->assertSame('pending',DB::table('tbl_refunds')->where('bookingId',$booking)->value('status'));
        $this->assertSame('refund_pending',DB::table('tbl_checkout')->where('bookingId',$booking)->value('paymentStatus'));
    }

}
