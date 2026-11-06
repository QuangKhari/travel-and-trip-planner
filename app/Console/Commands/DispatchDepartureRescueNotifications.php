<?php

namespace App\Console\Commands;

use App\Mail\TemplateMail;
use App\Services\DepartureRescueService;
use App\Support\BookingPii;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

/** Run with schedule + queue worker. Outbox is an at-most-once dispatch claim; SMTP delivery requires queue monitoring. */
class DispatchDepartureRescueNotifications extends Command
{
    protected $signature = 'departures:rescue-notify {--limit=100}';
    protected $description = 'Expire proposals and enqueue rescue proposal/decision/result emails';

    public function handle(DepartureRescueService $service): int
    {
        if (!$service->ready()) { $this->warn('Chưa chạy migration rescue.'); return self::FAILURE; }
        $expired=$service->expire();
        $limit=max(1,min(500,(int)$this->option('limit')));
        $items=DB::table('tbl_departure_rescue_outbox as o')
            ->join('tbl_departure_rescue_responses as r','r.responseId','=','o.responseId')
            ->join('tbl_departure_rescue_plans as p','p.planId','=','r.planId')
            ->join('tbl_booking as b','b.bookingId','=','r.bookingId')
            ->join('tbl_tours as t','t.tourId','=','b.tourId')
            ->whereNull('o.queuedAt')->orderBy('o.outboxId')->limit($limit)
            ->get(['o.outboxId','o.event','r.decision','p.status','p.description','p.changeSummary','p.serviceGuarantee','p.responseDeadline','b.bookingId','b.bookingCode','b.email','t.title']);
        $queued=0;
        foreach($items as $item) {
            if ($item->event==='proposal' && (!in_array($item->status,['pending','ready'],true) || $item->decision !== 'pending')) {
                DB::table('tbl_departure_rescue_outbox')->where('outboxId',$item->outboxId)->whereNull('queuedAt')->update(['queuedAt'=>now()]);
                continue;
            }
            $claimed=DB::table('tbl_departure_rescue_outbox')->where('outboxId',$item->outboxId)->whereNull('queuedAt')->update(['queuedAt'=>now()]);
            if (!$claimed) continue;
            try {
                $book=BookingPii::decrypt((object)['bookingId'=>$item->bookingId,'email'=>$item->email]);
                if (!filter_var($book->email,FILTER_VALIDATE_EMAIL)) { Log::warning('Rescue email invalid', ['bookingId'=>$item->bookingId]); continue; }
                Mail::to($book->email)->queue(new TemplateMail('admin.emails.departure-rescue', [
                    'event'=>$item->event,'bookingCode'=>$item->bookingCode,'tourTitle'=>$item->title,
                    'description'=>$item->description,'changeSummary'=>$item->changeSummary,
                    'serviceGuarantee'=>$item->serviceGuarantee,'deadline'=>$item->responseDeadline,
                    'url'=>route('departure.rescue.responses'),
                ],'Travela — cập nhật chuyến đi '.$item->bookingCode));
                $queued++;
            } catch (\Throwable $e) {
                DB::table('tbl_departure_rescue_outbox')->where('outboxId',$item->outboxId)->update(['queuedAt'=>null]);
                Log::error('Could not enqueue rescue email',['outboxId'=>$item->outboxId,'exception'=>$e->getMessage()]);
            }
        }
        $this->info("Hết hạn: {$expired}; email xếp hàng: {$queued}");
        return self::SUCCESS;
    }
}
