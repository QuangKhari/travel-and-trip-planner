<?php

namespace App\Services\Planner\Route;

use App\Services\Planner\PlannerRules;

/**
 * Mọi phép tính tuyến đường của Planner đi qua đây (PlannerEngine, TripHealth, quanh tuyến, tối ưu tuyến).
 *
 *  - estimate($a, $b)       : một đoạn đường {km, min}
 *  - distanceMatrix($pts)   : ma trận đoạn đường
 *  - travelTime($pts)       : các đoạn theo thứ tự + tổng (bỏ đoạn trong cùng một khu < MIN_LEG_KM như trình duyệt)
 *  - detour($route, $p)     : độ lệch khỏi tuyến, quãng phải đi thêm khi ghé, ghé sau điểm thứ mấy
 *  - optimize($stops)       : sắp lại các điểm "tự do", giữ nguyên điểm cố định giờ / khóa vị trí / có buổi
 *
 * Nguồn dữ liệu (RouteProvider) chọn bằng config('planner.route.provider'): haversine (mặc định) hoặc osrm.
 * Tham số (hệ số đường vòng, tốc độ theo phương tiện, ngưỡng "cùng một khu") đọc qua PlannerRules::route():
 * trình duyệt nhận đúng các số này (cfg.rules.route) nên bản xem trước ở trình duyệt khớp máy chủ khi dùng ước tính.
 */
class RouteService
{
    public const MIN_LEG_KM = 0.5;   // mặc định; giá trị thật lấy từ config planner.route.min_leg_km

    public function __construct(private RouteProvider $provider, private float $minLegKm = self::MIN_LEG_KM, private string $mode = 'driving', private float $factor = 1.3) {}

    /** Theo config('planner'); $mode = null là phương tiện mặc định. */
    public static function make(?string $mode = null): self
    {
        return self::fromRules(PlannerRules::fromConfig()->route($mode));
    }

