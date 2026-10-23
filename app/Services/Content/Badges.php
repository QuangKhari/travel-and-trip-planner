<?php

namespace App\Services\Content;

use Illuminate\Support\Facades\DB;

class Badges
{
    public const DEFS = [
        ['key' => 'buoc-chan-dau',  'name' => 'Bước chân đầu tiên', 'desc' => 'Đánh dấu 1 điểm đến là đã đến',         'metric' => 'visited',         'need' => 1,  'icon' => '👣'],
        ['key' => 'nguoi-di-nhieu', 'name' => 'Người đi nhiều',     'desc' => 'Đã đến 5 điểm đến',                      'metric' => 'visited',         'need' => 5,  'icon' => '🧭'],
        ['key' => 'xuyen-viet',     'name' => 'Xuyên Việt',         'desc' => 'Đã đến điểm đến ở cả 3 miền',           'metric' => 'visited_domains', 'need' => 3,  'icon' => '🗺️'],
        ['key' => 'thuc-khach',     'name' => 'Thực khách',         'desc' => 'Đã thử 3 món ăn',                        'metric' => 'tried',           'need' => 3,  'icon' => '🍜'],
        ['key' => 'sanh-an',        'name' => 'Sành ăn',            'desc' => 'Đã thử 10 món ăn',                       'metric' => 'tried',           'need' => 10, 'icon' => '🥢'],
        ['key' => 'vi-ba-mien',     'name' => 'Vị ba miền',         'desc' => 'Đã thử món của cả 3 miền',              'metric' => 'tried_domains',   'need' => 3,  'icon' => '🍲'],
        ['key' => 'khach-hoi-lang', 'name' => 'Khách hội làng',     'desc' => 'Đã dự 1 lễ hội',                         'metric' => 'attended',        'need' => 1,  'icon' => '🏮'],
        ['key' => 'mua-le-hoi',     'name' => 'Mùa lễ hội',         'desc' => 'Đã dự 3 lễ hội',                         'metric' => 'attended',        'need' => 3,  'icon' => '🎎'],
        ['key' => 'nguoi-suu-tam',  'name' => 'Người sưu tầm',      'desc' => 'Đã lưu 10 điểm đến, món ăn hoặc lễ hội', 'metric' => 'saved',           'need' => 10, 'icon' => '🔖'],
        ['key' => 'khach-travela',  'name' => 'Khách của Travela',  'desc' => 'Có 1 chuyến tour đã hoàn thành',         'metric' => 'booked_done',     'need' => 1,  'icon' => '🧳'],
    ];

    /**
     * Các chỉ số thô của một người dùng. Mỗi chỉ số là một truy vấn đếm.
     *
     * @return array{visited:int,tried:int,attended:int,saved:int,visited_domains:int,tried_domains:int,booked_done:int}
     */
    public function metrics(int $userId): array
    {
        // visited / tried / attended / saved: đã có sẵn ở UserMarks::counts()
        $m = (new UserMarks())->counts($userId);

        // Số miền (b, t, n) của các điểm đến đã đến
        $m['visited_domains'] = (int) DB::table('tbl_user_marks as m')
            ->join('tbl_destinations as d', 'd.destinationId', '=', 'm.refId')
            ->where('m.userId', $userId)
            ->where('m.type', 'visited')
            ->where('m.refType', 'destination')
            ->distinct()
            ->count('d.domain');

        // Số miền của các món đã thử (miền của món = miền của điểm đến xuất xứ)
        $m['tried_domains'] = (int) DB::table('tbl_user_marks as m')
            ->join('tbl_foods as f', 'f.foodId', '=', 'm.refId')
            ->join('tbl_destinations as d', 'd.destinationId', '=', 'f.destinationId')
            ->where('m.userId', $userId)
            ->where('m.type', 'tried')
            ->where('m.refType', 'food')
            ->distinct()
            ->count('d.domain');

        // Tour đã hoàn thành (bookingStatus = 'f')
        $m['booked_done'] = (int) DB::table('tbl_booking')
            ->where('userId', $userId)
            ->where('bookingStatus', 'f')
            ->count();

        return $m;
    }

    /**
     * Hàm thuần (không đụng DB) để dễ viết unit test: nhận chỉ số, trả về danh sách huy hiệu kèm tiến độ.
     *
     * @param array<string,int> $metrics
     * @return list<array{key:string,name:string,desc:string,metric:string,need:int,icon:string,have:int,earned:bool,percent:int}>
     */
    public static function evaluate(array $metrics): array
    {
        $out = [];
        foreach (self::DEFS as $b) {
            $have = (int) ($metrics[$b['metric']] ?? 0);
            $capped = min($have, $b['need']);
            $out[] = $b + [
                'have' => $capped,
                'earned' => $have >= $b['need'],
                'percent' => (int) floor($capped / $b['need'] * 100),
            ];
        }

        return $out;
    }
}
