<?php

namespace App\Services\Ai\Copilot;

use App\Services\Ai\AiGateway;
use App\Services\Ai\Explain\ExplainFacts;
use App\Services\Planner\Health\TripHealth;
use App\Services\Planner\Health\TripResolver;
use App\Services\Planner\TripPlanner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * F3 "La bàn Copilot": người dùng nói bằng lời, La bàn đề xuất thay đổi lịch, ENGINE kiểm chứng rồi người dùng bấm Áp dụng.
 *
 *   LLM (search / propose / reply) -> KeyedChangeCompiler (khóa -> vị trí, luật cấm) -> TripResolver::simulate (TripHealth trước / sau)
 *   -> có vấn đề CỨNG mới? trả lời lỗi cho LLM sửa (tối đa 2 lần) : trả "phiếu kiểm chứng" + changes[] cho trình duyệt.
 *
 * Không route nào ở đây ghi vào chuyến: trình duyệt áp dụng changes[] bằng CHÍNH planner-resolve.js rồi tự lưu (rev, 409 như thường).
 * Đề xuất được giữ 1 giờ (Cache) để "Kiểm tra lại" sau khi lịch đổi: ánh xạ lại theo danh tính mục, mô phỏng lại, KHÔNG gọi LLM.
 */
final class CopilotService
{
    private const MAX_STEPS = 4;
    private const MAX_REPAIRS = 2;
    private const MAX_SEARCHES = 2;

    public function __construct(
        private AiGateway $gateway,
        private TripPlanner $planner,
        private TripHealth $health,
        private TripResolver $resolver,
        private PlaceFinder $finder,
        private KeyedChangeCompiler $compiler,
    ) {}

