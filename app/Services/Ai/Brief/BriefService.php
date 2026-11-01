<?php

namespace App\Services\Ai\Brief;

use App\Services\Ai\AiGateway;
use App\Services\Content\SearchIntent;
use App\Services\Planner\DestinationFinder;
use App\Services\Planner\PlannerGate;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * F1 "Vé hành trình": câu tự nhiên -> các trường của form đề xuất, kèm trạng thái nêu rõ / AI đoán / còn thiếu.
 * KHÔNG gọi PlannerService::generate: người dùng xem vé, sửa, rồi tự bấm "Xem trước lịch trình" như luồng cũ.
 *
 * Luôn chạy SearchIntent (luật, miễn phí) trước: là đường lùi khi AI tắt / lỗi / hết lượt, và là mốc so sánh ở ai:eval.
 */
final class BriefService
{
    public function __construct(
        private AiGateway $gateway,
        private SearchIntent $intent,
        private BriefValidator $validator,
        private PlannerGate $gate,
    ) {}

    public static function clean(string $text): string
    {
        $t = strip_tags($text);
        $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $t) ?? '';
        $t = trim(preg_replace('/\s+/u', ' ', $t) ?? '');

        return mb_substr($t, 0, (int) config('ai.limits.max_input_chars', 500));
    }

    /** @return array<string,mixed> trả thẳng cho giao diện (POST /len-ke-hoach/hieu-yeu-cau) */
    public function understand(string $text, ?int $userId, ?string $today = null): array
    {
        $text = self::clean($text);
        $today ??= now()->toDateString();
        $ctx = $this->context($today);
        $t0 = microtime(true);

        $rules = $this->validator->validate($this->fromRules($text), $text, $ctx);
        $engine = 'rules';
        $outcome = 'fallback';
        $res = ['ok' => false, 'reason' => 'disabled', 'ms' => 0, 'in' => null, 'out' => null, 'model' => null];
        $brief = $rules;

        if ($text !== '') {
            $res = $this->gateway->json('brief', $userId, BriefPrompt::system(array_map(fn($d) => ['name' => $d['name']], $ctx['dests']), $today),
                BriefPrompt::user($text), BriefPrompt::schema($ctx['maxDays']));
            if ($res['ok'] && is_array($res['data'])) {
                $brief = BriefValidator::merge($this->validator->validate($res['data'], $text, $ctx), $rules);
                $engine = 'llm';
                $outcome = $brief['refused'] ? 'refused' : ($brief['flags'] ? 'guard_replaced' : 'ok');
            } elseif (($res['reason'] ?? null) === 'invalid') {
                $outcome = 'invalid';
            } elseif (in_array($res['reason'] ?? null, ['timeout', 'error'], true)) {
                $outcome = $res['reason'];
            }
        }

        $this->gateway->log(['feature' => 'brief', 'userId' => $userId, 'engine' => $engine, 'outcome' => $outcome, 'model' => $res['model'] ?? null,
            'inputChars' => mb_strlen($text), 'in' => $res['in'] ?? null, 'out' => $res['out'] ?? null, 'ms' => (int) round((microtime(true) - $t0) * 1000),
            'flags' => $brief['flags'], 'error' => $res['error'] ?? null, 'text' => $text]);

        return [
            'ok' => $text !== '' && !$brief['refused'],
            'engine' => $engine,
            'notice' => $engine === 'rules' ? AiGateway::reasonText($res['reason'] ?? 'disabled') : null,
            'text' => $text,
            'refused' => $brief['refused'] ? 'Mình chỉ đọc được yêu cầu về chuyến đi. Hãy kể nơi muốn đến, số ngày và thời gian nhé.' : null,
            'fields' => $brief['fields'],
            'prefill' => $brief['prefill'],
            'needs' => $this->asks($brief, $ctx, $today),
            'warnings' => $brief['warnings'],
            'spans' => $brief['spans'],
            'mention' => $brief['mention'],
            'ready' => isset($brief['prefill']['destinationSlug']) ? (bool) ($ctx['dests'][$brief['prefill']['destinationSlug']]['ready'] ?? false) : null,
            'remaining' => $this->gateway->remaining($userId),
        ];
    }

    /** Ngữ cảnh cho validator: điểm đến đang bật (kèm mức sẵn sàng), enum từ cấu hình. */
    public function context(string $today): array
    {
        $ready = [];
        try {
            foreach ($this->gate->coreStatus() as $c) {
                $ready[$c['destinationId']] = $c['ready'];
            }
        } catch (Throwable) {
            $ready = [];
        }
        $dests = [];
        foreach (DB::table('tbl_destinations')->where('isActive', 1)->orderBy('name')->limit(300)->get(['destinationId', 'slug', 'name']) as $d) {
            $dests[$d->slug] = ['id' => (int) $d->destinationId, 'name' => $d->name, 'ready' => (bool) ($ready[(int) $d->destinationId] ?? false)];
        }

        return [
            'dests' => $dests,
            'maxDays' => (int) config('planner.max_days', 7),
            'interests' => array_keys((array) config('interests')),
            'dislikes' => array_keys((array) config('planner.dislikes')),
            'paces' => array_keys((array) config('planner.pace')),
            'difficulties' => array_keys((array) config('planner.difficulty_rank')),
            'today' => $today,
        ];
    }

    /** Kết quả SearchIntent đổi sang cùng hình dạng với JSON của LLM để đi qua CÙNG validator. */
    public function fromRules(string $text): array
    {
        if ($text === '') {
            return [];
        }
        try {
            $i = $this->intent->parse($text);
        } catch (Throwable) {
            return [];
        }

        return [
            'destination' => ['slug' => $i['destination']['slug'] ?? null, 'mention' => null],
            'month' => $i['month'],
            'startDate' => null,
            'days' => $i['days'],
            'adults' => $i['groupSize'],
            'children' => null,
            'budget' => ['amountVnd' => $i['budget'], 'scope' => null],
            'pace' => null,
            'maxDifficulty' => null,
            'interests' => $i['interests'],
            'dislikes' => $i['dislikes'],
            'extras' => [],
            'missing' => [],
            'inferred' => $i['groupSize'] ? ['adults'] : [],   // "2 người" chưa nói rõ có trẻ em hay không
            'spans' => [],
            'offTopic' => false,
        ];
    }

    /** Câu hỏi lại kèm lựa chọn nhanh cho từng trường còn thiếu. */
    private function asks(array $brief, array $ctx, string $today): array
    {
        $out = [];
        $month = (int) substr($today, 5, 2);
        foreach ($brief['needs'] as $n) {
            $q = ['field' => $n['field'], 'optional' => $n['optional']];
            switch ($n['field']) {
                case 'destination':
                    $q['question'] = $brief['mention'] ? 'Travela chưa có “' . $brief['mention'] . '”. Bạn chọn điểm đến gần đúng nhé?' : 'Bạn muốn đi đâu?';
                    $q['options'] = $this->destOptions($ctx, $brief['prefill']['month'] ?? null);
                    break;
                case 'days':
                    $q['question'] = 'Chuyến đi mấy ngày?';
                    $q['options'] = array_map(fn($d) => ['label' => $d . ' ngày', 'value' => $d], [2, 3, 4, 5]);
                    break;
                case 'when':
                    $q['question'] = 'Bạn định đi tháng nào?';
                    $q['options'] = array_map(fn($k) => ['label' => 'Tháng ' . (($month + $k - 1) % 12 + 1), 'value' => ($month + $k - 1) % 12 + 1], [0, 1, 2, 3]);
                    break;
                case 'budget_scope':
                    $q['question'] = 'Ngân sách này cho cả nhóm hay mỗi người?';
                    $q['options'] = [['label' => 'Cả nhóm', 'value' => 'total'], ['label' => 'Mỗi người', 'value' => 'perPerson']];
                    break;
                case 'single_destination':
                    $q['question'] = 'Planner xếp từng điểm đến một. Bạn bắt đầu với nơi nào?';
                    $q['options'] = array_map(fn($n) => ['label' => $n, 'value' => $n], array_values(array_filter(array_merge([$brief['fields']['destination']['value']], $brief['others']))));
                    break;
            }
            $out[] = $q;
        }

        return $out;
    }

    private function destOptions(array $ctx, ?int $month): array
    {
        $opts = [];
        if ($month) {
            try {
                foreach (app(DestinationFinder::class)->recommend(['month' => $month, 'limit' => 8])['items'] as $it) {
                    $d = $ctx['dests'][$it['slug'] ?? ''] ?? null;
                    if ($d && $d['ready']) {
                        $opts[] = ['label' => $d['name'], 'value' => $d['id']];
                    }
                }
            } catch (Throwable) {
                $opts = [];
            }
        }
        foreach ($ctx['dests'] as $d) {
            if (count($opts) >= 4) {
                break;
            }
            if ($d['ready'] && !in_array($d['id'], array_column($opts, 'value'), true)) {
                $opts[] = ['label' => $d['name'], 'value' => $d['id']];
            }
        }
        return array_slice($opts, 0, 4);
    }
}
