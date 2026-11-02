<?php

namespace App\Services\Ai\Eval;

use App\Services\Content\SearchCatalog;
use App\Services\Planner\OpeningHours;
use App\Services\Planner\PlannerService;
use Illuminate\Support\Facades\DB;

/**
 * Thí nghiệm A (tài liệu v2.2 mục 8.3): cùng một yêu cầu, ba cách sinh lịch, đo bằng CHÍNH TripHealth.
 *   engine   (A2)  PlannerEngine hiện tại (lọc cứng, chấm điểm, chia ngày theo vị trí, xếp giờ, kiểm giờ mở cửa)
 *   llm_data (A1b) LLM nhận ĐÚNG danh sách ứng viên engine nhận (tên, loại, tọa độ, giờ mở cửa từng ngày, thời lượng) và tự xếp lịch + giờ
 *   llm      (A1)  LLM tự viết lịch, không dữ liệu, không công cụ (mốc "LLM bịa")
 * So sánh chính là A1b với A2: cùng dữ liệu, khác ở chỗ có engine kiểm chứng hay không.
 */
final class PlanExperiment
{
    public const MODES = ['engine', 'llm_data', 'llm'];
    private const DOW = [1 => 'T2', 2 => 'T3', 3 => 'T4', 4 => 'T5', 5 => 'T6', 6 => 'T7', 7 => 'CN'];

    public function __construct(private EvalKit $kit, private PlannerService $planner) {}

    /** @param array{id:string,slug:string,days:int,startDate:string,groupSize?:int,interests?:list<string>,pace?:string,dislikes?:list<string>} $case */
    public function run(array $case, string $mode): array
    {
        $req = $case + ['month' => (int) date('n', strtotime($case['startDate'])), 'groupSize' => $case['groupSize'] ?? 2];
        $row = ['id' => $case['id'], 'mode' => $mode, 'slug' => $case['slug'], 'days' => $case['days']];

        return $this->kit->sandbox(function (int $uid) use ($req, $mode, $row) {
            $c = $this->planner->candidatesFor($req);
            if (!$c['ok']) {
                return $row + ['error' => 'không có điểm đến ' . $req['slug']];
            }
            $dest = $c['dest'];
            $nreq = $c['req'];
            $cands = $c['cands'];
            $byKey = [];
            foreach ($cands as $x) {
                $byKey[$x['key']] = $x;
            }

            if ($mode === 'engine') {
                $t0 = microtime(true);
                $res = $this->planner->generate($req, true);
                if (empty($res['ok'])) {
                    return $row + ['error' => $res['message'] ?? 'engine lỗi'];
                }
                $tripId = $this->planner->save($uid, $res);
                $out = ['out' => array_sum(array_map(fn($d) => count($d['items']), $res['plan']['days'])), 'exist' => 1.0, 'dup' => 0, 'ms' => EvalKit::ms($t0), 'in' => 0, 'tokOut' => 0];
                $placed = array_merge(...array_map(fn($d) => $d['items'], $res['plan']['days']));
            } else {
                [$items, $out, $err] = $mode === 'llm_data' ? $this->llmWithData($dest, $nreq, $cands, $byKey) : $this->llmAlone($dest, $nreq);
                if ($err) {
                    return $row + ['error' => $err];
                }
                $tripId = $this->kit->saveTrip($uid, (int) $dest->destinationId, $nreq['days'], $nreq['startDate'], $nreq['groupSize'], $items);
                $placed = array_values(array_filter(array_map(fn($i) => $byKey[$i['type'] . ':' . $i['refId']] ?? null, $items)));
            }
            $h = $this->kit->healthOf($tripId);

            return $row + $out + [
                'items' => $h['items'], 'hard' => $h['hard'], 'warn' => $h['warn'], 'score' => $h['score'], 'avgLoad' => $h['avgLoad'], 'maxLoad' => $h['maxLoad'],
                'interestCover' => $this->interestCover($nreq['interests'], $placed), 'codes' => $h['codes'],
            ];
        });
    }

