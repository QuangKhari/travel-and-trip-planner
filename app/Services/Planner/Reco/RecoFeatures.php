<?php

namespace App\Services\Planner\Reco;

use App\Services\Planner\PlaceSignals;
use App\Support\Geo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Đặc trưng cho mô hình cá nhân hóa (Đợt D, L2). Mọi đặc trưng phụ thuộc lịch sử chỉ dùng dữ liệu TRƯỚC thời điểm $t
 * (lúc gợi ý hiện ra) để đánh giá offline không "nhìn trước tương lai".
 *
 * Nhóm đặc trưng:
 *   nơi       : loại (place / food / experience), nhóm loại nơi (kind_groups), giá (log), miễn phí, trong nhà, thời lượng, độ nổi bật
 *   ngữ cảnh  : vị trí trong danh sách (để khử thiên lệch vị trí khi học; lúc dùng đặt cố định), khoảng cách tới điểm tham chiếu
 *   người     : điểm sở thích theo nhóm tính tới $t (cùng công thức PlaceSignals), độ giống nội dung với các nơi người đó đã chọn (TF-IDF)
 *   cộng đồng : tỷ lệ người KHÁC giữ / ghé nơi này tới $t (làm mượt)
 * Hàm thuần sau khi nạp dữ liệu (load), dùng chung cho huấn luyện, đánh giá và lúc gợi ý thật.
 */
final class RecoFeatures
{
    private const W = ['added' => 1, 'visited' => 2, 'applied' => 1, 'removed' => -1, 'skipped' => -2, 'ignored' => -0.5];

    /** @var array<string, object> "type:id" => thông tin nơi */
    private array $items = [];
    /** @var array<int, list<array{t:int,key:string,grp:string,signal:string}>> tín hiệu theo người, tăng dần theo thời gian */
    private array $signals = [];
    /** @var array<string, array<string,float>> vector TF-IDF đã chuẩn hóa */
    private array $tfidf = [];
    private array $groups;

    public function __construct()
    {
        $this->groups = array_keys((array) config('planner.kind_groups', []));
    }

    /** Nạp thông tin nơi + tín hiệu cần cho các cặp (người, nơi) sẽ dùng. */
    public function load(array $keys, array $userIds = []): self
    {
        $by = [];
        foreach (array_unique($keys) as $k) {
            [$t, $id] = explode(':', $k) + [1 => 0];
            $by[$t][] = (int) $id;
        }
        if (!empty($by['place']) || !empty($by['food'])) {
            $ids = array_merge($by['place'] ?? [], $by['food'] ?? []);
            foreach (DB::table('tbl_external_places')->whereIn('placeId', $ids)
                         ->get(['placeId', 'name', 'category', 'kind', 'priceMin', 'indoor', 'durationMinutes', 'rank', 'latitude', 'longitude', 'description']) as $p) {
                $info = (object) ['type' => 'place', 'name' => $p->name, 'category' => $p->category, 'kind' => $p->kind, 'price' => $p->priceMin,
                    'indoor' => $p->indoor, 'dur' => $p->durationMinutes, 'rank' => $p->rank, 'lat' => $p->latitude, 'lng' => $p->longitude,
                    'text' => $p->name . ' ' . $p->kind . ' ' . $p->category . ' ' . $p->description];
                $this->items['place:' . $p->placeId] = $info;
                $this->items['food:' . $p->placeId] = $info;
            }
        }
        if (!empty($by['experience']) && \Illuminate\Support\Facades\Schema::hasTable('tbl_experiences')) {
            foreach (DB::table('tbl_experiences')->whereIn('experienceId', $by['experience'])->get() as $x) {
                $this->items['experience:' . $x->experienceId] = (object) ['type' => 'experience', 'name' => $x->name ?? '', 'category' => 'sight',
                    'kind' => null, 'price' => $x->priceMin ?? null, 'indoor' => $x->indoor ?? null, 'dur' => $x->durationMinutes ?? null, 'rank' => null,
                    'lat' => $x->latitude ?? null, 'lng' => $x->longitude ?? null, 'text' => ($x->name ?? '') . ' ' . ($x->category ?? '') . ' ' . ($x->description ?? '')];
            }
        }
        if (PlaceSignals::ready()) {
            $q = DB::table('tbl_place_signals')->orderBy('createdAt')->orderBy('signalId');
            foreach ($q->get(['userId', 'refType', 'refId', 'grp', 'signal', 'createdAt']) as $s) {
                $this->signals[(int) $s->userId][] = ['t' => strtotime((string) $s->createdAt), 'key' => $s->refType . ':' . $s->refId,
                    'grp' => (string) ($s->grp ?? 'see'), 'signal' => $s->signal];
            }
        }
        $this->buildTfidf();

        return $this;
    }

