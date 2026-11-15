<?php

namespace App\Services\Planner\Route;

/**
 * Nguồn đường đi có bộ nhớ theo cặp điểm, tính sẵn một lần cho cả ngày (dùng khi thử hàng trăm thứ tự trong DayOptimizer).
 * Ô nào chưa có thì hỏi nguồn gốc (Haversine / OSRM) đúng một lần rồi nhớ lại.
 */
final class MatrixCache implements RouteProvider
{
    /** @var array<string, array{km:float,min:int,status:string}> */
    private array $pairs = [];

    public function __construct(private RouteProvider $inner) {}

    /** RouteService mới dùng bộ nhớ này, đã tính sẵn ma trận cho $pts. */
    public static function wrap(RouteService $route, array $pts): RouteService
    {
        $c = new self($route->rawProvider());
        if (count($pts) >= 2) {
            $c->matrix($pts);
        }

        return $route->withProvider($c);
    }

    public function name(): string
    {
        return $this->inner->name();
    }

    private static function key(array $p): string
    {
        return round((float) $p[0], 6) . ',' . round((float) $p[1], 6);
    }

    public function matrix(array $pts): array
    {
        $pts = array_values($pts);
        $keys = array_map([self::class, 'key'], $pts);
        $miss = false;
        foreach ($keys as $i => $a) {
            foreach ($keys as $j => $b) {
                if ($i !== $j && !isset($this->pairs[$a . '|' . $b])) {
                    $miss = true;
                    break 2;
                }
            }
        }
        if ($miss) {
            $m = RouteService::withStatus($this->inner->matrix($pts), RouteProvider::ESTIMATED);
            foreach ($keys as $i => $a) {
                foreach ($keys as $j => $b) {
                    $this->pairs[$a . '|' . $b] = $m[$i][$j];
                }
            }

            return $m;
        }
        $out = [];
        foreach ($keys as $i => $a) {
            foreach ($keys as $j => $b) {
                $out[$i][$j] = $i === $j ? ['km' => 0.0, 'min' => 0, 'status' => RouteProvider::VERIFIED] : $this->pairs[$a . '|' . $b];
            }
        }

        return $out;
    }
}
