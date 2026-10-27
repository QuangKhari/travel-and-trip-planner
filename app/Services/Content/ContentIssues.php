<?php

namespace App\Services\Content;

use Illuminate\Support\Facades\DB;

/**
 * Danh sách lỗi dữ liệu cho Content Quality Dashboard (kế hoạch v6, mục 9): thiếu ảnh bìa, nguồn, ghi công, tọa độ, mô tả;
 * lễ hội hết ngày; cẩm nang quá hạn; trải nghiệm chưa vào được Planner; điểm đến thiếu lịch trình / tour.
 * Chỉ xét nội dung đã xuất bản (nội dung nháp chưa public nên chưa phải lỗi). Dùng PublishGate để không có hai bộ quy tắc.
 */
final class ContentIssues
{
    public const CODES = [
        'no_source' => 'Thiếu nguồn / giấy phép',
        'no_credit' => 'Có ảnh nhưng thiếu ghi công ảnh',
        'no_description' => 'Thiếu mô tả',
        'no_coords' => 'Thiếu tọa độ hợp lệ',
        'event_stale' => 'Lễ hội không còn ngày sắp tới',
        'guide_stale' => 'Cẩm nang quá hạn cập nhật',
        'planner_gap' => 'Trải nghiệm chưa vào được Planner',
        'dest_gap' => 'Điểm đến thiếu dữ liệu nền',
        'other' => 'Lỗi khác',
    ];

    private const KINDS = [
        'foods' => ['tbl_foods', 'foodId', 'name'],
        'experiences' => ['tbl_experiences', 'experienceId', 'name'],
        'events' => ['tbl_events', 'eventId', 'name'],
        'itineraries' => ['tbl_itineraries', 'itineraryId', 'title'],
        'guides' => ['tbl_guides', 'guideId', 'title'],
    ];

    /**
     * @param list<int>|null $destinationIds null = tất cả
     * @return list<array{code:string,kind:string,id:?int,title:string,destinationId:?int,problem:string}>
     */
    public function all(?array $destinationIds = null, int $perTable = 1000): array
    {
        $out = [];
        foreach (self::KINDS as $kind => [$table, $key, $titleCol]) {
            $q = DB::table($table)->where('reviewStatus', 'published');
            $hasDest = $kind !== 'itineraries' && $kind !== 'guides';
            if ($destinationIds !== null && $hasDest) {
                $q->whereIn('destinationId', $destinationIds);
            } elseif ($destinationIds !== null) {
                continue;   // lịch trình và cẩm nang không gắn điểm đến: chỉ hiện ở bản "tất cả"
            }
            foreach ($q->limit($perTable)->get() as $row) {
                $problems = PublishGate::problems($kind, $row);
                if ($kind === 'guides' && !empty($row->updatedOn) && $row->updatedOn < date('Y-m-d', strtotime('-' . (int) config('coverage.guide_stale_days', 365) . ' days'))) {
                    $problems[] = 'Quá ' . (int) config('coverage.guide_stale_days', 365) . ' ngày chưa cập nhật (cập nhật cuối ' . $row->updatedOn . ')';
                }
                if ($kind === 'experiences') {
                    foreach (PublishGate::plannerProblems($kind, $row) as $p) {
                        $problems[] = 'Planner: ' . $p;
                    }
                }
                foreach ($problems as $p) {
                    $out[] = [
                        'code' => self::classify($p), 'kind' => $kind, 'id' => (int) $row->$key, 'title' => (string) $row->$titleCol,
                        'destinationId' => $hasDest && $row->destinationId !== null ? (int) $row->destinationId : null, 'problem' => $p,
                    ];
                }
            }
        }

        // Địa điểm OSM đã xuất bản: thiếu mô tả hoặc tọa độ
        $q = DB::table('tbl_external_places')->where('reviewStatus', 'published');
        if ($destinationIds !== null) {
            $q->whereIn('destinationId', $destinationIds);
        }
        foreach ($q->limit(3000)->get() as $p) {
            if (trim((string) ($p->description ?? '')) === '') {
                $out[] = ['code' => 'no_description', 'kind' => 'places', 'id' => (int) $p->placeId, 'title' => (string) $p->name, 'destinationId' => (int) $p->destinationId, 'problem' => 'Thiếu mô tả địa điểm'];
            }
            $lat = $p->latitude !== null ? (float) $p->latitude : null;
            $lng = $p->longitude !== null ? (float) $p->longitude : null;
            if ($lat === null || $lng === null || ($lat == 0.0 && $lng == 0.0) || abs($lat) > 90 || abs($lng) > 180) {
                $out[] = ['code' => 'no_coords', 'kind' => 'places', 'id' => (int) $p->placeId, 'title' => (string) $p->name, 'destinationId' => (int) $p->destinationId, 'problem' => 'Tọa độ thiếu hoặc không hợp lệ'];
            }
        }

        // Điểm đến thiếu dữ liệu nền: dùng kết quả chấm điểm để cùng một nguồn với CLI/Admin
        foreach ((new CoverageScorer())->scoreAll() as $s) {
            if ($destinationIds !== null && !in_array($s['destinationId'], $destinationIds, true)) {
                continue;
            }
            $base = ['code' => 'dest_gap', 'kind' => 'destinations', 'id' => $s['destinationId'], 'title' => $s['name'], 'destinationId' => $s['destinationId']];
            $c = $s['criteria'];
            if (!$s['hasCover']) {
                $out[] = $base + ['problem' => 'Thiếu ảnh bìa'];
            } elseif (!$s['hasCredit']) {
                $out[] = ['code' => 'no_credit'] + $base + ['problem' => 'Ảnh bìa thiếu ghi công'];
            }
            if (!$s['hasIntro']) {
                $out[] = $base + ['problem' => 'Thiếu phần giới thiệu (dưới ' . (int) config('coverage.intro_min_chars', 80) . ' ký tự)'];
            }
            if ($c['months']['have'] < 12) {
                $out[] = $base + ['problem' => 'Mới có ' . $c['months']['have'] . '/12 tháng'];
            }
            if ($c['climate']['have'] < 12) {
                $out[] = $base + ['problem' => 'Mới có ' . $c['climate']['have'] . '/12 tháng khí hậu'];
            }
            if ($c['itineraries']['have'] < 1) {
                $out[] = $base + ['problem' => 'Chưa có lịch trình một điểm đến'];
            }
            if ($c['tours']['applicable'] && $c['tours']['have'] < 1) {
                $out[] = $base + ['problem' => 'Chưa có tour liên kết'];
            }
        }

        return $out;
    }

    /** Gom theo mã lỗi: [code => số lượng]. */
    public static function countByCode(array $issues): array
    {
        $c = array_fill_keys(array_keys(self::CODES), 0);
        foreach ($issues as $i) {
            $c[$i['code']] = ($c[$i['code']] ?? 0) + 1;
        }

        return array_filter($c);
    }

    public static function classify(string $problem): string
    {
        $p = mb_strtolower($problem);

        return match (true) {
            str_contains($p, 'ghi công') => 'no_credit',
            str_contains($p, 'nguồn') || str_contains($p, 'giấy phép') => 'no_source',
            str_contains($p, 'planner') => 'planner_gap',
            str_contains($p, 'quy tắc ngày') => 'event_stale',
            str_contains($p, 'quá') && str_contains($p, 'ngày chưa cập nhật') => 'guide_stale',
            str_contains($p, 'mô tả') || str_contains($p, 'nội dung') => 'no_description',
            default => 'other',
        };
    }
}