    /** Thêm / thay thông tin một nơi (dùng trong kiểm thử và bộ mô phỏng). */
    public function withItem(string $key, object $info): self
    {
        $this->items[$key] = $info;

        return $this;
    }

    /** @param list<array{userId:int,t:int,key:string,grp:string,signal:string}> $signals */
    public function withSignals(array $signals): self
    {
        $this->signals = [];
        usort($signals, fn($a, $b) => $a['t'] <=> $b['t']);
        foreach ($signals as $s) {
            $this->signals[$s['userId']][] = $s;
        }

        return $this;
    }

    public function rebuild(): self
    {
        $this->buildTfidf();

        return $this;
    }

    public function info(string $key): ?object
    {
        return $this->items[$key] ?? null;
    }

    public function groupOf(string $key): string
    {
        $i = $this->items[$key] ?? null;
        [$type] = explode(':', $key);

        return PlaceSignals::groupOf($type, $i->kind ?? null);
    }

    /** Tên các đặc trưng theo thứ tự cố định. */
    public function names(): array
    {
        return array_merge(['bias', 't_food', 't_exp', 'cat_food'], array_map(fn($g) => 'g_' . $g, $this->groups),
            ['price_log', 'free', 'indoor', 'dur_h', 'pop', 'pos_inv', 'dist_log', 'aff', 'aff_conf', 'sim', 'glob', 'aff_x_pop']);
    }

    /**
     * @param array $ctx ngữ cảnh lúc hiện (lat/lng tham chiếu, position)
     * @return array<string,float>
     */
    public function vector(int $userId, string $key, int $t, array $ctx = []): array
    {
        $i = $this->items[$key] ?? null;
        [$type] = explode(':', $key);
        $grp = PlaceSignals::groupOf($type, $i->kind ?? null);
        $f = array_fill_keys($this->names(), 0.0);
        $f['bias'] = 1.0;
        $f['t_food'] = $type === 'food' ? 1.0 : 0.0;
        $f['t_exp'] = $type === 'experience' ? 1.0 : 0.0;
        $f['cat_food'] = ($i->category ?? null) === 'food' ? 1.0 : 0.0;
        if (isset($f['g_' . $grp])) {
            $f['g_' . $grp] = 1.0;
        }
        if ($i) {
            $f['price_log'] = $i->price !== null ? min(1.0, log10(1 + (float) $i->price) / 6) : 0.0;
            $f['free'] = $i->price !== null && (float) $i->price == 0.0 ? 1.0 : 0.0;
            $f['indoor'] = (float) ($i->indoor ?? 0);
            $f['dur_h'] = $i->dur ? min(1.0, (int) $i->dur / 240) : 0.0;
            $f['pop'] = $i->rank !== null ? min(1.0, (float) $i->rank / 10) : 0.3;
            if (isset($ctx['lat'], $ctx['lng']) && $i->lat !== null) {
                $f['dist_log'] = min(1.0, log(1 + Geo::distanceKm((float) $ctx['lat'], (float) $ctx['lng'], (float) $i->lat, (float) $i->lng)) / 4);
            }
        }
        $f['pos_inv'] = isset($ctx['position']) ? 1 / (1 + (int) $ctx['position']) : 0.0;
        $f['aff'] = $this->affinityAt($userId, $t)[$grp] ?? 0.0;
        // độ tin của sở thích tăng theo số tín hiệu đã có: giúp mô hình học đúng khi dữ liệu cũ còn ít lịch sử hơn dữ liệu mới
        $f['aff_conf'] = $f['aff'] * min(1.0, $this->historyAt($userId, $t) / 10);
        $f['sim'] = $this->similarityAt($userId, $key, $t);
        $f['glob'] = $this->globalAt($userId, $key, $t);
        $f['aff_x_pop'] = $f['aff'] * $f['pop'];

        return $f;
    }

