<?php

namespace App\Services\Planner;

use App\Services\Content\CoverageScorer;

/**
 * Cổng Planner-ready (kế hoạch v6, mục 0.2 và tuần 4): Planner chỉ tự đề xuất lịch trình cho điểm đến ĐỦ dữ liệu.
 * Điều kiện nằm ở config('coverage.planner_ready') và do CoverageScorer tính, nên Admin, CLI và Planner luôn thấy cùng một kết quả.
 */
final class PlannerGate
{
    public function __construct(private ?CoverageScorer $scorer = null)
    {
        $this->scorer ??= new CoverageScorer();
    }

    /** @return array{known:bool,ready:bool,missing:list<string>,percent:int}  known=false khi điểm đến không tồn tại/đã tắt */
    public function status(int $destinationId): array
    {
        $s = $this->scorer->score($destinationId);
        if (!$s) {
            return ['known' => false, 'ready' => false, 'missing' => ['Điểm đến không tồn tại'], 'percent' => 0];
        }

        return ['known' => true, 'ready' => (bool) $s['plannerReady'], 'missing' => $s['plannerMissing'], 'percent' => (int) $s['percent']];
    }

    public function isReady(int $destinationId): bool
    {
        return $this->status($destinationId)['ready'];
    }

    /**
     * Tình trạng của các điểm đến lõi (config/core_destinations.php); mảng slugs rỗng thì xét mọi điểm đến đang bật.
     *
     * @return list<array{slug:string,name:string,destinationId:int,ready:bool,missing:list<string>,percent:int}>
     */
    public function coreStatus(): array
    {
        $slugs = (array) config('core_destinations.slugs', []);
        $out = [];
        foreach ($this->scorer->scoreAll($slugs ?: null) as $s) {
            $out[] = ['slug' => $s['slug'], 'name' => $s['name'], 'destinationId' => (int) $s['destinationId'],
                'ready' => (bool) $s['plannerReady'], 'missing' => $s['plannerMissing'], 'percent' => (int) $s['percent']];
        }

        return $out;
    }
}
