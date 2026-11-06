<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Fail-closed itinerary verification and serializable resource capacity reservation. */
class RescueOperationalService
{
    private function fail(string $message): never { throw ValidationException::withMessages(['operations'=>$message]); }

    public function segmentHash(int $firstTour, int $secondTour, int $from, int $to): string
    {
        $one = $this->days($firstTour); $two = $this->days($secondTour);
        if ($from < 1 || $to < $from || count($one) < $to || count($two) < $to) {
            $this->fail('Lịch trình thiếu ngày hoặc số ngày ghép không hợp lệ.');
        }
        $a = array_slice($one, $from-1, $to-$from+1);
        $b = array_slice($two, $from-1, $to-$from+1);
        if ($a !== $b) $this->fail('Lịch trình từng ngày của hai tour khác nhau; cần tách hoạt động riêng hoặc sửa đề xuất, không được ghép tự động.');
        return hash('sha256',json_encode([$firstTour,$secondTour,$from,$to,$a],JSON_UNESCAPED_UNICODE));
    }

    private function days(int $tourId): array
    {
        $rows=DB::table('tbl_timeline')->where('tourId',$tourId)->orderBy('timeLineId')->get(['title','description']);
        return $rows->map(static fn($r)=>[
            trim(mb_strtolower(preg_replace('/\s+/u',' ',strip_tags((string)$r->title)))),
            trim(mb_strtolower(preg_replace('/\s+/u',' ',strip_tags((string)$r->description)))),
        ])->all();
    }

    public function reservePartial(object $plan): void
    {
        $segment=DB::table('tbl_departure_rescue_segments')->where('planId',$plan->planId)->first();
        if (!$segment || !$segment->resourceId) $this->fail('Chưa chỉ định xe/hướng dẫn viên/hoạt động có sức chứa thực tế.');
        $source=DB::table('tbl_tour_availability')->where('availabilityId',$plan->availabilityId)->first();
        $partner=DB::table('tbl_tour_availability')->where('availabilityId',$segment->partnerAvailabilityId)->first();
        if (!$source || !$partner) $this->fail('Lịch khởi hành không tồn tại.');
        if ($plan->itineraryHash !== $this->segmentHash((int)$source->tourId,(int)$partner->tourId,(int)$segment->fromDay,(int)$segment->toDay)) {
            $this->fail('Lịch trình đã thay đổi sau khi xin ý kiến khách; phải xin lại xác nhận.');
        }
        $resource=DB::table('tbl_rescue_resources')->where('resourceId',$segment->resourceId)->lockForUpdate()->first();
        if (!$resource || !$resource->active || $resource->kind !== $segment->sharedService) $this->fail('Tài nguyên không còn hoạt động hoặc không đúng loại dịch vụ.');
        $from=Carbon::parse($source->departureDate)->addDays($segment->fromDay-1)->toDateString();
        $to=Carbon::parse($source->departureDate)->addDays($segment->toDay-1)->toDateString();
        $ids=[$source->availabilityId,$partner->availabilityId];
        $people=(int)DB::table('tbl_booking')->whereIn('availabilityId',$ids)->whereIn('bookingStatus',['y','f'])->sum(DB::raw('numAdults + numChildren'));
        // Lock resource row is the serialization point. Other bookings cannot use resources without this same reservation API.
        $occupied=(int)DB::table('tbl_rescue_resource_allocations')
            ->where('resourceId',$resource->resourceId)->where('status','active')
            ->whereDate('startDate','<=',$to)->whereDate('endDate','>=',$from)->sum('seats');
        if ($people < 1 || $people + $occupied > $resource->capacity) $this->fail('Tài nguyên quá sức chứa hoặc đoàn chưa có khách.');
        DB::table('tbl_rescue_resource_allocations')->insert([
            'resourceId'=>$resource->resourceId,'planId'=>$plan->planId,'startDate'=>$from,'endDate'=>$to,
            'seats'=>$people,'status'=>'active','created_at'=>now(),'updated_at'=>now(),
        ]);
    }
}
