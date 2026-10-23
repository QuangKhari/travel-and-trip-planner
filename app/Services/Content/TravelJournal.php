<?php

namespace App\Services\Content;

use App\Support\VietnamMap;
use Illuminate\Support\Facades\DB;

class TravelJournal
{
    public const STATE_DONE = 'done';       // đã đến (đánh dấu "Đã đến" hoặc tour hoàn thành)
    public const STATE_PLANNED = 'planned'; // sắp đi (tour đã đặt, chưa hoàn thành)

    /**
     * @return list<array{province:string,state:string,x:float,y:float,places:list<string>}>
     */
    public function provinces(int $userId): array
    {
        $rows = [];

        // 1) điểm đến người dùng bấm "Đã đến"
        $visited = DB::table('tbl_user_marks as m')
            ->join('tbl_destinations as d', 'd.destinationId', '=', 'm.refId')
            ->where('m.userId', $userId)
            ->where('m.type', 'visited')
            ->where('m.refType', 'destination')
            ->where('d.isActive', 1)
            ->get(['d.destinationId', 'd.name', 'd.province', 'd.latitude', 'd.longitude']);
        foreach ($visited as $r) {
            $rows[] = [$r, true];
        }

        // 2) điểm đến của các tour đã đặt (n, y = sắp đi; f = đã đi; c = đã hủy thì bỏ qua)
        $booked = DB::table('tbl_booking as b')
            ->join('tbl_tours as t', 't.tourId', '=', 'b.tourId')
            ->join('tbl_destinations as d', 'd.destinationId', '=', 't.destinationId')
            ->where('b.userId', $userId)
            ->whereIn('b.bookingStatus', ['n', 'y', 'f'])
            ->get(['d.destinationId', 'd.name', 'd.province', 'd.latitude', 'd.longitude', 'b.bookingStatus']);
        foreach ($booked as $r) {
            $rows[] = [$r, $r->bookingStatus === 'f'];
        }

        // Gộp theo tỉnh: nhiều điểm đến cùng tỉnh chỉ tô một vệt.
        $by = [];
        foreach ($rows as [$r, $done]) {
            $p = $r->province;
            $by[$p] ??= ['province' => $p, 'done' => false, 'lat' => [], 'lng' => [], 'places' => []];
            $by[$p]['done'] = $by[$p]['done'] || $done;
            // khóa theo destinationId để một điểm đến xuất hiện hai lần (đã đến + có tour) không bị tính trùng
            $by[$p]['lat'][$r->destinationId] = (float) $r->latitude;
            $by[$p]['lng'][$r->destinationId] = (float) $r->longitude;
            $by[$p]['places'][$r->destinationId] = $r->name;
        }

        $out = [];
        foreach ($by as $p) {
            [$x, $y] = VietnamMap::project(array_sum($p['lat']) / count($p['lat']), array_sum($p['lng']) / count($p['lng']));
            $out[] = [
                'province' => $p['province'],
                'state' => $p['done'] ? self::STATE_DONE : self::STATE_PLANNED,
                'x' => $x,
                'y' => $y,
                'places' => array_values($p['places']),
            ];
        }
        // đã đến trước, rồi theo tên tỉnh
        $rank = fn(array $a): int => $a['state'] === self::STATE_DONE ? 0 : 1;
        usort($out, fn($a, $b) => [$rank($a), $a['province']] <=> [$rank($b), $b['province']]);

        return $out;
    }

    /**
     * Tour đã đặt (bỏ đơn đã hủy), tách hai nhóm.
     *
     * @return array{upcoming:list<object>,done:list<object>}
     */
    public function trips(int $userId): array
    {
        $rows = DB::table('tbl_booking as b')
            ->join('tbl_tours as t', 't.tourId', '=', 'b.tourId')
            ->leftJoin('tbl_checkout as c', 'c.bookingId', '=', 'b.bookingId')
            ->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 't.destinationId')
            ->where('b.userId', $userId)
            ->whereIn('b.bookingStatus', ['n', 'y', 'f'])
            ->orderByDesc('t.startDate')
            ->limit(50)
            ->get(['b.bookingId', 'b.bookingStatus', 't.title', 't.startDate', 't.endDate', 'd.name as destName', 'd.province', 'c.checkoutId']);

        $upcoming = [];
        $done = [];
        foreach ($rows as $r) {
            $r->url = $r->checkoutId
                ? route('tour-booked', ['bookingId' => $r->bookingId, 'checkoutId' => $r->checkoutId])
                : route('my-tours');
            if ($r->bookingStatus === 'f') {
                $done[] = $r;
            } else {
                $upcoming[] = $r;
            }
        }
        // sắp đi: ngày gần nhất lên đầu (truy vấn đang sắp giảm dần nên đảo lại)
        $upcoming = array_reverse($upcoming);

        return ['upcoming' => $upcoming, 'done' => $done];
    }
}
