<?php

namespace App\Services\Planner;

use App\Services\Ai\AiGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * A-13: danh sách đồ mang theo cho một chuyến đi.
 *
 * Hai lớp, giống các tính năng AI khác của Planner ("LLM đề xuất, luật làm nền"):
 *  1. Luật (luôn có): từ số ngày, số người, sở thích, loại điểm dừng và khí hậu tháng đi (tbl_destination_climate) -> danh sách cơ bản.
 *  2. LLM qua AiGateway (feature "packing"): nhận đúng các dữ kiện trên + danh sách luật, viết lại cho sát chuyến (thêm / bỏ món, lý do ngắn).
 *     Kết quả phải qua schema JSON; lỗi / tắt AI / hết hạn mức / ngắt mạch -> trả danh sách luật, gắn nhãn nguồn.
 * Kết quả được cache theo "dấu vân tay" của các dữ kiện, nên mở lại trang không gọi LLM lần nữa nếu chuyến không đổi.
 */
class PackingList
{
    public const FEATURE = 'packing';
    public const MAX_GROUPS = 7;
    public const MAX_ITEMS = 12;

    public function __construct(private AiGateway $ai) {}

    /** @return array{source:string,groups:list<array{name:string,items:list<array{name:string,why:?string}>}>,facts:array,note:?string} */
    public function forTrip(object $trip, ?int $userId, bool $useAi = true): array
    {
        $facts = $this->facts($trip);
        $base = $this->rules($facts);
        $key = 'packing:' . $trip->tripId . ':' . md5(json_encode($facts) . (string) config('ai.prompt_version.packing', 'packing-1'));
        if ($hit = Cache::get($key)) {
            return $hit;
        }
        $out = ['source' => 'rules', 'groups' => $base, 'facts' => $facts, 'note' => null];

        if ($useAi && $this->ai->blocked(self::FEATURE, $userId) === null) {
            $r = $this->ai->json(self::FEATURE, $userId, $this->system(), $this->prompt($facts, $base), $this->schema());
            $groups = $r['ok'] ? $this->clean($r['data']['groups'] ?? []) : [];
            $this->ai->log(['feature' => self::FEATURE, 'userId' => $userId, 'tripId' => (int) $trip->tripId, 'engine' => $groups ? 'llm' : 'rules',
                'outcome' => $groups ? 'ok' : ($r['reason'] ?? 'empty'), 'model' => $r['model'] ?? null, 'in' => $r['in'] ?? null, 'out' => $r['out'] ?? null,
                'ms' => $r['ms'] ?? 0, 'error' => $r['error'] ?? null, 'inputChars' => strlen(json_encode($facts))]);
            if ($groups) {
                $out = ['source' => 'ai', 'groups' => $groups, 'facts' => $facts,
                    'note' => isset($r['data']['note']) ? mb_substr(strip_tags((string) $r['data']['note']), 0, 240) : null];
            }
        }
        // chỉ cache kết quả AI lâu; kết quả luật rẻ, cache ngắn để bật AI lại là có ngay
        Cache::put($key, $out, $out['source'] === 'ai' ? now()->addDays(7) : now()->addMinutes(10));

        return $out;
    }