    /** @param array{provider:string,mode:string,factor:float,speedKmh:float,minLegKm:float,osrmProfile:string,osrmUrl:string} $r */
    public static function fromRules(array $r): self
    {
        $hav = new HaversineProvider($r['factor'], $r['speedKmh']);
        $provider = $r['provider'] === 'osrm' ? new OsrmProvider($r['osrmUrl'], $hav, 6, $r['osrmProfile']) : $hav;

        return new self($provider, $r['minLegKm'], $r['mode'], $r['factor']);
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function minLegKm(): float
    {
        return $this->minLegKm;
    }

    /**
     * Một đoạn đi giữa hai điểm theo quy tắc chung: gần hơn minLegKm (chim bay) thì coi như cùng khu, không tính.
     * @return array{km:float,min:int}
     */
    public function leg(array $a, array $b): array
    {
        if (HaversineProvider::straightKm($a, $b) < $this->minLegKm) {
            return ['km' => 0.0, 'min' => 0];
        }

        return $this->estimate($a, $b);
    }

    /** Phút đi xe/đi bộ cho một quãng km (dùng cho quãng đi vòng thêm của "quanh tuyến"). */
    public function minutesFor(float $km): int
    {
        $speed = method_exists($this->provider, 'speedKmh') ? $this->provider->speedKmh() : PlannerRules::fromConfig()->route($this->mode)['speedKmh'];

        return (int) round($km / max(0.1, $speed) * 60);
    }

    /** Nguồn THỰC SỰ đã dùng: "osrm" chỉ khi mọi yêu cầu tới giờ đều lấy được từ OSRM; có lần phải dự phòng thì là "haversine". */
    public function provider(): string
    {
        return $this->status()['actual'];
    }

    /**
     * Minh bạch nguồn số liệu tuyến cho giao diện:
     * requested = nguồn cấu hình, actual = nguồn đã dùng, fallback = có phải dùng ước tính thay OSRM không, reason = vì sao.
     * @return array{requested:string,actual:string,fallback:bool,reason:?string,mode:string}
     */
    public function status(): array
    {
        $req = $this->provider->name();
        $fb = method_exists($this->provider, 'fellBack') && $this->provider->fellBack();

        return ['requested' => $req, 'actual' => $fb ? 'haversine' : $req, 'fallback' => $fb,
            'reason' => $fb && method_exists($this->provider, 'lastError') ? $this->provider->lastError() : null, 'mode' => $this->mode];
    }

    /** @return array{km:float,min:int} */
    public function estimate(array $a, array $b): array
    {
        return $this->provider->matrix([$a, $b])[0][1];
    }

    public function distanceMatrix(array $pts): array
    {
        return $this->provider->matrix(array_values($pts));
    }

    /**
     * @param array<int,array{0:float,1:float}> $pts theo thứ tự đi
     * @return array{legs:array<int,array{from:int,to:int,km:float,min:int}>,km:float,min:int}
     */
    public function travelTime(array $pts): array
    {
        $pts = array_values($pts);
        $legs = [];
        $km = 0.0;
        $min = 0;
        if (count($pts) < 2) {
            return ['legs' => [], 'km' => 0.0, 'min' => 0];
        }
        $m = $this->provider->matrix($pts);
        for ($i = 1; $i < count($pts); $i++) {
            if (HaversineProvider::straightKm($pts[$i - 1], $pts[$i]) < $this->minLegKm) {
                continue;   // cùng một khu: đi bộ vài phút, không tính
            }
            $l = $m[$i - 1][$i];
            $legs[] = ['from' => $i - 1, 'to' => $i, 'km' => $l['km'], 'min' => $l['min']];
            $km += $l['km'];
            $min += $l['min'];
        }

        return ['legs' => $legs, 'km' => $km, 'min' => $min];
    }

    /**
     * Đo điểm P so với tuyến: offKm (khoảng cách ngắn nhất tới tuyến), detourKm (quãng đi thêm khi ghé), after (ghé sau điểm thứ mấy).
     *
     * @param array<int,array{0:float,1:float}> $route
     * @return array{offKm:float,detourKm:float,after:int}
     */
    public function detour(array $route, float $lat, float $lng): array
    {
        $f = $this->factor;
        $route = array_values($route);
        $p = [$lat, $lng];
        if (count($route) === 1) {
            $d = HaversineProvider::straightKm($route[0], $p);

            return ['offKm' => round($d, 2), 'detourKm' => round(2 * $d * $f, 1), 'after' => 0];
        }
        $best = null;
        for ($i = 0; $i < count($route) - 1; $i++) {
            $a = $route[$i];
            $b = $route[$i + 1];
            $off = self::segDist($a, $b, $p);
            $detour = max(0.0, HaversineProvider::straightKm($a, $p) + HaversineProvider::straightKm($p, $b) - HaversineProvider::straightKm($a, $b));
            if ($best === null || $detour < $best['detourKm']) {
                $best = ['offKm' => $off, 'detourKm' => $detour, 'after' => $i];
            }
        }
        $n = count($route) - 1;
        $tail = HaversineProvider::straightKm($route[$n], $p);   // đi tiếp tới P sau điểm cuối ngày: chỉ một chiều
        if ($tail < $best['detourKm']) {
            $best = ['offKm' => min($best['offKm'], $tail), 'detourKm' => $tail, 'after' => $n];
        }

        return ['offKm' => round($best['offKm'], 2), 'detourKm' => round($best['detourKm'] * $f, 1), 'after' => $best['after']];
    }

    /**
     * Tối ưu thứ tự trong một ngày có ràng buộc.
     * Mỗi điểm: ['key' => string, 'lat' => ?float, 'lng' => ?float, 'anchor' => bool]. Điểm không có tọa độ hoặc anchor = true
     * (giờ cố định, khóa vị trí, đã chọn buổi, hoặc điểm đầu ngày khi keepFirst) giữ NGUYÊN chỗ; các điểm còn lại hoán vị trong những chỗ "tự do".
     * Thuật toán: dựng bằng láng giềng gần nhất (đi qua cả điểm neo) rồi cải thiện bằng đổi chỗ / đảo đoạn (2-opt trên các chỗ tự do)
     * cho tới khi không còn cải thiện. Hàm thuần: không ghi gì, chỉ trả đề xuất để giao diện xem trước.
     *
     * @return array{order:array<int,string>,beforeKm:float,afterKm:float,savedKm:float,savedMin:int,changed:bool,moved:int}
     */
    public function optimize(array $stops): array
    {
        $stops = array_values($stops);
        $pos = [];      // chỉ số các điểm có tọa độ, theo thứ tự hiện tại
        foreach ($stops as $i => $s) {
            if (isset($s['lat'], $s['lng']) && $s['lat'] !== null && $s['lng'] !== null) {
                $pos[] = $i;
            }
        }
        $pts = array_map(fn($i) => [(float) $stops[$i]['lat'], (float) $stops[$i]['lng']], $pos);
        $m = $this->provider->matrix($pts);
        $len = function (array $seq) use ($m): float {
            $s = 0.0;
            for ($k = 1; $k < count($seq); $k++) {
                $s += $m[$seq[$k - 1]][$seq[$k]]['km'];
            }

            return $s;
        };
        $cur = range(0, count($pos) - 1);   // thứ tự (theo chỉ số trong $pts)
        $free = [];                          // các CHỖ trong $cur được phép đổi
        foreach ($cur as $slot => $pi) {
            if (empty($stops[$pos[$pi]]['anchor'])) {
                $free[] = $slot;
            }
        }
        $before = $len($cur);
        $best = $cur;
        if (count($free) >= 2) {
            // 1) láng giềng gần nhất: đi theo chỗ, chỗ tự do lấy điểm tự do gần điểm trước nhất
            $pool = array_map(fn($s) => $cur[$s], $free);
            $nn = $cur;
            foreach ($cur as $slot => $pi) {
                if (!in_array($slot, $free, true)) {
                    continue;
                }
                $prev = $slot > 0 ? $nn[$slot - 1] : null;
                $pick = 0;
                if ($prev !== null) {
                    $bd = INF;
                    foreach ($pool as $k => $cand) {
                        if ($m[$prev][$cand]['km'] < $bd) {
                            $bd = $m[$prev][$cand]['km'];
                            $pick = $k;
                        }
                    }
                }
                $nn[$slot] = $pool[$pick];
                array_splice($pool, $pick, 1);
            }
            if ($len($nn) < $len($best)) {
                $best = $nn;
            }
            // 2) cải thiện: đổi chỗ hai điểm tự do, hoặc đảo đoạn giữa hai chỗ tự do (chỉ đảo các phần tử tự do bên trong)
            $guard = 0;
            do {
                $improved = false;
                for ($a = 0; $a < count($free) - 1; $a++) {
                    for ($b = $a + 1; $b < count($free); $b++) {
                        $cand = $best;
                        [$cand[$free[$a]], $cand[$free[$b]]] = [$cand[$free[$b]], $cand[$free[$a]]];
                        $rev = $best;
                        $inner = array_slice($free, $a, $b - $a + 1);
                        $vals = array_reverse(array_map(fn($s) => $best[$s], $inner));
                        foreach ($inner as $k => $s) {
                            $rev[$s] = $vals[$k];
                        }
                        foreach ([$cand, $rev] as $c) {
                            if ($len($c) + 1e-9 < $len($best)) {
                                $best = $c;
                                $improved = true;
                            }
                        }
                    }
                }
            } while ($improved && ++$guard < 60);
        }
        $after = $len($best);
        // ghép lại thứ tự đầy đủ: điểm không tọa độ giữ nguyên chỗ
        $order = array_map(fn($s) => (string) $s['key'], $stops);
        foreach ($best as $slot => $pi) {
            $order[$pos[$slot]] = (string) $stops[$pos[$pi]]['key'];
        }
        $moved = 0;
        foreach ($order as $i => $k) {
            if ($k !== (string) $stops[$i]['key']) {
                $moved++;
            }
        }
        $saved = max(0.0, $before - $after);

        return ['order' => $order, 'beforeKm' => round($before, 2), 'afterKm' => round($after, 2), 'savedKm' => round($saved, 2),
            'savedMin' => $this->minutesFor($saved), 'changed' => $moved > 0 && $saved >= 0.2, 'moved' => $moved];
    }

    /** Khoảng cách (km) từ P tới đoạn AB, chiếu phẳng cục bộ (đủ chính xác trong vài chục km). */
    private static function segDist(array $a, array $b, array $p): float
    {
        $k = 111.32;
        $cos = cos(deg2rad(($a[0] + $b[0] + $p[0]) / 3));
        [$ax, $ay] = [$a[1] * $k * $cos, $a[0] * $k];
        [$bx, $by] = [$b[1] * $k * $cos, $b[0] * $k];
        [$px, $py] = [$p[1] * $k * $cos, $p[0] * $k];
        $dx = $bx - $ax;
        $dy = $by - $ay;
        $len2 = $dx * $dx + $dy * $dy;
        $t = $len2 > 0 ? max(0.0, min(1.0, (($px - $ax) * $dx + ($py - $ay) * $dy) / $len2)) : 0.0;

        return sqrt(($px - $ax - $t * $dx) ** 2 + ($py - $ay - $t * $dy) ** 2);
    }
}
