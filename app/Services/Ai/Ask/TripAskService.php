<?php

namespace App\Services\Ai\Ask;

use App\Services\Ai\AiGateway;
use App\Services\Ai\Explain\ExplainFacts;
use App\Services\Content\DestinationMonthBrief;
use App\Services\Planner\Health\TripHealth;
use App\Services\Planner\ItemInsights;
use App\Services\Planner\TripPlanner;
use App\Services\Rag\AskLimitException;
use App\Services\Rag\AskService;
use App\Services\Rag\RagUnavailableException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * F5 "Hỏi về chuyến này" (tài liệu v2.2 mục 5.5). Tái dùng nguyên AskService của Hỏi đáp (cổng tin cậy, từ chối khi thiếu căn cứ,
 * kiểm tra trích dẫn, hạn mức rag.limits.daily_per_user, nhật ký tbl_chat_logs với mode = "trip"), chỉ thêm hai thứ:
 *   (1) phạm vi mặc định = điểm đến của chuyến;
 *   (2) "dữ kiện chuyến" (TripFacts) đứng trước dữ kiện SQL, trích dẫn trỏ về đúng ngày trong lịch.
 * Không cần AI: khi tắt LLM, AskService trả lời bằng trích đoạn nguyên văn của chính các nguồn đó.
 */
final class TripAskService
{
    public function __construct(private AskService $ask, private TripPlanner $planner, private TripHealth $health) {}

    public function ask(object $trip, ?int $userId, string $question): array
    {
        $rev = (int) ($trip->rev ?? 0);
        $dest = !empty($trip->destinationId) ? DB::table('tbl_destinations')->where('destinationId', (int) $trip->destinationId)->first(['name', 'slug']) : null;
        $payload = $this->planner->payload($trip);
        $health = $this->health->forTrip($trip);
        $notes = isset($trip->notes) && trim((string) $trip->notes) !== '' ? ExplainFacts::clean(mb_substr((string) $trip->notes, 0, 300)) : null;
        $facts = TripFacts::build($payload, $health, route('planner.edit', ['id' => (int) $trip->tripId], false), $dest->name ?? null, $this->monthText($trip), $notes);

        // câu nêu rõ "ngày N": chỉ đưa lịch của ngày đó (đỡ nhiễu cho LLM, và trích đoạn khi không có LLM đúng trọng tâm)
        if (preg_match_all('/\bngay\s*(\d{1,2})\b/', \App\Services\Content\SearchCatalog::fold($question), $m)) {
            $want = array_map('intval', $m[1]);
            $facts = array_values(array_filter($facts, fn($f) => $f['day'] === null || in_array($f['day'], $want, true)));
        }
        try {
            $r = $this->ask->ask($question, $userId, ['facts' => $facts, 'destination' => $dest->slug ?? null, 'logMode' => 'trip']);
        } catch (AskLimitException) {
            return ['ok' => false, 'kind' => 'reply', 'rev' => $rev, 'notice' => AiGateway::reasonText('quota'),
                'say' => [['t' => 'Bạn đã dùng hết lượt hỏi hôm nay. Lịch và các nút sửa vẫn dùng bình thường.']]];
        } catch (RagUnavailableException | Throwable $e) {
            report($e);

            return ['ok' => false, 'kind' => 'reply', 'rev' => $rev, 'say' => [['t' => 'La bàn chưa trả lời được câu hỏi này lúc này.']]];
        }

        $sources = [];
        foreach ($r['sources'] ?? [] as $s) {
            $day = TripFacts::dayOf($s['key'] ?? null);
            $isTrip = str_starts_with((string) ($s['key'] ?? ''), 'trip:');
            $sources[] = ['n' => (int) $s['n'], 'title' => (string) $s['title'], 'url' => $isTrip ? null : (string) $s['url'], 'day' => $day,
                'trip' => $isTrip, 'cited' => (bool) $s['cited']];
        }

        return [
            'ok' => true, 'kind' => 'answer', 'rev' => $rev,
            'say' => [['t' => (string) $r['answer']]],
            'sources' => $sources,
            'refused' => (bool) ($r['refused'] ?? false),
            'notice' => $r['notice'] ?? null,
            'engine' => $r['engine'] ?? null,
            'suggest' => $r['suggest'] ?? [],
            'remaining' => $r['remaining'] ?? null,
        ];
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
}