    /** Dữ kiện của chuyến (cũng là "dấu vân tay" cho cache). */
    public function facts(object $trip): array
    {
        $dest = $trip->destinationId ? DB::table('tbl_destinations')->where('destinationId', $trip->destinationId)->first(['name', 'province', 'domain', 'slug']) : null;
        $month = $trip->startDate ? (int) date('n', strtotime((string) $trip->startDate)) : (isset($trip->month) && $trip->month ? (int) $trip->month : null);
        $climate = ($trip->destinationId && $month)
            ? DB::table('tbl_destination_climate')->where(['destinationId' => $trip->destinationId, 'month' => $month])->first(['tMax', 'tMin', 'rainMm', 'rainyDays'])
            : null;
        $items = DB::table('tbl_trip_items')->where('tripId', $trip->tripId)->get(['refType', 'label', 'note']);
        $text = mb_strtolower($items->map(fn($i) => $i->label . ' ' . $i->note)->implode(' ') . ' ' . ($trip->title ?? '') . ' ' . ($dest->name ?? ''));
        $has = fn(array $words) => (bool) array_filter($words, fn($w) => str_contains($text, $w));

        return [
            'destination' => $dest ? $dest->name . ($dest->province && $dest->province !== $dest->name ? ', ' . $dest->province : '') : null,
            'days' => max(1, (int) ($trip->days ?: 1)),
            'people' => max(1, (int) ($trip->groupSize ?: 1)),
            'month' => $month,
            'tMin' => $climate ? (float) $climate->tMin : null,
            'tMax' => $climate ? (float) $climate->tMax : null,
            'rainyDays' => $climate ? (int) $climate->rainyDays : null,
            'interests' => array_values(array_filter((array) json_decode((string) ($trip->interests ?? ''), true), 'is_string')),
            'beach' => $has(['biển', 'bãi ', 'đảo', 'lặn', 'snorkel', 'tắm']),
            'trek' => $has(['trek', 'leo', 'đi bộ', 'fansipan', 'núi', 'đèo', 'hang']),
            'temple' => $has(['chùa', 'đền', 'miếu', 'nhà thờ', 'lăng']),
            'boat' => $has(['thuyền', 'tàu', 'du thuyền', 'thúng', 'ghe']),
            'tour' => $items->contains(fn($i) => $i->refType === 'tour'),
        ];
    }

    /** Lớp 1: danh sách theo luật. */
    public function rules(array $f): array
    {
        $d = $f['days'];
        $cold = $f['tMin'] !== null && $f['tMin'] < 16;
        $veryCold = $f['tMin'] !== null && $f['tMin'] < 10;
        $hot = $f['tMax'] !== null && $f['tMax'] >= 30;
        $rainy = $f['rainyDays'] !== null && $f['rainyDays'] >= 10;
        $kids = in_array('family', $f['interests'], true);
        $sets = min($d, 4) . ($d > 4 ? ' (giặt giữa chuyến)' : '');

        $g = [];
        $g['Giấy tờ và tiền'] = array_filter([
            ['CCCD / hộ chiếu của từng người', 'Nhận phòng, mua vé tham quan'],
            $f['tour'] ? ['Mã đơn đặt tour', 'Xuất trình khi gặp hướng dẫn viên'] : null,
            ['Ít tiền mặt mệnh giá nhỏ', 'Chợ, gửi xe, quán nhỏ không nhận thẻ'],
            ['Thẻ ngân hàng / ví điện tử', null],
        ]);
        $g['Quần áo'] = array_filter([
            ["{$sets} bộ quần áo hằng ngày", null],
            $veryCold ? ['Áo khoác dày, mũ len, găng tay', 'Đêm có thể dưới ' . (int) $f['tMin'] . '°C'] : ($cold ? ['Áo khoác nhẹ', 'Sáng tối khoảng ' . (int) $f['tMin'] . '°C'] : null),
            $hot ? ['Áo chống nắng, mũ rộng vành', 'Trưa khoảng ' . (int) $f['tMax'] . '°C'] : null,
            $f['beach'] ? ['Đồ bơi, dép đi biển', null] : null,
            $f['temple'] ? ['Áo có tay, quần / váy quá gối', 'Vào chùa, đền cần ăn mặc kín đáo'] : null,
            ['Đồ ngủ', null],
            $f['trek'] ? ['Giày thể thao / giày leo núi bám tốt', 'Có đoạn đi bộ đường dốc'] : ['Giày đi bộ thoải mái', null],
        ]);
        $g['Mưa nắng'] = array_filter([
            $rainy ? ['Áo mưa / ô gấp', 'Tháng này trung bình ' . $f['rainyDays'] . ' ngày mưa'] : ['Ô gấp nhỏ', null],
            ['Kem chống nắng, kính râm', null],
            $f['boat'] || $f['beach'] ? ['Túi chống nước cho điện thoại', 'Đi thuyền / ra biển'] : null,
        ]);
        $g['Vệ sinh cá nhân'] = [
            ['Bàn chải, kem đánh răng', null],
            ['Khăn mặt nhỏ, khăn giấy ướt', null],
            ['Kem dưỡng, son dưỡng', $cold ? 'Trời lạnh dễ khô da' : null],
        ];
        $g['Thuốc và sơ cứu'] = array_filter([
            ['Thuốc cá nhân đang dùng', 'Mang dư 1–2 ngày'],
            ['Băng cá nhân, dầu gió', null],
            $f['boat'] ? ['Thuốc chống say tàu xe', null] : null,
            ['Kem chống muỗi', null],
        ]);
        $g['Thiết bị'] = array_filter([
            ['Sạc điện thoại, sạc dự phòng', null],
            $d >= 3 ? ['Ổ cắm kéo dài nhỏ', 'Phòng thường ít ổ cắm'] : null,
            $f['trek'] ? ['Đèn pin / đèn đội đầu', null] : null,
            ['Bình nước cá nhân', null],
        ]);
        if ($kids || $f['people'] >= 4) {
            $g['Cho trẻ nhỏ'] = array_filter([
                $kids ? ['Đồ ăn vặt, sữa quen dùng', null] : null,
                $kids ? ['Quần áo thay thêm 1–2 bộ', null] : null,
                ['Túi đựng đồ bẩn / túi nilon', null],
            ]);
        }

        $out = [];
        foreach ($g as $name => $items) {
            $items = array_values(array_filter($items));
            if ($items) {
                $out[] = ['name' => $name, 'items' => array_map(fn($i) => ['name' => $i[0], 'why' => $i[1]], $items)];
            }
        }

        return $out;
    }

