<?php

namespace App\Services\Content;

use Illuminate\Support\Facades\DB;

/** Lưu và đọc lịch sử Coverage (mỗi điểm đến một dòng mỗi ngày). */
final class CoverageHistory
{
    /** @param list<array<string,mixed>> $scores kết quả CoverageScorer::scoreAll() */
    public function snapshot(array $scores, ?string $day = null): int
    {
        $day ??= date('Y-m-d');
        $n = 0;
        DB::transaction(function () use ($scores, $day, &$n) {
            foreach ($scores as $s) {
                DB::table('tbl_coverage_history')->updateOrInsert(
                    ['destinationId' => $s['destinationId'], 'day' => $day],
                    ['percent' => $s['percent'], 'earned' => $s['earned'], 'applicable' => $s['applicable'], 'plannerReady' => $s['plannerReady'] ? 1 : 0,
                     'counts' => json_encode($s['counts']), 'createdAt' => date('Y-m-d H:i:s')]
                );
                $n++;
            }
        });

        return $n;
    }

    /**
     * Các mốc gần nhất của mỗi điểm đến, cũ đến mới.
     *
     * @param list<int> $destinationIds
     * @return array<int, list<array{day:string,percent:int,plannerReady:bool}>>
     */
    public function recent(array $destinationIds, int $perDest = 8): array
    {
        if (!$destinationIds) {
            return [];
        }
        $out = [];
        foreach (DB::table('tbl_coverage_history')->whereIn('destinationId', $destinationIds)->orderByDesc('day')->limit(max(1, $perDest) * count($destinationIds) * 2)->get() as $r) {
            $d = (int) $r->destinationId;
            if (count($out[$d] ?? []) < $perDest) {
                $out[$d][] = ['day' => (string) $r->day, 'percent' => (int) $r->percent, 'plannerReady' => (bool) $r->plannerReady];
            }
        }

        return array_map('array_reverse', $out);
    }
}