    /** Điểm sở thích theo nhóm tới thời điểm $t (cùng công thức PlaceSignals::profile, không gồm phần người dùng tự sửa). */
    public function affinityAt(int $userId, int $t): array
    {
        $acc = [];
        foreach ($this->signals[$userId] ?? [] as $s) {
            if ($s['t'] >= $t) {
                break;
            }
            $acc[$s['grp']]['s'] = ($acc[$s['grp']]['s'] ?? 0) + self::W[$s['signal']];
            $acc[$s['grp']]['n'] = ($acc[$s['grp']]['n'] ?? 0) + 1;
        }

        return array_map(fn($x) => max(-1, min(1, $x['s'] / ($x['n'] + 4))), $acc);
    }

    public function historyAt(int $userId, int $t): int
    {
        $n = 0;
        foreach ($this->signals[$userId] ?? [] as $s) {
            if ($s['t'] >= $t) {
                break;
            }
            $n++;
        }

        return $n;
    }

    /** Cosine TF-IDF giữa nơi này và trung bình các nơi người dùng đã thêm / ghé / áp dụng trước $t. */
    public function similarityAt(int $userId, string $key, int $t): float
    {
        $v = $this->tfidf[$key] ?? null;
        if (!$v) {
            return 0.0;
        }
        $profile = [];
        $n = 0;
        foreach ($this->signals[$userId] ?? [] as $s) {
            if ($s['t'] >= $t) {
                break;
            }
            if ($s['key'] === $key || !in_array($s['signal'], ['added', 'visited', 'applied'], true) || empty($this->tfidf[$s['key']])) {
                continue;
            }
            foreach ($this->tfidf[$s['key']] as $w => $x) {
                $profile[$w] = ($profile[$w] ?? 0) + $x;
            }
            $n++;
        }
        if (!$n) {
            return 0.0;
        }
        $dot = 0.0;
        $norm = 0.0;
        foreach ($profile as $w => $x) {
            $norm += $x * $x;
            $dot += $x * ($v[$w] ?? 0);
        }

        return $norm > 0 ? max(0.0, min(1.0, $dot / sqrt($norm))) : 0.0;
    }

    /** Tỷ lệ người khác giữ / ghé nơi này trước $t, làm mượt về 0. */
    public function globalAt(int $userId, string $key, int $t): float
    {
        $s = 0.0;
        $n = 0;
        foreach ($this->signals as $uid => $list) {
            if ($uid === $userId) {
                continue;
            }
            foreach ($list as $x) {
                if ($x['t'] >= $t) {
                    break;
                }
                if ($x['key'] === $key) {
                    $s += self::W[$x['signal']];
                    $n++;
                }
            }
        }

        return $n ? max(-1, min(1, $s / ($n + 4))) : 0.0;
    }

    private function buildTfidf(): void
    {
        $docs = [];
        foreach ($this->items as $k => $i) {
            $docs[$k] = self::tokens((string) ($i->text ?? $i->name ?? ''));
        }
        $df = [];
        foreach ($docs as $toks) {
            foreach (array_unique($toks) as $w) {
                $df[$w] = ($df[$w] ?? 0) + 1;
            }
        }
        $n = max(1, count($docs));
        $this->tfidf = [];
        foreach ($docs as $k => $toks) {
            $tf = array_count_values($toks);
            $vec = [];
            foreach ($tf as $w => $c) {
                $vec[$w] = (1 + log($c)) * log(1 + $n / $df[$w]);
            }
            $norm = sqrt(array_sum(array_map(fn($x) => $x * $x, $vec))) ?: 1.0;
            $this->tfidf[$k] = array_map(fn($x) => $x / $norm, $vec);
        }
    }

    /** Từ đơn + cặp từ, bỏ dấu, bỏ từ quá ngắn. */
    public static function tokens(string $s): array
    {
        $w = array_values(array_filter(preg_split('/[^a-z0-9]+/', strtolower(Str::ascii($s))) ?: [], fn($x) => strlen($x) >= 2));
        $out = $w;
        for ($i = 1; $i < count($w); $i++) {
            $out[] = $w[$i - 1] . '_' . $w[$i];
        }

        return $out;
    }
}