    private function system(): string
    {
        return 'Bạn soạn danh sách đồ mang theo cho một chuyến du lịch ở Việt Nam, viết tiếng Việt ngắn gọn. '
            . 'Chỉ dựa vào dữ kiện được cho (điểm đến, tháng, nhiệt độ, số ngày ngày mưa, số người, sở thích, loại hoạt động). '
            . 'Bắt đầu từ danh sách gợi ý theo luật: giữ món hợp lý, bỏ món không cần, thêm món còn thiếu cho đúng chuyến này. '
            . 'Không bịa số liệu thời tiết, không quảng cáo thương hiệu, không khuyên dùng thuốc kê đơn. '
            . 'Tối đa ' . self::MAX_GROUPS . ' nhóm, mỗi nhóm tối đa ' . self::MAX_ITEMS . ' món; "why" để trống nếu không cần giải thích.';
    }

    private function prompt(array $f, array $base): string
    {
        return "Dữ kiện chuyến đi:\n" . json_encode($f, JSON_UNESCAPED_UNICODE)
            . "\n\nDanh sách theo luật:\n" . json_encode($base, JSON_UNESCAPED_UNICODE)
            . "\n\nTrả JSON theo schema: groups[] {name, items[] {name, why}}, note (một câu lưu ý quan trọng nhất, có thể rỗng).";
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['groups', 'note'],
            'properties' => [
                'note' => ['type' => 'string'],
                'groups' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['name', 'items'],
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'items' => ['type' => 'array', 'items' => [
                            'type' => 'object', 'additionalProperties' => false, 'required' => ['name', 'why'],
                            'properties' => ['name' => ['type' => 'string'], 'why' => ['type' => 'string']],
                        ]],
                    ],
                ]],
            ],
        ];
    }

    /** Kiểm lại đầu ra LLM: cắt độ dài, bỏ trùng, bỏ HTML; quá ít món thì coi như hỏng. */
    private function clean(array $groups): array
    {
        $out = [];
        $seen = [];
        $total = 0;
        foreach (array_slice($groups, 0, self::MAX_GROUPS) as $g) {
            $name = mb_substr(trim(strip_tags((string) ($g['name'] ?? ''))), 0, 40);
            $items = [];
            foreach (array_slice((array) ($g['items'] ?? []), 0, self::MAX_ITEMS) as $it) {
                $n = mb_substr(trim(strip_tags((string) ($it['name'] ?? ''))), 0, 80);
                $k = mb_strtolower($n);
                if ($n === '' || isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = true;
                $why = trim(strip_tags((string) ($it['why'] ?? '')));
                $items[] = ['name' => $n, 'why' => $why !== '' ? mb_substr($why, 0, 120) : null];
            }
            if ($name !== '' && $items) {
                $out[] = ['name' => $name, 'items' => $items];
                $total += count($items);
            }
        }

        return $total >= 5 ? $out : [];
    }
}