    /** A1b: danh sách ứng viên thật (tối đa 120 dòng) -> LLM chọn khóa + giờ. */
    private function llmWithData(object $dest, array $req, array $cands, array $byKey): array
    {
        usort($cands, fn($a, $b) => [!($a['mustTry'] ?? false), $a['rank'] ?? 999] <=> [!($b['mustTry'] ?? false), $b['rank'] ?? 999]);
        $dows = [];
        for ($d = 1; $d <= $req['days']; $d++) {
            $dows[$d] = OpeningHours::dowOf($req['startDate'], $d);
        }
        $lines = [];
        foreach (array_slice($cands, 0, 120) as $x) {
            $hours = [];
            foreach ($dows as $d => $dow) {
                $t = OpeningHours::describe($x['hours'] ?? null, $dow);
                if ($t !== null) {
                    $hours[] = 'ngày ' . $d . ' (' . self::DOW[$dow] . '): ' . $t;
                }
            }
            $lines[] = $x['key'] . ' | ' . $x['name'] . ' | ' . $x['type'] . ($x['kind'] ?? null ? '/' . $x['kind'] : '') . ($x['group'] ?? null ? '/' . $x['group'] : '')
                . ' | ' . ($x['lat'] !== null ? round($x['lat'], 4) . ',' . round($x['lng'], 4) : 'không rõ vị trí')
                . ' | ' . (($x['durationMin'] ?? null) ? $x['durationMin'] . ' phút' : 'thời lượng tự ước')
                . ' | giờ mở cửa: ' . ($hours ? implode('; ', $hours) : 'chưa rõ');
        }
        $system = 'Bạn là chuyên gia lập lịch du lịch. Chỉ được chọn mục trong danh sách ứng viên (dùng đúng khóa ở cột đầu). '
            . 'Xếp lịch hợp lý: không chồng giờ, đủ thời gian di chuyển giữa các nơi, đúng giờ mở cửa của từng ngày, nhịp đi theo yêu cầu, hợp sở thích. '
            . 'Trả JSON {"days":[{"day":1,"items":[{"key":"place:12","start":"08:30"}]}]}.';
        $user = $this->requestText($dest, $req) . "\n\n# Ứng viên (khóa | tên | loại | tọa độ | thời lượng | giờ mở cửa theo ngày của chuyến)\n" . implode("\n", $lines);
        $r = $this->kit->llm('copilot', $system, $user, self::schema('key'));
        if (!$r['ok']) {
            return [[], [], 'LLM: ' . $r['error']];
        }
        $items = [];
        $total = $valid = $dup = 0;
        $seen = [];
        foreach ((array) ($r['data']['days'] ?? []) as $d) {
            $day = (int) ($d['day'] ?? 0);
            foreach ((array) ($d['items'] ?? []) as $it) {
                $total++;
                $k = (string) ($it['key'] ?? '');
                if (!isset($byKey[$k]) || $day < 1 || $day > $req['days']) {
                    continue;
                }
                $valid++;
                if (isset($seen[$k])) {
                    $dup++;
                    continue;
                }
                $seen[$k] = true;
                $items[] = ['type' => $byKey[$k]['type'], 'refId' => $byKey[$k]['refId'], 'day' => $day, 'startMin' => EvalKit::minutes($it['start'] ?? null)];
            }
        }

        return [$items, ['out' => $total, 'exist' => $total ? round($valid / $total, 3) : 0.0, 'dup' => $dup, 'ms' => $r['ms'], 'in' => $r['in'], 'tokOut' => $r['out'], 'model' => (string) ($r['model'] ?? '')], null];
    }

