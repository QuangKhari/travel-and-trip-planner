<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Chuyến đi mẫu cho trang Cộng đồng hành trình: 8 lịch trình thật ở các điểm đến phổ biến, có giờ, thời lượng, chi phí ước tính
 * và ghi chú thực tế, để người mới vào thấy ngay chuyến có thể "Làm thành chuyến của bạn".
 *   php artisan db:seed --class=CommunityDemoSeeder
 * Chạy lại nhiều lần không nhân bản (bỏ qua chuyến trùng tiêu đề). Tác giả là 4 tài khoản mẫu (mật khẩu 12345678).
 * Các mục là địa điểm tự thêm có tọa độ nên không phụ thuộc kho nội dung.
 */
class CommunityDemoSeeder extends Seeder
{
    public const AUTHORS = [
        'thanhlan' => ['Phạm Thanh Lan', 'Hà Nội'],
        'minhquan' => ['Lê Minh Quân', 'Đà Nẵng'],
        'hoangyen' => ['Võ Hoàng Yến', 'Hồ Chí Minh'],
        'ducanh' => ['Ngô Đức Anh', 'Cần Thơ'],
    ];

    /**
     * [tác giả, slug điểm đến, tiêu đề, số ngày, số người, ngân sách, sở thích, tóm tắt,
     *  mục: [ngày, giờ bắt đầu "HH:MM", phút, tên, lat, lng, chi phí/người, ghi chú]]
     */
    public const TRIPS = [
        ['minhquan', 'hoi-an', 'Hội An 3 ngày đi chậm, không cần thuê xe', 3, 2, 5000000, ['culture', 'food'],
            'Phố cổ buổi tối, làng rau Trà Quế, biển An Bàng. Mọi điểm trong bán kính 5 km, đi bộ và xe đạp là đủ.', [
            [1, '15:00', 120, 'Phố cổ Hội An', 15.8770, 108.3260, 120000, 'Vé tham quan 120.000đ gồm 5 điểm; đi từ chùa Cầu sang hội quán Phúc Kiến'],
            [1, '18:00', 60, 'Cao lầu Thanh', 15.8779, 108.3302, 50000, 'Quán nhỏ, tối đông, nên đến trước 18:30'],
            [1, '19:30', 60, 'Thả đèn hoa đăng sông Hoài', 15.8768, 108.3274, 20000, null],
            [2, '07:30', 150, 'Làng rau Trà Quế', 15.9010, 108.3310, 150000, 'Đạp xe 15 phút; lớp trồng rau buổi sáng, đặt trước một ngày'],
            [2, '11:00', 60, 'Bánh mì Phượng', 15.8796, 108.3343, 35000, null],
            [2, '15:30', 150, 'Biển An Bàng', 15.9150, 108.3400, 0, 'Ghế bãi miễn phí nếu gọi nước; tắm biển sau 16h đỡ nắng'],
            [3, '07:00', 120, 'Rừng dừa Bảy Mẫu', 15.8650, 108.3750, 150000, 'Đi thúng chai sớm, trước 9h ít khách và mát'],
            [3, '10:00', 90, 'Chợ Hội An', 15.8767, 108.3320, 80000, 'Mua quà: trà, mứt, đèn lồng gấp'],
        ]],
        ['thanhlan', 'da-lat', 'Đà Lạt 2 ngày cho gia đình có trẻ nhỏ', 2, 4, 8000000, ['family', 'nature'],
            'Lịch thoáng, nhiều điểm có mái che phòng khi mưa chiều. Mỗi buổi chỉ 1–2 điểm để trẻ kịp nghỉ trưa.', [
            [1, '09:00', 60, 'Quảng trường Lâm Viên', 11.9370, 108.4450, 0, 'Chụp ảnh nụ hoa atiso, có chỗ cho trẻ chạy nhảy'],
            [1, '10:30', 90, 'Vườn dâu Đất Sét', 11.9050, 108.4400, 60000, 'Trẻ tự hái dâu, có mái che'],
            [1, '15:00', 120, 'Thung lũng Tình Yêu', 11.9780, 108.4500, 250000, 'Khu vui chơi rộng; mang áo khoác, chiều gió lạnh'],
            [1, '18:30', 90, 'Chợ đêm Đà Lạt', 11.9430, 108.4370, 100000, 'Bánh tráng nướng, sữa đậu nành; đông khách sau 19h'],
            [2, '06:30', 150, 'Đồi chè Cầu Đất', 11.8500, 108.5600, 0, 'Đi sớm có sương; 45 phút lái xe'],
            [2, '10:00', 60, 'Ga Đà Lạt', 11.9420, 108.4560, 150000, 'Tàu ngắn đi Trại Mát, chạy theo giờ cố định'],
            [2, '14:00', 90, 'Vườn hoa thành phố', 11.9480, 108.4500, 50000, null],
        ]],
        ['thanhlan', 'ha-noi', 'Hà Nội 2 ngày ăn từ sáng tới khuya', 2, 2, 3000000, ['food', 'culture'],
            'Một ngày phố cổ, một ngày Văn Miếu – hồ Tây, toàn quán ăn địa phương, đi bộ và xe ôm công nghệ.', [
            [1, '07:00', 45, 'Phở Bát Đàn', 21.0336, 105.8467, 60000, 'Xếp hàng tự bưng, mở từ 6h'],
            [1, '08:30', 90, 'Hồ Hoàn Kiếm và đền Ngọc Sơn', 21.0287, 105.8524, 50000, null],
            [1, '11:30', 60, 'Bún chả Hàng Mành', 21.0322, 105.8490, 70000, null],
            [1, '15:00', 60, 'Cà phê trứng Giảng', 21.0335, 105.8530, 40000, null],
            [1, '19:00', 120, 'Phố đi bộ và chợ đêm Hàng Đào', 21.0345, 105.8510, 100000, 'Chỉ tối thứ Sáu – Chủ nhật'],
            [2, '08:00', 90, 'Văn Miếu – Quốc Tử Giám', 21.0294, 105.8355, 70000, 'Mở 8h, sáng sớm vắng khách'],
            [2, '11:00', 60, 'Bún cá Sơn', 21.0300, 105.8420, 50000, null],
            [2, '16:00', 120, 'Hồ Tây – bánh tôm', 21.0560, 105.8360, 80000, 'Đi bộ quanh hồ lúc hoàng hôn'],
        ]],
        ['ducanh', 'sa-pa', 'Sa Pa 4 ngày trekking bản làng', 4, 3, 9000000, ['adventure', 'nature'],
            'Hai ngày đi bộ Cát Cát – Lao Chải – Tả Van, ngủ homestay, một ngày Fansipan. Cần giày bám đường và áo mưa.', [
            [1, '14:00', 120, 'Thị trấn Sa Pa – nhà thờ đá', 22.3356, 103.8430, 0, 'Nhận phòng, đi dạo hồ Sa Pa'],
            [2, '08:00', 180, 'Bản Cát Cát', 22.3290, 103.8320, 150000, 'Đi bộ xuống thung lũng, đường dốc đá'],
            [2, '13:00', 180, 'Lao Chải', 22.3050, 103.8750, 0, 'Ruộng bậc thang đẹp nhất tháng 9'],
            [3, '08:30', 240, 'Tả Van – homestay', 22.2990, 103.8900, 400000, 'Ngủ lại nhà dân, ăn tối cùng chủ nhà'],
            [4, '08:00', 300, 'Fansipan (cáp treo)', 22.3030, 103.7750, 850000, 'Mang áo ấm, lạnh hơn thị trấn 8–10°C; kiểm tra giờ cáp trước'],
        ]],
        ['thanhlan', 'ninh-binh', 'Ninh Bình 2 ngày Tràng An – Tam Cốc', 2, 2, 4000000, ['nature', 'relax'],
            'Đi thuyền buổi sáng, chiều đạp xe, tối nghỉ homestay ven núi. Từ Hà Nội 2 tiếng xe khách.', [
            [1, '07:30', 180, 'Tràng An – đi thuyền', 20.2530, 105.9150, 250000, 'Tuyến 1 qua nhiều hang nhất; đi sớm ít chờ'],
            [1, '14:00', 150, 'Chùa Bái Đính', 20.2740, 105.8640, 60000, 'Rộng, nên đi xe điện trong khuôn viên'],
            [2, '06:00', 120, 'Hang Múa', 20.2280, 105.9370, 100000, 'Leo 500 bậc, đi lúc 6h để ngắm bình minh'],
            [2, '09:30', 150, 'Tam Cốc – đạp xe', 20.2160, 105.9370, 50000, 'Thuê xe đạp ở homestay'],
            [2, '12:30', 60, 'Cơm dê núi', 20.2200, 105.9300, 200000, 'Đặc sản; gọi phần nhỏ cho 2 người là đủ'],
        ]],
        ['hoangyen', 'phu-quoc', 'Phú Quốc 3 ngày nghỉ dưỡng ít di chuyển', 3, 2, 12000000, ['relax'],
            'Mỗi ngày một bãi biển, tối chợ đêm. Chỉ một buổi đi xa xuống nam đảo.', [
            [1, '15:00', 150, 'Bãi Trường', 10.1800, 103.9650, 0, 'Gần khu khách sạn, tắm biển chiều'],
            [1, '19:00', 90, 'Chợ đêm Dinh Cậu', 10.2160, 103.9600, 300000, 'Hải sản: hỏi giá theo kg trước khi gọi'],
            [2, '08:00', 180, 'Bãi Sao', 10.0580, 104.0360, 0, 'Nước trong, cát trắng; thuê ghế 50.000đ'],
            [2, '13:30', 180, 'Cáp treo Hòn Thơm', 10.0270, 104.0080, 650000, 'Kiểm tra giờ chạy, ngày gió lớn có thể tạm dừng'],
            [3, '09:00', 90, 'Nhà thùng nước mắm', 10.2150, 103.9700, 0, null],
            [3, '17:00', 120, 'Sunset Sanato', 10.1230, 103.9830, 100000, 'Ngắm hoàng hôn, đến trước 17:30'],
        ]],
        ['minhquan', 'da-nang', 'Đà Nẵng 3 ngày biển, núi và ăn vặt', 3, 2, 6000000, ['nature', 'food'],
            'Sáng biển, chiều núi hoặc bán đảo, tối ăn vặt. Thuê xe máy là thuận nhất.', [
            [1, '06:00', 90, 'Biển Mỹ Khê', 16.0600, 108.2470, 0, 'Tắm sáng sớm, nước êm'],
            [1, '09:30', 150, 'Bán đảo Sơn Trà – chùa Linh Ứng', 16.1000, 108.2780, 0, 'Đường đèo, đi xe máy cẩn thận'],
            [1, '18:30', 90, 'Chợ Cồn – ăn vặt', 16.0680, 108.2140, 100000, null],
            [2, '07:30', 300, 'Bà Nà Hills – Cầu Vàng', 15.9950, 107.9960, 950000, 'Đến sớm để lên cáp chuyến đầu, mang áo khoác'],
            [2, '19:00', 60, 'Cầu Rồng phun lửa', 16.0610, 108.2270, 0, 'Chỉ tối thứ Bảy, Chủ nhật lúc 21h'],
            [3, '08:00', 120, 'Ngũ Hành Sơn', 16.0040, 108.2630, 40000, 'Leo bậc đá, thang máy có phí'],
            [3, '11:00', 60, 'Mì Quảng Bà Mua', 16.0660, 108.2190, 50000, null],
        ]],
        ['ducanh', 'can-tho', 'Cần Thơ 2 ngày chợ nổi và miệt vườn', 2, 2, 3500000, ['culture', 'food'],
            'Dậy sớm đi chợ nổi Cái Răng, chiều vườn trái cây, tối bến Ninh Kiều. Mùa trái cây đẹp nhất tháng 6–8.', [
            [1, '05:30', 150, 'Chợ nổi Cái Răng', 10.0070, 105.7480, 200000, 'Thuê ghe ở bến Ninh Kiều, ăn hủ tiếu trên ghe'],
            [1, '09:00', 60, 'Lò hủ tiếu Sáu Hoài', 10.0010, 105.7470, 0, null],
            [1, '14:30', 150, 'Vườn trái cây Mỹ Khánh', 10.0010, 105.7110, 60000, 'Ăn trái cây tại vườn theo mùa'],
            [1, '19:00', 90, 'Bến Ninh Kiều – chợ đêm', 10.0340, 105.7880, 100000, null],
            [2, '08:00', 60, 'Nhà cổ Bình Thủy', 10.0750, 105.7450, 20000, null],
            [2, '10:00', 60, 'Thiền viện Trúc Lâm Phương Nam', 9.9930, 105.6820, 0, null],
            [2, '12:00', 60, 'Bánh xèo Cần Thơ', 10.0300, 105.7700, 80000, null],
        ]],
    ];