    /**
     * @param list<array{name?:string,day?:?int}> $mentions
     * @param list<array{role:string,text:string}> $history
     * @param array{verify?:bool} $opt verify = false CHỈ dùng cho thí nghiệm B1 (ai:eval --suite=copilot --mode=unchecked):
     *        bỏ bước chặn "vấn đề cứng mới" và vòng sửa, để đo xem không có engine kiểm chứng thì chuyện gì xảy ra
     */
    public function chat(object $trip, ?int $userId, string $message, array $mentions = [], array $history = [], array $opt = []): array
    {
        $verify = $opt['verify'] ?? true;
        $t0 = microtime(true);
        $rev = (int) ($trip->rev ?? 0);
        $ctx = $this->context($trip);
        $base = ['rev' => $rev, 'quick' => $ctx['quick']];
        $message = trim(preg_replace('/\s+/u', ' ', strip_tags($message)) ?? '');
        if ($message === '') {
            return $base + ['ok' => false, 'kind' => 'reply', 'say' => [['t' => 'Bạn muốn sửa gì trong chuyến này?']]];
        }
        // Lệnh cấp ngày / chuyến (bỏ ngày, đổi chỗ hai ngày, dời chuyến, đổi số ngày): luật, không gọi LLM, chạy cả khi AI tắt
        if (!empty($opt['commands']) || !array_key_exists('commands', $opt)) {
            $cmd = TripCommands::parse($message, $ctx['days'], $trip->startDate ? substr((string) $trip->startDate, 0, 10) : null);
            if ($cmd) {
                return $base + $this->command($trip, $userId, $cmd, $ctx, $message, $t0);
            }
        }
        if ($why = $this->gateway->blocked('copilot', $userId)) {
            return $base + ['ok' => false, 'kind' => 'fallback', 'notice' => AiGateway::reasonText($why),
                'say' => [['t' => 'La bàn chưa trò chuyện được lúc này. Bạn vẫn có thể dùng nút “Sửa” ở phần Sức khỏe chuyến đi.']]];
        }
        $explicit = CopilotContext::explicit($message, $mentions, $ctx['keys']);
        $history = array_slice(array_map(fn($h) => ['role' => ($h['role'] ?? '') === 'user' ? 'user' : 'ai',
            'text' => ExplainFacts::clean(mb_substr((string) ($h['text'] ?? ''), 0, 200))], $history), -4);
        $system = CopilotPrompt::system($ctx['days']);
        $schema = CopilotPrompt::schema();
        $budget = (int) config('ai.copilot.budget_ms', 30000);

        $obs = [];
        $cands = [];
        $stat = ['steps' => 0, 'repairs' => 0, 'searches' => 0, 'in' => 0, 'out' => 0];
        $model = null;
        $last = null;
        $result = null;
        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            if ($step > 0 && (microtime(true) - $t0) * 1000 > $budget) {
                break;
            }
            $res = $this->gateway->json('copilot', $userId, $system,
                CopilotPrompt::user($ctx['table'], $history, $message, $explicit, $obs, self::MAX_STEPS - $step), $schema);
            $stat['steps']++;
            $stat['in'] += (int) ($res['in'] ?? 0);
            $stat['out'] += (int) ($res['out'] ?? 0);
            $model = $res['model'] ?? $model;
            if (!$res['ok']) {
                $last = $res;
                break;
            }
            $a = (array) $res['data'];
            $action = (string) ($a['action'] ?? 'reply');
            $say = (string) ($a['say'] ?? '');

            if ($action === 'search' && $stat['searches'] < self::MAX_SEARCHES && $step < self::MAX_STEPS - 1) {
                $stat['searches']++;
                $q = (array) ($a['search'] ?? []);
                $found = $this->finder->search($trip, $q, $ctx);
                $cands += $found['cands'];
                $obs[] = 'search ' . json_encode(array_filter($q, fn($v) => $v !== null), JSON_UNESCAPED_UNICODE) . ' ->'
                    . ($found['lines'] ? "\n" . implode("\n", $found['lines']) : ' không có kết quả (đổi nhóm hoặc bỏ mốc).');
                continue;
            }
            if ($action === 'propose') {
                $p = $this->propose($trip, $ctx, (array) ($a['changes'] ?? []), $cands, $explicit, $say, $verify);
                if ($p['ok']) {
                    $result = $p['body'];
                    break;
                }
                $stat['repairs']++;
                $obs[] = 'propose ' . json_encode($a['changes'] ?? [], JSON_UNESCAPED_UNICODE) . " -> BỊ TỪ CHỐI:\n- " . implode("\n- ", $p['errors']);
                if ($stat['repairs'] > self::MAX_REPAIRS) {
                    $result = ['kind' => 'reply', 'say' => [['t' => 'La bàn chưa tìm được cách sửa nào mà không gây vấn đề mới. ' . $p['errors'][0]]], 'failed' => true];
                    break;
                }
                continue;
            }
            // reply (hoặc search quá số lần cho phép)
            $result = ['kind' => 'reply', 'say' => $this->segments($this->afterSearch($action, $say, $stat['searches'], $cands) ?? $this->groundedReply($say, $ctx), $ctx, $cands)];
            break;
        }

        if ($result === null) {
            $result = $last
                ? ['kind' => 'fallback', 'notice' => AiGateway::reasonText($last['reason'] ?? 'error'), 'say' => [['t' => 'La bàn chưa trả lời được lúc này, bạn thử lại sau ít phút nhé.']], 'failed' => true]
                : ['kind' => 'reply', 'say' => [['t' => 'La bàn cần nhiều bước hơn cho yêu cầu này. Bạn thử chia nhỏ (một ngày hoặc một mục) nhé.']], 'failed' => true];
        }
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $runId = $this->gateway->log(['feature' => 'copilot', 'userId' => $userId, 'tripId' => (int) $trip->tripId, 'engine' => 'llm',
            'outcome' => $last ? ($last['reason'] ?? 'error') : ($result['kind'] === 'proposal' ? 'proposed' : (!empty($result['failed']) ? 'refused' : 'reply')),
            'model' => $model, 'inputChars' => mb_strlen($message), 'in' => $stat['in'], 'out' => $stat['out'], 'ms' => $ms,
            'flags' => ['steps' => $stat['steps'], 'repairs' => $stat['repairs'], 'searches' => $stat['searches'], 'hits' => count($cands)], 'error' => $last['error'] ?? null, 'text' => $message]);
        if ($result['kind'] === 'proposal' && $runId) {
            $this->remember($result['id'], ['runId' => $runId]);
        }
        unset($result['failed']);

