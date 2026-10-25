<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Content\ContentCatalog;
use Illuminate\Http\Request;

/** C2: /dang-vao-mua (điểm đến và lễ hội của một tháng) và /nen-di-thang-nao (ma trận 12 tháng). */
class SeasonPagesController extends Controller
{
    public function __construct(private ContentCatalog $catalog)
    {
        parent::__construct();
    }

    // GET /dang-vao-mua?thang=
    public function now(Request $r)
    {
        $explicit = ContentCatalog::validMonth($r->query('thang'));
        $month = $explicit ?? (int) date('n');
        $dest = $this->catalog->destinations(['thang' => $month], 1, 24);
        $events = $this->catalog->events(['thang' => $month], 1, 12);

        return view('clients.guide.season-now', [
            'title' => 'Đang vào mùa: tháng ' . $month,
            'month' => $month,
            'isCurrent' => $month === (int) date('n'),
            'destinations' => $dest['items'],
            'events' => $events['items'],
            'canonical' => route('seasons.now', $explicit ? ['thang' => $explicit] : []),
        ]);
    }

    /**
     * Gộp mức đẹp của từng điểm đến thành 1 hàng cho cả miền (heatmap 3 màu): điểm best = 2, ok = 1, avoid = 0, bỏ qua
     * "chưa có dữ liệu". Một tháng chỉ được xếp loại khi có dữ liệu của ít nhất nửa số điểm đến trong miền.
     * best nếu trung bình >= 1,4; ok nếu >= 0,8; còn lại avoid.
     *
     * @param \Illuminate\Support\Collection $groups domain => các dòng ['months' => [1..12 => level]]
     */
    private function summarize($groups): array
    {
        $out = [];
        foreach (ContentCatalog::REGIONS as $key => $label) {
            $rows = $groups->get($key);
            if (!$rows || $rows->isEmpty()) {
                continue;
            }
            $need = max(1, (int) ceil($rows->count() / 2));
            $levels = [];
            for ($m = 1; $m <= 12; $m++) {
                $b = $o = $a = 0;
                foreach ($rows as $r) {
                    match ($r['months'][$m] ?? 'unknown') {
                        'best' => $b++,
                        'ok' => $o++,
                        'avoid' => $a++,
                        default => null,
                    };
                }
                $known = $b + $o + $a;
                if ($known < $need) {
                    $levels[$m] = 'unknown';
                    continue;
                }
                $avg = (2 * $b + $o) / $known;
                $levels[$m] = $avg >= 1.4 ? 'best' : ($avg >= 0.8 ? 'ok' : 'avoid');
            }
            $out[$key] = [
                'label' => $label,
                'n' => $rows->count(),
                'levels' => $levels,
                'best' => self::runs($levels, 'best'),
                'avoid' => self::runs($levels, 'avoid'),
            ];
        }

        return $out;
    }

    /** [9 best, 10 best, 11 best, 3 best, 4 best] -> "3–4, 9–11". */
    private static function runs(array $levels, string $lv): string
    {
        $runs = [];
        $start = null;
        for ($m = 1; $m <= 13; $m++) {
            $on = $m <= 12 && ($levels[$m] ?? '') === $lv;
            if ($on && $start === null) {
                $start = $m;
            }
            if (!$on && $start !== null) {
                $end = $m - 1;
                $runs[] = $start === $end ? (string) $start : $start . '–' . $end;
                $start = null;
            }
        }

        return implode(', ', $runs);
    }

    // GET /nen-di-thang-nao  và  /nen-di-thang-nao/{thang}
    public function matrix(Request $r, ?int $thang = null)
    {
        abort_if($thang !== null && ($thang < 1 || $thang > 12), 404);
        $region = ContentCatalog::validRegion($r->query('mien'));
        $rows = collect($this->catalog->seasonMatrix($region))->groupBy('domain');
        $best = $thang ? $this->catalog->destinations(['thang' => $thang, 'mien' => $region], 1, 24)['items'] : null;

        return view('clients.guide.season-matrix', [
            'title' => $thang ? 'Nên đi tháng ' . $thang . ' ở đâu' : 'Nên đi tháng nào',
            'groups' => $rows,
            'summary' => $this->summarize($rows),
            'regions' => ContentCatalog::REGIONS,
            'region' => $region,
            'month' => $thang,
            'best' => $best,
            'filters' => ['mien' => $region],   // header tự gắn noindex khi đang lọc theo miền
            'canonical' => $thang ? route('seasons.matrix', ['thang' => $thang]) : route('seasons.matrix'),
        ]);
    }
}