    public function run(): void
    {
        $authors = [];
        foreach (self::AUTHORS as $username => [$name, $address]) {
            DB::table('tbl_users')->insertOrIgnore([
                'username' => $username, 'email' => $username . '@example.com', 'fullName' => $name, 'address' => $address,
                'password' => Hash::make('12345678'), 'isActive' => 'y',
            ]);
            $authors[$username] = DB::table('tbl_users')->where('username', $username)->value('userId');
        }

        $n = 0;
        foreach (self::TRIPS as $i => [$user, $dest, $title, $days, $group, $budget, $interests, $summary, $items]) {
            $userId = $authors[$user] ?? null;
            $destId = DB::table('tbl_destinations')->where('slug', $dest)->value('destinationId');
            if (!$userId || DB::table('tbl_trips')->where('title', $title)->exists()) {
                continue;
            }
            $tripId = DB::table('tbl_trips')->insertGetId([
                'userId' => $userId, 'kind' => 'trip', 'title' => $title, 'destinationId' => $destId, 'days' => $days, 'groupSize' => $group,
                'budget' => $budget, 'status' => 'done', 'source' => 'manual', 'interests' => json_encode($interests),
                'isPublic' => 1, 'publishedAt' => now()->subDays(2 + $i * 3), 'summary' => $summary,
                'viewCount' => 40 + $i * 17, 'likeCount' => 0, 'cloneCount' => max(0, 6 - $i), 'createdAt' => now()->subDays(20 + $i), 'updatedAt' => now()->subDays(2 + $i * 3),
            ]);
            $order = [];
            foreach ($items as [$day, $at, $dur, $label, $lat, $lng, $cost, $note]) {
                [$h, $m] = array_map('intval', explode(':', $at));
                $order[$day] = ($order[$day] ?? 0) + 1;
                DB::table('tbl_trip_items')->insert([
                    'tripId' => $tripId, 'refType' => 'note', 'refId' => null, 'label' => $label, 'note' => $note,
                    'dayNo' => $day, 'orderNo' => $order[$day], 'startMin' => $h * 60 + $m, 'durationMin' => $dur,
                    'cost' => $cost * $group, 'costType' => 'estimate', 'latitude' => $lat, 'longitude' => $lng, 'source' => 'map',
                ]);
            }
            // lượt thích từ các tác giả mẫu khác
            foreach ($authors as $u => $id) {
                if ($id && $id !== $userId && ($i + strlen($u)) % 3 !== 0) {
                    DB::table('tbl_trip_likes')->insertOrIgnore(['tripId' => $tripId, 'userId' => $id, 'createdAt' => now()]);
                }
            }
            DB::table('tbl_trips')->where('tripId', $tripId)->update(['likeCount' => DB::table('tbl_trip_likes')->where('tripId', $tripId)->count()]);
            $n++;
        }
        $this->command?->info("Đã tạo {$n} chuyến công khai mẫu.");
    }
}
