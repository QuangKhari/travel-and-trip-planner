<?php

namespace App\Services\Ai\Explain;

use App\Services\Ai\AiGateway;
use App\Services\Content\DestinationMonthBrief;
use App\Services\Planner\Health\TripHealth;
use App\Services\Planner\ItemInsights;
use App\Services\Planner\TripPlanner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * F2 "Tóm tắt bằng AI": lời kể từng ngày + "vì sao có mục này" + lưu ý có căn cứ, dựng từ dữ kiện có sẵn
 * (reasons của PlannerEngine, TripHealth, mùa theo tháng, ghi chú chuyến). Không thêm thông tin mới.
 *
 * Cache theo (tripId, rev, model, promptVersion): sửa lịch thì rev đổi, tóm tắt cũ hết hiệu lực.
 * Chỉ sinh khi người dùng bấm (không tự gọi mỗi lần sửa) để giữ chi phí thấp.
 */
final class ExplainService
{
    public function __construct(
        private AiGateway $gateway,
        private TripPlanner $planner,
        private TripHealth $health,
        private ExplainComposer $composer,
    ) {}

    /** @return array<string,mixed> */
    public function forTrip(object $trip, ?int $userId, bool $fresh = false): array
    {
        $t0 = microtime(true);
        $rev = (int) ($trip->rev ?? 0);
        $tripId = (int) $trip->tripId;
        $version = (string) config('ai.prompt_version.explain', 'explain-1');

        if (!$fresh && ($hit = $this->cached($tripId, $rev, $version))) {
            return $hit + ['engine' => 'cache', 'rev' => $rev, 'notice' => null, 'remaining' => $this->gateway->remaining($userId)];
        }

        $payload = $this->planner->payload($trip);
        $health = $this->health->forTrip($trip);
        $facts = ExplainFacts::build($payload, $health, $this->destName($trip), $this->monthText($trip), $this->notes($trip));
        $scheduled = array_sum(array_map(fn($d) => count($d['keys']), $facts['days']));
        if ($scheduled === 0) {
            return ['ok' => false, 'message' => 'Chuyến chưa có mục nào trong các ngày để tóm tắt.', 'rev' => $rev];
        }

        $res = $this->gateway->json('explain', $userId, ExplainPrompt::system(), ExplainPrompt::user($facts['table']), ExplainPrompt::schema());
        if ($res['ok'] && is_array($res['data'])) {
            $out = $this->composer->compose($res['data'], $facts);
            $engine = 'llm';
            $outcome = $out['guards']['replaced'] ? 'guard_replaced' : 'ok';
        } else {
            $out = $this->composer->template($facts);
            $engine = 'template';
            $outcome = in_array($res['reason'] ?? '', ['timeout', 'error', 'invalid'], true) ? $res['reason'] : 'fallback';
        }

        $this->gateway->log(['feature' => 'explain', 'userId' => $userId, 'tripId' => $tripId, 'engine' => $engine, 'outcome' => $outcome,
            'model' => $res['model'] ?? null, 'inputChars' => mb_strlen($facts['table']), 'in' => $res['in'] ?? null, 'out' => $res['out'] ?? null,
            'ms' => (int) round((microtime(true) - $t0) * 1000), 'flags' => $out['guards']['flags'], 'error' => $res['error'] ?? null]);

        $body = ['ok' => true] + $out;
        if ($engine === 'llm') {
            $this->store($tripId, $rev, $res['model'] ?? 'llm', $version, $body);
        }

        return $body + ['engine' => $engine, 'rev' => $rev, 'notice' => $engine === 'template' ? AiGateway::reasonText($res['reason'] ?? 'disabled') : null,
            'remaining' => $this->gateway->remaining($userId)];
    }

    private function destName(object $trip): ?string
    {
        return !empty($trip->destinationId) ? DB::table('tbl_destinations')->where('destinationId', (int) $trip->destinationId)->value('name') : null;
    }

    private function monthText(object $trip): ?string
    {
        $m = ItemInsights::timeContext($trip)['month'] ?? null;
        if (!$m || empty($trip->destinationId)) {
            return null;
        }
        try {
            $b = app(DestinationMonthBrief::class)->for((int) $trip->destinationId, (int) $m);

            return $b ? DestinationMonthBrief::summaryText($b) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function notes(object $trip): ?string
    {
        $n = isset($trip->notes) ? trim((string) $trip->notes) : '';

        return $n !== '' ? ExplainFacts::clean(mb_substr($n, 0, 300)) : null;
    }

    private function cached(int $tripId, int $rev, string $version): ?array
    {
        if (!Schema::hasTable('tbl_trip_ai_cache')) {
            return null;
        }
        $row = DB::table('tbl_trip_ai_cache')->where(['tripId' => $tripId, 'rev' => $rev, 'feature' => 'explain', 'promptVersion' => $version])
            ->orderByDesc('cacheId')->first();
        $data = $row ? json_decode((string) $row->payload, true) : null;

        return is_array($data) ? $data : null;
    }

    private function store(int $tripId, int $rev, string $model, string $version, array $body): void
    {
        if (!Schema::hasTable('tbl_trip_ai_cache')) {
            return;
        }
        try {
            DB::table('tbl_trip_ai_cache')->updateOrInsert(
                ['tripId' => $tripId, 'rev' => $rev, 'feature' => 'explain', 'model' => mb_substr($model, 0, 60), 'promptVersion' => $version],
                ['payload' => json_encode($body, JSON_UNESCAPED_UNICODE), 'createdAt' => now()]
            );
            // giữ gọn: bỏ bản của các rev cũ
            DB::table('tbl_trip_ai_cache')->where('tripId', $tripId)->where('rev', '<', $rev)->delete();
        } catch (Throwable) {
            // cache là phụ
        }
    }
}
