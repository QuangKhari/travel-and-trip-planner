<?php

namespace Database\Seeders;

use App\Services\Content\PlaceImport;
use App\Services\Planner\TripDecide;
use App\Services\Planner\TripPlanner;
use App\Services\TourQuotes;
use App\Support\PasswordHasher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Trạng thái demo cố định (Đợt D mục D5). Chạy qua `php artisan demo:reset` (xóa dữ liệu demo cũ rồi gọi seeder này).
 * Ngày tháng tính tương đối theo hôm nay để demo lúc nào cũng hợp lệ:
 *   1. "Đà Lạt 3 ngày" (bắt đầu thứ Hai tuần sau): ngày 1 có nơi đóng cửa thứ Hai + hai mục đặt giờ sát nhau (chặng đỏ);
 *      ngày 2 thứ tự lộn xộn để bấm "Tối ưu tuyến"; dùng cho Sức khỏe chuyến đi, La bàn, Tối ưu tuyến.
 *   2. "Hội An – Đà Nẵng cùng nhóm": bạn đồng hành quyền sửa, câu hỏi chọn ngày đang mở, ai đi / chưa chắc.
 *   3. "Hà Nội – đang đi" (bắt đầu hôm nay): bảng Hôm nay, mục đầu đã ghé.
 *   4. Yêu cầu báo giá tour riêng cho chuyến 1, nhân viên đã báo giá lần 1 và đang trao đổi.
 *   5. Một lô nhập địa điểm chờ duyệt (có dòng trùng và dòng lỗi) cho trang Nhập địa điểm.
 */
class DemoShowcaseSeeder extends Seeder
{
    private array $ids = [];

