<?php

namespace App\Jobs;

use App\Services\Integration\PlannerTourSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/** Xử lý một sự kiện trong outbox (idempotent: đã có processedAt thì bỏ qua). */
class ProcessDomainEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;
    public array $backoff = [10, 60, 300];

    public function __construct(public int $eventId) {}

    public function handle(PlannerTourSync $sync): void
    {
        $e = DB::table('tbl_domain_events')->where('eventId', $this->eventId)->first();
        if (!$e || $e->processedAt) {
            return;
        }
        // nhận việc nguyên tử: hai worker (job + lệnh relay) không xử lý trùng
        $claimed = DB::table('tbl_domain_events')->where('eventId', $this->eventId)->whereNull('processedAt')->where('attempts', $e->attempts)
            ->update(['attempts' => $e->attempts + 1]);
        if (!$claimed) {
            return;
        }
        try {
            $sync->handle((string) $e->name, json_decode((string) $e->payload, true) ?: []);
            DB::table('tbl_domain_events')->where('eventId', $this->eventId)->update(['processedAt' => now(), 'lastError' => null]);
        } catch (\Throwable $ex) {
            DB::table('tbl_domain_events')->where('eventId', $this->eventId)->update(['lastError' => mb_substr($ex->getMessage(), 0, 255)]);
            throw $ex;
        }
    }
}