        return $base + ['ok' => $result['kind'] !== 'fallback'] + $result + ['ms' => $ms, 'steps' => $stat['steps'], 'remaining' => $this->gateway->remaining($userId)];
    }

    /**
     * Lệnh cấp ngày / chuyến đã nhận ra bằng TripCommands. Bỏ ngày / đổi chỗ hai ngày đi qua CÙNG bước biên dịch + mô phỏng như đề xuất LLM
     * (vé, chỗ ở giữ nguyên ngày). Dời chuyến / đổi số ngày là thay đổi cấp chuyến: mô phỏng bằng TripHealth trên bản sao chuyến với ngày mới,
     * trả kind "tripedit" để trang áp dụng qua ô Ngày đi / Số ngày (cùng đường lưu, cùng kiểm tra phiên bản).
     */
    private function command(object $trip, ?int $userId, array $cmd, array $ctx, string $message, float $t0): array
    {
        $rules = \App\Services\Planner\PlannerRules::fromConfig();
        $free = fn(array $k) => !\App\Services\Planner\TripLogistics::exempt(['type' => $k['type'], 'kind' => $k['kind'], 'name' => $k['name']], $rules);
        $result = null;
        if (in_array($cmd['cmd'], ['clear_day', 'swap'], true)) {
            $keyed = [];
            $explicit = [];
            foreach ($ctx['keys'] as $k => $x) {
                if ($x['day'] === null || !$free($x)) {
                    continue;
                }
                if ($cmd['cmd'] === 'clear_day' && $x['day'] === $cmd['day']) {
                    $keyed[] = ['op' => 'move', 'item' => $k, 'toDay' => null];
                    $explicit[] = $k;
                } elseif ($cmd['cmd'] === 'swap' && in_array($x['day'], [$cmd['a'], $cmd['b']], true)) {
                    $keyed[] = ['op' => 'move', 'item' => $k, 'toDay' => $x['day'] === $cmd['a'] ? $cmd['b'] : $cmd['a']];
                    $explicit[] = $k;
                }
            }
            $say = $cmd['cmd'] === 'clear_day'
                ? 'Đưa mọi hoạt động ngày ' . $cmd['day'] . ' về Đã lưu (vé và chỗ ở giữ nguyên). Muốn bớt hẳn một ngày thì nói "rút còn ' . max(1, $ctx['days'] - 1) . ' ngày".'
                : 'Đổi lịch ngày ' . $cmd['a'] . ' với ngày ' . $cmd['b'] . ' (vé và chỗ ở giữ nguyên ngày).';
            if (!$keyed) {
                $result = ['kind' => 'reply', 'say' => [['t' => $cmd['cmd'] === 'clear_day' ? 'Ngày ' . $cmd['day'] . ' chưa có hoạt động nào để bỏ.' : 'Hai ngày này chưa có hoạt động nào để đổi.']]];
            } else {
                // KHÔNG chặn khi có lỗi cứng mới: người dùng ra lệnh đích danh; phiếu kiểm chứng nêu rõ vấn đề mới (vd. nơi đóng cửa ngày mới)
                $p = $this->propose($trip, $ctx, $keyed, [], $explicit, $say, false, 200);
                $result = $p['ok'] ? $p['body'] : ['kind' => 'reply', 'say' => [['t' => 'Chưa làm được: ' . $p['errors'][0]]]];
            }
        } else {
            $result = $this->tripEdit($trip, $cmd, $ctx);
        }
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $runId = $this->gateway->log(['feature' => 'copilot', 'userId' => $userId, 'tripId' => (int) $trip->tripId, 'engine' => 'rules',
            'outcome' => in_array($result['kind'], ['proposal', 'tripedit'], true) ? 'proposed' : 'reply', 'inputChars' => mb_strlen($message), 'ms' => $ms,
            'flags' => ['command' => $cmd['cmd']], 'text' => $message]);
        if ($result['kind'] === 'proposal' && $runId) {
            $this->remember($result['id'], ['runId' => $runId]);
        }

        return ['ok' => true] + $result + ['ms' => $ms, 'steps' => 0, 'remaining' => $this->gateway->remaining($userId)];
    }

    /** Dời chuyến / đổi số ngày: mô phỏng trên bản sao chuyến, nêu rõ cái được và cái mất. */
    private function tripEdit(object $trip, array $cmd, array $ctx): array
    {
        $max = (int) \App\Services\Planner\TripPlanner::MAX_DAYS;
        $clone = clone $trip;
        $lines = [];
        if ($cmd['cmd'] === 'days') {
            $n = max(1, min($max, (int) $cmd['n']));
            if ($n !== (int) $cmd['n']) {
                $lines[] = 'Chuyến tối đa ' . $max . ' ngày.';
            }
            $clone->days = $n;
            $lost = array_values(array_filter($ctx['keys'], fn($k) => $k['day'] !== null && $k['day'] > $n));
            $title = 'Đổi chuyến thành ' . $n . ' ngày';
            if ($lost) {
                $lines[] = count($lost) . ' mục của ' . ($n + 1 === (int) $trip->days ? 'ngày ' . (int) $trip->days : 'ngày ' . ($n + 1) . '–' . (int) $trip->days) . ' sẽ về Đã lưu: '
                    . implode(', ', array_map(fn($k) => $k['name'], array_slice($lost, 0, 4))) . (count($lost) > 4 ? '…' : '') . '.';
            } elseif ($n > (int) $trip->days) {
                $lines[] = 'Thêm ' . ($n - (int) $trip->days) . ' ngày trống ở cuối chuyến để bạn (hoặc La bàn) xếp thêm.';
            }
            $patch = ['days' => $n];
        } else {
            if (!$trip->startDate) {
                return ['kind' => 'reply', 'say' => [['t' => 'Chuyến chưa có ngày đi. Bạn đặt ngày đi ở phần Thông tin chuyến trước nhé.']]];
            }
            $to = \Carbon\Carbon::parse($cmd['to']);
            if ($to->lt(now()->startOfDay())) {
                return ['kind' => 'reply', 'say' => [['t' => 'Ngày ' . $to->format('d/m/Y') . ' đã qua, La bàn không dời chuyến về quá khứ được.']]];
            }
            $clone->startDate = $to->toDateString();
            $title = 'Dời chuyến sang ' . $to->format('d/m/Y') . ' (' . ['', 'thứ Hai', 'thứ Ba', 'thứ Tư', 'thứ Năm', 'thứ Sáu', 'thứ Bảy', 'Chủ nhật'][$to->dayOfWeekIso] . ')';
            $books = array_values(array_filter($ctx['keys'], fn($k) => in_array($k['kind'] ?? null, ['lodging', 'transport'], true) || $k['type'] === 'transport'));
            if ($books) {
                $lines[] = 'Vé và chỗ ở đã có giữ ngày thật của đặt chỗ; mục nào ra ngoài ngày đi mới sẽ về Đã lưu và được nhắc ở phần Đặt chỗ.';
            }
            $patch = ['startDate' => $to->toDateString()];
        }
        $before = $this->health->forTrip($trip);
        $after = $this->health->forTrip($clone);
        $hardKey = fn(array $h) => array_values(array_map(fn($c) => $c['code'] . ':' . ($c['day'] ?? 0) . ':' . ($c['index'] ?? 0),
            array_filter($h['checks'], fn($c) => in_array($c['code'], \App\Services\Planner\Health\TripHealth::HARD, true))));
        $newHard = array_values(array_filter($after['checks'], fn($c) => in_array($c['code'], \App\Services\Planner\Health\TripHealth::HARD, true)
            && !in_array($c['code'] . ':' . ($c['day'] ?? 0) . ':' . ($c['index'] ?? 0), $hardKey($before), true)));
        $pick = fn($x) => ['day' => $x['day'] ?? null, 'text' => $x['text'], 'level' => $x['level'], 'code' => $x['code']];

        return ['kind' => 'tripedit', 'baseRev' => (int) ($trip->rev ?? 0), 'trip' => $patch,
            'say' => [['t' => $title . '.' . ($lines ? ' ' . implode(' ', $lines) : '')]],
            'receipt' => ['before' => ['score' => $before['score'], 'level' => $before['level']], 'after' => ['score' => $after['score'], 'level' => $after['level']],
                'warnings' => array_map($pick, array_slice($newHard, 0, 4)), 'newHard' => count($newHard), 'resolved' => [], 'effects' => [],
                'newWarn' => 0, 'checked' => ['giờ mở cửa theo thứ mới', 'thời tiết ngày mới', 'vé và chỗ ở']]];
    }

    /**
     * "Kiểm tra lại": lịch đã đổi sau khi La bàn đề xuất. Ánh xạ khóa cũ sang bản đã lưu MỚI theo danh tính mục, biên dịch và mô phỏng lại.
     * Không gọi LLM (miễn phí, tức thì).
     */
    public function recheck(object $trip, string $id): array
    {
        $saved = Cache::get('ai:cp:' . $id);
        if (!is_array($saved) || (int) $saved['tripId'] !== (int) $trip->tripId) {
            return ['ok' => false, 'kind' => 'reply', 'say' => [['t' => 'Đề xuất này đã hết hạn, bạn hỏi lại La bàn nhé.']]];
        }
        $ctx = $this->context($trip);
        $byId = [];
        foreach ($ctx['keys'] as $k => $x) {
            $idt = CopilotContext::identity($x);
            if (!isset($byId[$idt]) || ($x['day'] !== null && $ctx['keys'][$byId[$idt]]['day'] === null)) {
                $byId[$idt] = $k;
            }
        }
        $missing = [];
        $map = function (?string $old) use ($saved, $byId, &$missing): ?string {
            if ($old === null || !str_starts_with($old, 'i')) {
                return $old;
            }
            $idt = $saved['ids'][$old] ?? null;
            if ($idt === null || !isset($byId[$idt])) {
                $missing[] = $saved['names'][$old] ?? $old;

                return null;
            }

            return $byId[$idt];
        };
        $keyed = [];
        foreach ($saved['keyed'] as $c) {
            foreach (['item', 'after'] as $f) {
                if (isset($c[$f])) {
                    $c[$f] = $map($c[$f]);
                }
            }
            if (isset($c['order'])) {
                $c['order'] = array_map($map, $c['order']);
            }
            $keyed[] = $c;
        }
        if ($missing) {
            return ['ok' => false, 'kind' => 'reply', 'rev' => (int) ($trip->rev ?? 0),
                'say' => [['t' => 'Không áp dụng lại được: ' . implode(', ', array_unique($missing)) . ' không còn trong lịch. Bạn hỏi lại La bàn nhé.']]];
        }
        $explicit = array_values(array_filter(array_map($map, $saved['explicit'])));
        $p = $this->propose($trip, $ctx, $keyed, $saved['cands'], $explicit, $saved['say'], true, (int) ($saved['maxOps'] ?? KeyedChangeCompiler::MAX_OPS));
        if (!$p['ok']) {
            return ['ok' => false, 'kind' => 'reply', 'rev' => (int) ($trip->rev ?? 0), 'say' => [['t' => 'Lịch đã đổi nên đề xuất cũ không còn hợp: ' . $p['errors'][0]]]];
        }
        if (!empty($saved['runId'])) {
            $this->remember($p['body']['id'], ['runId' => $saved['runId']]);
        }

        return ['ok' => true, 'rev' => (int) ($trip->rev ?? 0), 'rechecked' => true] + $p['body'];
    }

    /**
     * Thẻ gợi ý của La bàn (TripSuggest) -> phiếu kiểm chứng: thao tác dạng khóa đã dựng sẵn bằng luật (không gọi LLM),
     * đi qua CÙNG bước biên dịch + mô phỏng TripHealth như đề xuất của LLM, rồi trả về để người dùng bấm Áp dụng.
     */
    public function proposeDirect(object $trip, ?int $userId, array $keyed, array $cands, array $explicit, string $say): array
    {
        $t0 = microtime(true);
        $ctx = $this->context($trip);
        $rev = (int) ($trip->rev ?? 0);
        $p = $this->propose($trip, $ctx, $keyed, $cands, $explicit, $say);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $runId = $this->gateway->log(['feature' => 'copilot', 'userId' => $userId, 'tripId' => (int) $trip->tripId, 'engine' => 'rules',
            'outcome' => $p['ok'] ? 'proposed' : 'refused', 'inputChars' => 0, 'ms' => $ms, 'flags' => ['suggest' => $keyed[0]['op'] ?? null]]);
        if (!$p['ok']) {
            return ['ok' => false, 'kind' => 'reply', 'rev' => $rev,
                'say' => [['t' => 'La bàn chưa thêm được gợi ý này vì sẽ làm lịch có vấn đề: ' . preg_replace('/^Đề xuất tạo vấn đề mới: /u', '', $p['errors'][0]) . ' Bạn thử ngày khác nhé.']]];
        }
        if ($runId) {
            $this->remember($p['body']['id'], ['runId' => $runId]);
        }

        return ['ok' => true, 'rev' => $rev, 'quick' => $ctx['quick'], 'ms' => $ms] + $p['body'];
    }

    /** Người dùng bấm Áp dụng / Bỏ qua: cập nhật outcome của lượt (đo tỷ lệ chấp nhận, mục 8 tài liệu). */
    public function feedback(object $trip, string $id, bool $applied): void
    {
        $saved = Cache::get('ai:cp:' . $id);
        if (!is_array($saved) || (int) $saved['tripId'] !== (int) $trip->tripId || empty($saved['runId'])) {
            return;
        }
        try {
            DB::table('tbl_ai_runs')->where('runId', (int) $saved['runId'])->update(['outcome' => $applied ? 'applied' : 'skipped']);
            // học từ hành vi: nơi La bàn đề xuất thêm / thay vào được áp dụng hay bị bỏ qua
            $with = [];
            foreach (array_keys((array) ($saved['cands'] ?? [])) as $k) {
                if (preg_match('/^c:(\w+):(\d+)$/', (string) $k, $m)) {
                    $with[] = ['with' => ['type' => $m[1], 'refId' => (int) $m[2]]];
                }
            }
            app(\App\Services\Planner\PlaceSignals::class)->recordProposal(isset($trip->userId) ? (int) session('userId') ?: (int) $trip->userId : null, (int) $trip->tripId, $with, $applied);
        } catch (Throwable) {
            // log là phụ
        }
    }

    // ------------------------------------------------------------------------------------------------

    private function context(object $trip): array
    {
        $payload = $this->planner->payload($trip);
        $health = $this->health->forTrip($trip);
        $dest = !empty($trip->destinationId) ? DB::table('tbl_destinations')->where('destinationId', (int) $trip->destinationId)->value('name') : null;
        $notes = isset($trip->notes) && trim((string) $trip->notes) !== '' ? ExplainFacts::clean(mb_substr((string) $trip->notes, 0, 300)) : null;

        return CopilotContext::build($payload, $health, $dest, $notes);
    }

    /**
     * Biên dịch + mô phỏng một đề xuất. ok=false kèm lỗi viết cho LLM (để sửa) / người dùng.
     * @return array{ok:bool,errors?:list<string>,body?:array}
     */
    private function propose(object $trip, array $ctx, array $keyed, array $cands, array $explicit, string $say, bool $verify = true, int $maxOps = KeyedChangeCompiler::MAX_OPS): array
    {
        $c = $this->compiler->compile($keyed, $ctx['keys'], $cands, $ctx['days'], $explicit, $maxOps);
        if (!$c['ok']) {
            return ['ok' => false, 'errors' => $c['errors']];
        }
        $changes = [];
        foreach ($c['changes'] as $ch) {
            if (isset($ch['with'])) {
                $full = $this->resolver->candidate($trip, $ch['with']['type'], (int) $ch['with']['refId']);
                if (!$full) {
                    return ['ok' => false, 'errors' => [$ch['with']['name'] . ' không còn trong kho Travela.']];
                }
                $ch['with'] = $full;
            }
            $changes[] = $ch;
        }
        try {
            $sim = $this->resolver->simulate($trip, $changes);
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'errors' => [$e->getMessage()]];
        }
        if ($verify && $sim['newHard'] > 0) {
            $txt = array_map(fn($x) => ($x['day'] ? 'Ngày ' . $x['day'] . ': ' : '') . $x['text'],
                array_values(array_filter($sim['newIssues'], fn($x) => $x['severity'] === 'hard')));

            return ['ok' => false, 'errors' => array_map(fn($t) => 'Đề xuất tạo vấn đề mới: ' . $t, array_slice($txt, 0, 3))];
        }

        $id = Str::random(24);
        $ids = [];
        $names = [];
        foreach ($ctx['keys'] as $k => $x) {
            $ids[$k] = CopilotContext::identity($x);
            $names[$k] = $x['name'];
        }
        $used = array_intersect_key($cands, array_flip(array_filter(array_merge(
            array_column($keyed, 'with'), ...array_map(fn($x) => (array) ($x['items'] ?? []), $keyed)
        ), 'is_string')));
        Cache::put('ai:cp:' . $id, ['tripId' => (int) $trip->tripId, 'rev' => (int) ($trip->rev ?? 0), 'keyed' => $keyed, 'ids' => $ids, 'names' => $names,
            'cands' => $used, 'explicit' => $explicit, 'say' => $say, 'maxOps' => $maxOps], 3600);

        // NumberGuard: số trong lời La bàn phải có trong bảng dữ kiện hoặc trong chính các bước đề xuất (vd giờ mới 08:30)
        $human = implode('; ', array_column($c['human'], 'text'));
        $unknown = $ctx['facts']['guard']->unknown(preg_replace('/\{\{[^}]*\}\}/u', ' ', $say) ?? '');
        $sayOk = $say !== '' && !array_diff($unknown, \App\Services\Ai\Explain\NumberGuard::extract($human));
        $sayText = $sayOk ? $say : 'Đề xuất: ' . $human . '.';
        $pick = fn($x) => ['day' => $x['day'], 'text' => $x['text'], 'level' => $x['level'], 'code' => $x['code']];

        return ['ok' => true, 'body' => [
            'kind' => 'proposal',
            'id' => $id,
            'baseRev' => (int) ($trip->rev ?? 0),
            'say' => $this->segments($sayText, $ctx, $cands),
            'steps_human' => $c['human'],
            'ghost' => $c['ghost'],
            'changes' => $changes,
            'receipt' => [
                'before' => $sim['before'], 'after' => $sim['after'],
                'resolved' => array_map($pick, array_slice($sim['resolvedIssues'], 0, 4)),
                'warnings' => array_map($pick, array_slice($sim['newIssues'], 0, 3)),
                'effects' => $sim['effects'],
                'newHard' => $sim['newHard'],
                'newWarn' => count($sim['newIssues']) - $sim['newHard'],
                'checked' => ['giờ mở cửa', 'trùng giờ', 'thời gian đi lại', 'độ dày ngày'],
            ],
        ]];
    }

    /** "Thêm {{c:place:4}} sau {{i2}}" -> [{t},{k,name,day},{t}]; khóa lạ bị bỏ (KeyGuard). */
    private function segments(string $text, array $ctx, array $cands): array
    {
        $out = [];
        $parts = preg_split('/(\{\{\s*[^}]*\s*\}\})/u', mb_substr($text, 0, 400), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($parts as $p) {
            if (preg_match('/^\{\{\s*([^}]*)\s*\}\}$/u', $p, $m)) {
                $k = trim($m[1]);
                if ($k === 'dest') {
                    $out[] = ['t' => $ctx['facts']['dest']];
                } elseif (isset($ctx['keys'][$k])) {
                    $out[] = ['k' => $k, 'name' => $ctx['keys'][$k]['name'], 'day' => $ctx['keys'][$k]['day']];
                } elseif (isset($cands[$k])) {
                    $out[] = ['k' => $k, 'name' => ExplainFacts::clean($cands[$k]['name']), 'day' => null, 'new' => true];
                }
                continue;
            }
            $out[] = ['t' => strip_tags($p)];
        }

        return $out ?: [['t' => '…']];
    }

    /** Câu trả lời thường (không đề xuất): bỏ những câu có số liệu không có trong bảng dữ kiện (NumberGuard). */
    /**
     * Đã dùng hết lượt tìm mà LLM vẫn muốn tìm tiếp, hoặc lời đáp HỨA sẽ tìm ("để em tìm thêm…") trong khi lượt đã kết thúc:
     * không để người dùng chờ một việc sẽ không xảy ra -> nói thật kết quả tìm. null = giữ lời LLM.
     */
    private function afterSearch(string $action, string $say, int $searches, array $cands): ?string
    {
        if ($searches === 0) {
            return null;
        }
        $promises = (bool) preg_match('/(đang|sẽ|để (em|tôi|mình)|thử) (tìm|kiếm)|tìm (thêm|lại|kiếm thêm)/iu', $say);
        if ($action !== 'search' && !$promises) {
            return null;
        }

        return $cands
            ? 'La bàn đã tìm được vài nơi nhưng chưa chọn xong trong một lượt. Bạn hỏi lại cụ thể hơn (một ngày, một loại nơi) nhé.'
            : 'La bàn đã tìm trong các địa điểm đã duyệt của Travela nhưng chưa thấy nơi phù hợp. '
                . 'Bạn thử loại khác (vd quán ăn thay vì cà phê), bỏ yêu cầu "gần…", hoặc tự thêm từ trang Khám phá nhé.';
    }

    private function groundedReply(string $say, array $ctx): string
    {
        $keep = [];
        foreach (preg_split('/(?<=[.!?…])\s+/u', trim($say)) ?: [] as $sent) {
            if ($sent !== '' && $ctx['facts']['guard']->passes(preg_replace('/\{\{[^}]*\}\}/u', ' ', $sent) ?? '')) {
                $keep[] = $sent;
            }
        }

        return $keep ? implode(' ', $keep) : 'Bạn nói rõ hơn mục hoặc ngày muốn sửa nhé.';
    }

    private function remember(string $id, array $extra): void
    {
        $v = Cache::get('ai:cp:' . $id);
        if (is_array($v)) {
            Cache::put('ai:cp:' . $id, $extra + $v, 3600);
        }
    }
}