    public function run(): void
    {
        \Illuminate\Support\Facades\Mail::fake();   // không gửi mail thật tới các tài khoản demo
        $pass = PasswordHasher::make((string) config('demo.password'));
        foreach ((array) config('demo.accounts') as $a) {
            if ($a['type'] === 'admin') {
                DB::table('tbl_admin')->updateOrInsert(['userName' => $a['username']],
                    ['passWord' => $pass, 'email' => $a['email'], 'fullName' => $a['name'], 'address' => 'Hà Nội', 'role' => $a['role']]);
                $this->ids[$a['username']] = (int) DB::table('tbl_admin')->where('userName', $a['username'])->value('adminId');
            } else {
                DB::table('tbl_users')->updateOrInsert(['username' => $a['username']],
                    ['email' => $a['email'], 'password' => $pass, 'fullName' => $a['name'], 'isActive' => 'y']);
                $this->ids[$a['username']] = (int) DB::table('tbl_users')->where('username', $a['username'])->value('userId');
            }
        }
        $me = $this->ids['demo_khach'];
        $friend = $this->ids['demo_ban'];
        $planner = app(TripPlanner::class);

        // 1. Đà Lạt: có chỗ cần sửa
        $monday = now()->startOfDay()->addDays(7)->next('Monday')->toDateString();
        $dl = $this->dest('da-lat');
        $t1 = $planner->create($me, ['title' => 'Đà Lạt 3 ngày', 'days' => 3, 'groupSize' => 2, 'budget' => 6000000, 'startDate' => $monday, 'destinationId' => $dl]);
        $planner->save($me, $t1, ['title' => 'Đà Lạt 3 ngày', 'days' => 3, 'groupSize' => 2, 'budget' => 6000000, 'startDate' => $monday, 'destinationId' => $dl], array_values(array_filter([
            $this->p($dl, 'Chợ Đà Lạt', 1),
            $this->p($dl, 'Bảo tàng Lâm Đồng', 1),                                   // đóng cửa thứ Hai
            $this->p($dl, 'Thác Datanla', 1, ['startMin' => 600]),
            $this->p($dl, 'Ga Đà Lạt', 1, ['startMin' => 640]),                       // không kịp từ Datanla
            $this->p($dl, 'Bánh căn Nhà Chung', 1),
            $this->p($dl, 'Đồi chè Cầu Đất', 2),                                      // ngày 2 lộn xộn: xa – gần – xa
            $this->p($dl, 'Hồ Xuân Hương', 2),
            $this->p($dl, 'Thiền viện Trúc Lâm', 2),
            $this->p($dl, 'Quảng trường Lâm Viên', 2),
            $this->p($dl, 'Thác Datanla', 2),
            $this->p($dl, 'Biệt thự Hằng Nga (Crazy House)', 3),
            $this->p($dl, 'Dinh Bảo Đại (Dinh III)', 3),
            $this->p($dl, 'Nem nướng Bà Hùng', 3),
        ])));

        // 2. Hội An – Đà Nẵng cùng nhóm
        $ha = $this->dest('hoi-an');
        $dn = $this->dest('da-nang');
        $t2 = $planner->create($me, ['title' => 'Hội An – Đà Nẵng cùng nhóm', 'days' => 4, 'groupSize' => 4, 'budget' => 16000000, 'destinationId' => $ha]);
        $planner->save($me, $t2, ['title' => 'Hội An – Đà Nẵng cùng nhóm', 'days' => 4, 'groupSize' => 4, 'budget' => 16000000, 'destinationId' => $ha], array_values(array_filter([
            $this->p($ha, 'Chùa Cầu', 1), $this->p($ha, 'Bảo tàng Hội An', 1), $this->p($dn, 'Cầu Rồng', 3), $this->p($dn, 'Bãi biển Mỹ Khê', 3), $this->p($dn, 'Ngũ Hành Sơn', 4),
        ])));
        DB::table('tbl_trip_members')->updateOrInsert(['tripId' => $t2, 'userId' => $friend], ['role' => 'edit', 'invitedBy' => $me, 'createdAt' => now()]);
        if (TripDecide::ready()) {
            $trip2 = DB::table('tbl_trips')->where('tripId', $t2)->first();
            $dec = app(TripDecide::class);
            $d1 = now()->addDays(21)->next('Friday')->toDateString();
            $d2 = now()->addDays(28)->next('Friday')->toDateString();
            $poll = $dec->create($trip2, $me, 'date', 'Mình đi ngày nào?', [['label' => 'Thứ Sáu ' . date('d/m', strtotime($d1)), 'value' => $d1], ['label' => 'Thứ Sáu ' . date('d/m', strtotime($d2)), 'value' => $d2]]);
            $opts = DB::table('tbl_trip_poll_options')->where('pollId', $poll)->orderBy('optionId')->pluck('optionId');
            $dec->vote($trip2, $me, $poll, (int) $opts[0]);
            $dec->vote($trip2, $friend, $poll, (int) $opts[1]);
            $dec->rsvp($trip2, $friend, 'maybe');
        }

        // 3. Hà Nội – đang đi (bắt đầu hôm nay)
        $hn = $this->dest('ha-noi');
        $t3 = $planner->create($me, ['title' => 'Hà Nội – đang đi', 'days' => 2, 'groupSize' => 2, 'startDate' => now()->toDateString(), 'destinationId' => $hn]);
        $planner->save($me, $t3, ['title' => 'Hà Nội – đang đi', 'days' => 2, 'groupSize' => 2, 'startDate' => now()->toDateString(), 'destinationId' => $hn], array_values(array_filter([
            $this->p($hn, 'Hồ Hoàn Kiếm và đền Ngọc Sơn', 1, ['visitedAt' => now()->setTime(8, 40)->toDateTimeString()]),
            $this->p($hn, 'Phở Gia Truyền Bát Đàn', 1),
            $this->p($hn, 'Văn Miếu - Quốc Tử Giám', 1),
            $this->p($hn, 'Nhà hát Múa rối Thăng Long', 1, ['startMin' => 1110]),
            $this->p($hn, 'Hoàng thành Thăng Long', 2), $this->p($hn, 'Bún chả Hương Liên', 2), $this->p($hn, 'Bảo tàng Dân tộc học Việt Nam', 2),
        ])));

        // 4. Báo giá tour riêng đang trao đổi
        if (TourQuotes::ready() && DB::table('tbl_trip_items')->where('tripId', $t1)->exists()) {
            $svc = app(TourQuotes::class);
            $trip1 = DB::table('tbl_trips')->where('tripId', $t1)->first();
            $q = $svc->request($trip1, $me, 'Nhóm 2 người, muốn có xe đưa đón và hướng dẫn viên ngày 2.');
            $svc->quote($q, ['pricePerPerson' => 2950000, 'includes' => 'Xe 7 chỗ 3 ngày, hướng dẫn viên ngày 2, vé tham quan', 'excludes' => 'Ăn uống, khách sạn',
                'message' => 'Giá đã gồm xe đưa đón sân bay Liên Khương.', 'validUntil' => now()->addDays(10)->toDateString()], $this->ids['demo_nhanvien']);
            if (TourQuotes::chatReady()) {
                $svc->message($q, 'customer', $me, 'Nếu thêm một người nữa thì giá mỗi người thay đổi thế nào ạ?', $me);
            }
        }

        // 5. Lô nhập địa điểm chờ duyệt (một dòng mới, một dòng trùng, một dòng lỗi)
        if (PlaceImport::ready()) {
            $csv = "dest,name,category,kind,lat,lng,address,duration,price_min,price_max,indoor,hours,website,description,source,source_url\n"
                . "can-tho,Nhà trưng bày Lịch sử Cần Thơ,sight,museum,10.0318,105.7861,Hai Bà Trưng,45,0,0,1,Tu-Su 08:00-17:00; Mo off,,,Khảo sát thực địa,\n"
                . "can-tho,Chợ nổi Cái Răng,sight,attraction,10.0041,105.7481,Cái Răng,120,150000,300000,0,Mo-Su 05:00-09:00,,,Khảo sát thực địa,\n"
                . "can-tho,Quán không rõ nguồn,food,restaurant,105.78,10.03,,30,,,,,,,,\n";
            PlaceImport::stage($csv, 'can-tho-bo-sung.csv', $this->ids['demo_quanly'], (string) config('demo.import_note'));
        }
    }

    private function dest(string $slug): ?int
    {
        return ($id = DB::table('tbl_destinations')->where('slug', $slug)->value('destinationId')) ? (int) $id : null;
    }

    /** Mục địa điểm theo tên (bỏ qua nếu kho chưa có nơi đó). */
    private function p(?int $dest, string $name, int $day, array $extra = []): ?array
    {
        if (!$dest) {
            return null;
        }
        $id = DB::table('tbl_external_places')->where('destinationId', $dest)->where('name', $name)->where('reviewStatus', 'published')->value('placeId');

        return $id ? ['type' => 'place', 'refId' => (int) $id, 'day' => $day] + $extra : null;
    }
}
