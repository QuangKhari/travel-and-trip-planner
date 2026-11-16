<?php

namespace App\Services\Ai\Explain;

use App\Services\Ai\AiGateway;
use Illuminate\Support\Facades\Cache;

/**
 * Lời giải thích ngắn cho một thẻ cảnh báo của La bàn (dông, mưa, nắng nóng, đóng cửa, lệch giờ vé...).
 * Hệ thống đã quyết định: thẻ nào hiện, cách sửa nào được đề xuất (mỗi cách đã qua TripHealth).
 * LLM chỉ được diễn đạt lại dữ kiện của thẻ: vì sao đáng lo, nên chọn cách nào.
 * Chốt chặn: mọi con số phải có trong dữ kiện (NumberGuard), không quá 2 câu mỗi phần, chỉ được nhắc
 * tới cách sửa có trong danh sách. Sai một điều -> dùng mẫu soạn sẵn.
 */
final class AlertExplainer
{
    public function __construct(private AiGateway $gateway) {}

    /**
     * @param array{id:string,title:string,sub?:string,text?:string,source?:string,day?:int,actions?:list<array>} $card
     * @return array{why:string,fix:?string,engine:string,notice:?string}
     */
    public function explain(array $card, ?int $userId, ?int $tripId = null): array
    {
        $facts = self::facts($card);
        $key = 'alert-explain:' . md5(json_encode($facts, JSON_UNESCAPED_UNICODE) . config('ai.prompt_version.alert', 'alert-1'));
        if ($hit = Cache::get($key)) {
            return $hit + ['engine' => 'cache', 'notice' => null];
        }
        $t0 = microtime(true);
        $res = $this->gateway->json('explain', $userId, self::system(), json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), self::schema());
        $flags = [];
        $out = null;
        if ($res['ok'] && is_array($res['data'] ?? null)) {
            $out = self::check($res['data'], $facts, $flags);
        }
        $engine = $out ? 'llm' : 'template';
        $out = $out ?? self::template($facts);
        $this->gateway->log(['feature' => 'explain', 'userId' => $userId, 'tripId' => $tripId, 'engine' => $engine,
            'outcome' => $engine === 'llm' ? 'ok' : ($flags ? 'guard_replaced' : ($res['reason'] ?? 'fallback')),
            'model' => $res['model'] ?? null, 'in' => $res['in'] ?? null, 'out' => $res['out'] ?? null,
            'ms' => (int) round((microtime(true) - $t0) * 1000), 'flags' => array_merge(['alert'], $flags), 'error' => $res['error'] ?? null]);
        if ($engine === 'llm') {
            Cache::put($key, $out, now()->addHours(6));
        }

