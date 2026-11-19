<?php

namespace App\Modules\Content;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Giao diện ĐỌC danh mục tour của phân hệ Content (tbl_tours, tbl_tour_stops, tbl_timeline) cho Planner và AI.
 * Planner / AI không viết truy vấn vào bảng tour nữa (config/modules.php: deny_tables); mọi câu hỏi "tour nào còn bán,
 * tour này mấy ngày, lịch trình ghé đâu" đi qua đây, nên đổi cách lưu tour (vd tách nội dung khỏi lịch khởi hành) chỉ sửa một chỗ.
 *
 * Mỗi dòng tour trả về cùng một bộ cột: tourId, title, time, description, startDate, endDate, quantity, priceAdult, priceChild,
 * destinationId, destName, dLat, dLng.
 */
final class TourCatalog
{
    private const COLS = ['t.tourId', 't.title', 't.time', 't.description', 't.startDate', 't.endDate', 't.quantity', 't.priceAdult', 't.priceChild',
        't.destinationId', 'd.name as destName', 'd.latitude as dLat', 'd.longitude as dLng'];

    /**
     * Tour đang mở bán.
     *
     * @param array{destId?:?int,destIds?:list<int>,after?:string,from?:string,to?:string,endFrom?:string,seats?:bool,near?:?string,
     *              title?:?string,limit?:int,withDest?:bool} $f
     *   after: khởi hành SAU ngày này (mặc định hôm nay); from / to: khoảng ngày khởi hành (gồm hai đầu); endFrom: thay cho after,
     *   lấy cả tour đang diễn ra (ngày về >= endFrom); seats: còn chỗ (quantity > 0, mặc định có); near: sắp theo độ gần ngày này
     *   (không có thì theo ngày khởi hành); withDest: chỉ tour có điểm đến (inner join).
     * @return Collection<int,object>
     */
    public function bookable(array $f = []): Collection
    {
        $q = DB::table('tbl_tours as t')->where('t.availability', 1);
        !empty($f['withDest'])
            ? $q->join('tbl_destinations as d', 'd.destinationId', '=', 't.destinationId')
            : $q->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 't.destinationId');
        if ($f['seats'] ?? true) {
            $q->where('t.quantity', '>', 0);
        }
        if (!empty($f['endFrom'])) {
            $q->whereDate('t.endDate', '>=', $f['endFrom']);
        } elseif (!empty($f['from'])) {
            $q->whereDate('t.startDate', '>=', $f['from']);
        } else {
            $q->whereDate('t.startDate', '>', $f['after'] ?? date('Y-m-d'));
        }
        if (!empty($f['to'])) {
            $q->whereDate('t.startDate', '<=', $f['to']);
        }
        if (!empty($f['destId'])) {
            $q->where('t.destinationId', (int) $f['destId']);
        }
        if (array_key_exists('destIds', $f)) {
            $q->whereIn('t.destinationId', array_map('intval', (array) $f['destIds']));
        }
        if (!empty($f['title'])) {
            $q->where('t.title', 'like', $f['title']);
        }
        !empty($f['near']) ? $q->orderByRaw('ABS(DATEDIFF(t.startDate, ?))', [$f['near']])->orderBy('t.tourId') : $q->orderBy('t.startDate')->orderBy('t.tourId');

        return $q->limit(max(1, (int) ($f['limit'] ?? 30)))->get(self::COLS);
    }

    /** Số tour còn chỗ và giá người lớn thấp nhất theo điểm đến, khởi hành trong [from, to] và từ hôm nay. @return array<int,array{count:int,minPrice:?float}> */
    public function countByDestination(string $from, string $to): array
    {
        $out = [];
        $rows = DB::table('tbl_tours')->whereNotNull('destinationId')->where('availability', 1)->where('quantity', '>', 0)
            ->whereBetween('startDate', [$from, $to])->whereDate('startDate', '>=', date('Y-m-d'))
            ->selectRaw('destinationId, COUNT(*) AS c, MIN(priceAdult) AS p')->groupBy('destinationId')->get();
        foreach ($rows as $r) {
            $out[(int) $r->destinationId] = ['count' => (int) $r->c, 'minPrice' => $r->p !== null ? (float) $r->p : null];
        }

        return $out;
    }

    /** Một tour (kể cả đã ngừng bán); $bookable: chỉ khi còn mở bán và còn chỗ. */
    public function find(int $tourId, bool $bookable = false): ?object
    {
        $q = DB::table('tbl_tours as t')->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 't.destinationId')->where('t.tourId', $tourId);
        if ($bookable) {
            $q->where('t.availability', 1)->where('t.quantity', '>', 0);
        }

        return $q->first(self::COLS);
    }

    /** @param list<int> $ids @return Collection<int,object> theo tourId */
    public function findMany(array $ids): Collection
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return collect();
        }

        return DB::table('tbl_tours as t')->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 't.destinationId')
            ->whereIn('t.tourId', $ids)->get(self::COLS)->keyBy('tourId');
    }

    /** Chữ lịch trình (tiêu đề + mô tả từng chặng) của nhiều tour. @param list<int> $ids @return Collection<int,Collection> theo tourId */
    public function timelines(array $ids): Collection
    {
        if (!$ids || !Schema::hasTable('tbl_timeline')) {
            return collect();
        }

        return DB::table('tbl_timeline')->whereIn('tourId', $ids)->orderBy('timeLineId')->get(['tourId', 'title', 'description'])->groupBy('tourId');
    }

    /**
     * Địa điểm / trải nghiệm có thật mà lịch trình tour gắn tới (bỏ điểm đón, trả, di chuyển, khách sạn), không trùng, theo thứ tự lịch.
     * @return list<array{type:string,refId:int}>
     */
    public function visitedPlaces(int $tourId): array
    {
        if (!Schema::hasTable('tbl_tour_stops')) {
            return [];
        }
        $out = [];
        foreach (DB::table('tbl_tour_stops')->where('tourId', $tourId)->whereIn('refType', ['place', 'experience'])->whereNotNull('refId')
            ->whereNotIn('kind', ['meet', 'drop', 'transfer', 'hotel'])->orderBy('dayNo')->orderBy('orderNo')->get(['refType', 'refId']) as $r) {
            $out[$r->refType . ':' . $r->refId] = ['type' => (string) $r->refType, 'refId' => (int) $r->refId];
        }

        return array_values($out);
    }

    /** Số ngày tour theo chữ thời lượng ("4 ngày 3 đêm"), không có thì theo startDate - endDate; ngoài 1..30 coi như không biết. */
    public static function days(?string $time, ?string $start = null, ?string $end = null): ?int
    {
        if ($time !== null && preg_match('/(\d{1,2})\s*ng[àa]y/iu', $time, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 30) {
            return (int) $m[1];
        }
        if ($start && $end) {
            $d = (int) round((strtotime($end) - strtotime($start)) / 86400) + 1;

            return $d >= 1 && $d <= 30 ? $d : null;
        }

        return null;
    }
}
