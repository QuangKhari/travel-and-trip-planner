<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Dữ liệu mẫu cho trang Cộng đồng hành trình (demo, chụp màn hình, đo tải k6).
 *   php artisan db:seed --class=CommunityDemoSeeder
 * Tạo vài chuyến công khai của user1…user6 (bỏ qua nếu tiêu đề đã có). Các mục là ghi chú có tọa độ nên không phụ thuộc kho nội dung.
 */
class CommunityDemoSeeder extends Seeder
{
    private const TRIPS = [
        ['user1', 'hoi-an', 'Hội An 3 ngày đi chậm', 3, 2, 5000000, ['culture', 'food'], 'Phố cổ buổi tối, làng rau Trà Quế, biển An Bàng. Không cần thuê xe.', [
            [1, 'Phố cổ Hội An', 15.8770, 108.3260, 'Đi bộ từ chùa Cầu, tối thả đèn hoa đăng'],
            [1, 'Cao lầu Thanh', 15.8779, 108.3302, 'Ăn trưa, quán nhỏ nên tới trước 11h'],
            [2, 'Làng rau Trà Quế', 15.9010, 108.3310, 'Đạp xe 15 phút, học trồng rau buổi sáng'],
            [2, 'Biển An Bàng', 15.9150, 108.3400, 'Chiều tắm biển, ngắm hoàng hôn'],
            [3, 'Rừng dừa Bảy Mẫu', 15.8650, 108.3750, 'Đi thúng chai, nên đi sớm tránh nắng'],
        ]],
        ['user2', 'da-lat', 'Đà Lạt 2 ngày cho gia đình có trẻ nhỏ', 2, 4, 8000000, ['family', 'nature'], 'Lịch thoáng, nhiều điểm trong nhà phòng khi mưa chiều.', [
            [1, 'Quảng trường Lâm Viên', 11.9370, 108.4450, 'Chụp ảnh nụ hoa atiso'],
            [1, 'Vườn dâu Đất Sét', 11.9050, 108.4400, 'Trẻ con hái dâu, có mái che'],
            [1, 'Chợ đêm Đà Lạt', 11.9430, 108.4370, 'Ăn bánh tráng nướng, sữa đậu nành'],
            [2, 'Đồi chè Cầu Đất', 11.8500, 108.5600, 'Đi sớm có sương, 45 phút lái xe'],
            [2, 'Ga Đà Lạt', 11.9420, 108.4560, 'Tàu ngắn đi Trại Mát'],
        ]],
        ['user3', 'ha-noi', 'Hà Nội 2 ngày ăn sáng tới khuya', 2, 2, 3000000, ['food', 'culture'], 'Một ngày phố cổ, một ngày hồ Tây, toàn quán ăn địa phương.', [
            [1, 'Phở Bát Đàn', 21.0336, 105.8467, 'Xếp hàng tự bưng'],
            [1, 'Hồ Hoàn Kiếm và đền Ngọc Sơn', 21.0287, 105.8524, null],
            [1, 'Bún chả Hàng Mành', 21.0322, 105.8490, 'Ăn trưa'],
            [2, 'Văn Miếu – Quốc Tử Giám', 21.0294, 105.8355, 'Mở 8h'],
            [2, 'Hồ Tây – bánh tôm', 21.0560, 105.8360, 'Đạp vịt hoặc đi bộ quanh hồ chiều'],
        ]],
        ['user4', 'sa-pa', 'Sa Pa 4 ngày trekking bản làng', 4, 3, 9000000, ['adventure', 'nature'], 'Hai ngày đi bộ Lao Chải – Tả Van, ngủ homestay, một ngày Fansipan.', [
            [1, 'Thị trấn Sa Pa – nhà thờ đá', 22.3356, 103.8430, 'Nhận phòng, đi dạo'],
            [2, 'Bản Cát Cát', 22.3290, 103.8320, 'Đi bộ xuống thung lũng'],
            [2, 'Lao Chải', 22.3050, 103.8750, 'Ruộng bậc thang'],
            [3, 'Tả Van – homestay', 22.2990, 103.8900, 'Ăn tối cùng chủ nhà'],
            [4, 'Fansipan (cáp treo)', 22.3030, 103.7750, 'Mang áo ấm, nhiệt độ thấp hơn thị trấn 8–10°C'],
        ]],
        ['user5', 'ninh-binh', 'Ninh Bình 2 ngày Tràng An – Tam Cốc', 2, 2, 4000000, ['nature', 'relax'], 'Đi thuyền buổi sáng, chiều đạp xe, tối nghỉ homestay ven núi.', [
            [1, 'Tràng An – đi thuyền', 20.2530, 105.9150, '3 tiếng, nên đi lúc 7h'],
            [1, 'Chùa Bái Đính', 20.2740, 105.8640, null],
            [2, 'Hang Múa', 20.2280, 105.9370, 'Leo 500 bậc, đi lúc 6h để ngắm bình minh'],
            [2, 'Tam Cốc – đạp xe', 20.2160, 105.9370, 'Thuê xe đạp ở homestay'],
        ]],
        ['user6', 'phu-quoc', 'Phú Quốc 3 ngày nghỉ dưỡng', 3, 2, 12000000, ['relax'], 'Ít di chuyển, mỗi ngày một bãi biển, tối chợ đêm.', [
            [1, 'Bãi Sao', 10.0580, 104.0360, 'Nước trong, cát trắng'],
            [1, 'Chợ đêm Dinh Cậu', 10.2160, 103.9600, null],
            [2, 'Cáp treo Hòn Thơm', 10.0270, 104.0080, 'Kiểm tra giờ chạy trước'],
            [3, 'Sunset Sanato', 10.1230, 103.9830, 'Ngắm hoàng hôn'],
        ]],
    ];