        return $out + ['engine' => $engine, 'notice' => $engine === 'template' && !$flags ? AiGateway::reasonText($res['reason'] ?? 'disabled') : null];
    }

    /** @return array{code:?string,title:string,place:string,detail:string,source:string,day:?int,options:list<string>} */
    public static function facts(array $card): array
    {
        $opts = [];
        foreach ((array) ($card['actions'] ?? []) as $a) {
            if (!empty($a['label'])) {
                $opts[] = trim((string) $a['label'] . (!empty($a['hint']) ? ' (' . $a['hint'] . ')' : ''));
            }
        }

        preg_match('/^alert:([a-z]+\.[a-z]+)/', (string) ($card['id'] ?? ''), $m);

        return ['code' => $m[1] ?? null, 'title' => (string) ($card['title'] ?? ''), 'place' => (string) ($card['sub'] ?? ''), 'detail' => (string) ($card['text'] ?? ''),
            'source' => (string) ($card['source'] ?? ''), 'day' => isset($card['day']) ? (int) $card['day'] : null, 'options' => array_slice($opts, 0, 4)];
    }

    public static function system(): string
    {
        return "Bạn viết lời giải thích ngắn bằng tiếng Việt cho một cảnh báo trong lịch du lịch.\n"
            . "Chỉ dùng dữ kiện được đưa. Không thêm con số, giờ, tên nơi hay lời khuyên nào không có trong dữ kiện.\n"
            . "why: tối đa 2 câu, nói vì sao cảnh báo này ảnh hưởng tới chuyến đi.\n"
            . "fix: tối đa 2 câu, nói nên chọn cách nào trong 'options' và vì sao; option phải chép đúng nguyên văn trong ngoặc kép. Không có options thì để trống.\n"
            . "Giọng bình thường, không cảm thán, không biểu tượng.";
    }

    public static function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['why', 'fix'],
            'properties' => ['why' => ['type' => 'string', 'maxLength' => 320], 'fix' => ['type' => 'string', 'maxLength' => 320]]];
    }

    /** Kiểm tra lời LLM; trả null nếu không đạt (ghi lý do vào $flags). */
    public static function check(array $data, array $facts, array &$flags): ?array
    {
        $why = trim((string) ($data['why'] ?? ''));
        $fix = trim((string) ($data['fix'] ?? ''));
        if ($why === '') {
            $flags[] = 'empty';
            return null;
        }
        $src = array_merge([$facts['title'], $facts['place'], $facts['detail'], $facts['source']], $facts['options']);
        $guard = new NumberGuard($src, array_filter([$facts['day']]));
        foreach (['why' => $why, 'fix' => $fix] as $k => $t) {
            if ($t !== '' && !$guard->passes($t)) {
                $flags[] = 'number:' . $k;
            }
            if (mb_strlen($t) > 320 || preg_match_all('/[.!?](\s|$)/u', $t) > 2) {
                $flags[] = 'long:' . $k;
            }
        }
        if ($fix !== '') {
            if (!$facts['options']) {
                $fix = '';
            } else {
                // phải nhắc đúng một cách sửa có trong danh sách (so phần nhãn, bỏ phần gợi ý trong ngoặc)
                $named = array_filter($facts['options'], function ($o) use ($fix) {
                    $label = trim(preg_replace('/\s*\(.*\)$/u', '', $o));
                    return $label !== '' && mb_stripos($fix, $label) !== false;
                });
                if (!$named) {
                    $flags[] = 'option';
                }
            }
        }

        return $flags ? null : ['why' => $why, 'fix' => $fix !== '' ? $fix : null];
    }

    /** Lời giải thích soạn sẵn theo loại cảnh báo: nói hệ quả (thẻ đã nêu dữ kiện), không lặp lại câu của thẻ. */
    public static function template(array $facts): array
    {
        $p = $facts['place'] !== '' ? '“' . $facts['place'] . '”' : 'Hoạt động này';
        $why = match ($facts['code'] ?? null) {
            'weather.storm' => $p . ' ở ngoài trời đúng lúc dự báo có dông hoặc gió mạnh; đi vẫn được nhưng dễ phải bỏ dở hoặc chờ.',
            'weather.rain' => $p . ' ở ngoài trời đúng khung giờ có khả năng mưa, trải nghiệm sẽ kém và đường đi chậm hơn.',
            'weather.heat' => $p . ' rơi vào giữa trưa nắng nóng; đổi sang sáng sớm hoặc chiều muộn sẽ dễ chịu hơn.',
            'hours.closed' => $p . ' nghỉ đúng ngày bạn xếp, tới nơi sẽ không vào được; các mục sau trong ngày cũng lệch theo.',
            default => trim($facts['detail'] !== '' ? $facts['detail'] : $facts['title']),
        };
        if ($facts['source'] !== '') {
            $why = rtrim($why, '. ') . '. Căn cứ: ' . $facts['source'] . '.';
        }
        $fix = $facts['options'] ? 'Cách đề xuất trước: "' . $facts['options'][0] . '" (đã kiểm tra lại giờ mở cửa và quãng đường). Lịch chỉ đổi khi bạn xác nhận.' : null;

        return ['why' => $why, 'fix' => $fix];
    }
}