    /** A1: không dữ liệu. Tên LLM viết được đối chiếu với nội dung đã duyệt của điểm đến (khớp tên sau khi bỏ dấu). */
    private function llmAlone(object $dest, array $req): array
    {
        $system = 'Bạn là chuyên gia lập lịch du lịch Việt Nam. Viết lịch trình cụ thể với tên địa điểm, món ăn, trải nghiệm có thật và giờ bắt đầu. '
            . 'Trả JSON {"days":[{"day":1,"items":[{"name":"...","start":"08:30"}]}]}.';
        $r = $this->kit->llm('copilot', $system, $this->requestText($dest, $req), self::schema('name'));
        if (!$r['ok']) {
            return [[], [], 'LLM: ' . $r['error']];
        }
        $known = [];
        $id = (int) $dest->destinationId;
        foreach (DB::table('tbl_external_places')->where('destinationId', $id)->where('reviewStatus', 'published')->get(['placeId', 'name']) as $p) {
            $known[] = ['place', (int) $p->placeId, SearchCatalog::fold($p->name)];
        }
        foreach (DB::table('tbl_experiences')->where('destinationId', $id)->where('reviewStatus', 'published')->get(['experienceId', 'name']) as $x) {
            $known[] = ['experience', (int) $x->experienceId, SearchCatalog::fold($x->name)];
        }
        foreach (DB::table('tbl_foods')->where('destinationId', $id)->where('reviewStatus', 'published')->get(['foodId', 'name']) as $f) {
            $known[] = ['food', (int) $f->foodId, SearchCatalog::fold($f->name)];
        }
        $items = [];
        $total = $valid = $dup = 0;
        $seen = [];
        foreach ((array) ($r['data']['days'] ?? []) as $d) {
            $day = (int) ($d['day'] ?? 0);
            foreach ((array) ($d['items'] ?? []) as $it) {
                $total++;
                $n = SearchCatalog::fold((string) ($it['name'] ?? ''));
                $hit = null;
                foreach ($known as $k) {
                    if ($n !== '' && ($k[2] === $n || (mb_strlen($k[2]) >= 6 && str_contains($n, $k[2])) || (mb_strlen($n) >= 6 && str_contains($k[2], $n)))) {
                        $hit = $k;
                        break;
                    }
                }
                if (!$hit || $day < 1 || $day > $req['days']) {
                    continue;
                }
                $valid++;
                if (isset($seen[$hit[0] . $hit[1]])) {
                    $dup++;
                    continue;
                }
                $seen[$hit[0] . $hit[1]] = true;
                $items[] = ['type' => $hit[0], 'refId' => $hit[1], 'day' => $day, 'startMin' => EvalKit::minutes($it['start'] ?? null)];
            }
        }

        return [$items, ['out' => $total, 'exist' => $total ? round($valid / $total, 3) : 0.0, 'dup' => $dup, 'ms' => $r['ms'], 'in' => $r['in'], 'tokOut' => $r['out'], 'model' => (string) ($r['model'] ?? '')], null];
    }

    private function requestText(object $dest, array $req): string
    {
        $labels = array_map(fn($i) => config('interests.' . $i . '.label', $i), $req['interests']);

        return '# Yêu cầu' . "\n" . 'Điểm đến: ' . $dest->name . ' | ' . $req['days'] . ' ngày từ ' . date('d/m/Y', strtotime($req['startDate']))
            . ' (ngày 1 là ' . self::DOW[OpeningHours::dowOf($req['startDate'], 1)] . ') | ' . $req['groupSize'] . ' người | nhịp: '
            . ['relaxed' => 'thong thả', 'normal' => 'vừa phải', 'packed' => 'dày'][$req['pace']] . ' | sở thích: ' . ($labels ? implode(', ', $labels) : 'không nêu')
            . ($req['dislikes'] ? ' | không thích: ' . implode(', ', $req['dislikes']) : '');
    }

    private static function schema(string $field): array
    {
        return ['type' => 'object', 'properties' => ['days' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
            'day' => ['type' => 'integer'],
            'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [$field => ['type' => 'string'], 'start' => ['type' => 'string']], 'required' => [$field]]],
        ], 'required' => ['day', 'items']]]], 'required' => ['days']];
    }

    /** Tỷ lệ sở thích được phủ: mỗi sở thích có ít nhất một mục khớp (nhóm địa điểm, món ăn, từ khóa tên trải nghiệm). */
    private function interestCover(array $interests, array $placed): ?float
    {
        if (!$interests) {
            return null;
        }
        $hit = 0;
        foreach ($interests as $i) {
            $cfg = (array) config('interests.' . $i, []);
            foreach ($placed as $p) {
                $name = ' ' . SearchCatalog::fold((string) ($p['name'] ?? '')) . ' ';
                $ok = (($p['type'] ?? '') === 'place' && in_array($p['group'] ?? null, (array) ($cfg['place_groups'] ?? []), true))
                    || (($p['type'] ?? '') === 'food' && !empty($cfg['foods']))
                    || (($p['type'] ?? '') === 'experience' && array_filter((array) ($cfg['keywords'] ?? []), fn($k) => str_contains($name, ' ' . trim($k))));
                if ($ok) {
                    $hit++;
                    break;
                }
            }
        }

        return round($hit / count($interests), 3);
    }
}