    public function run(): void
    {
        $n = 0;
        foreach (self::TRIPS as $i => [$user, $dest, $title, $days, $group, $budget, $interests, $summary, $items]) {
            $userId = DB::table('tbl_users')->where('username', $user)->value('userId');
            $destId = DB::table('tbl_destinations')->where('slug', $dest)->value('destinationId');
            if (!$userId || DB::table('tbl_trips')->where('title', $title)->exists()) {
                continue;
            }
            $tripId = DB::table('tbl_trips')->insertGetId([
                'userId' => $userId, 'kind' => 'trip', 'title' => $title, 'destinationId' => $destId, 'days' => $days, 'groupSize' => $group,
                'budget' => $budget, 'status' => 'planned', 'source' => 'manual', 'interests' => json_encode($interests),
                'isPublic' => 1, 'publishedAt' => now()->subDays(2 + $i * 3), 'summary' => $summary,
                'viewCount' => 40 + $i * 17, 'likeCount' => 0, 'cloneCount' => max(0, 5 - $i), 'createdAt' => now()->subDays(10 + $i), 'updatedAt' => now()->subDays(2 + $i * 3),
            ]);
            foreach ($items as $k => [$day, $label, $lat, $lng, $note]) {
                DB::table('tbl_trip_items')->insert([
                    'tripId' => $tripId, 'refType' => 'note', 'refId' => null, 'label' => $label, 'note' => $note,
                    'dayNo' => $day, 'orderNo' => $k + 1, 'latitude' => $lat, 'longitude' => $lng, 'source' => 'map',
                ]);
            }
            // vài lượt thích từ người dùng mẫu khác
            $likers = DB::table('tbl_users')->where('userId', '!=', $userId)->where('username', 'like', 'user%')->limit(6 - $i)->pluck('userId');
            foreach ($likers as $u) {
                DB::table('tbl_trip_likes')->insertOrIgnore(['tripId' => $tripId, 'userId' => $u, 'createdAt' => now()]);
            }
            DB::table('tbl_trips')->where('tripId', $tripId)->update(['likeCount' => DB::table('tbl_trip_likes')->where('tripId', $tripId)->count()]);
            $n++;
        }
        $this->command?->info("Đã tạo {$n} chuyến công khai mẫu.");
    }
}
