<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Nạp 8 cảm hứng + 37 điểm đến + điểm xếp hạng (score) cho từng cặp.
 * Chạy lại nhiều lần an toàn (cập nhật theo slug, không nhân đôi).
 *
 *   php artisan db:seed --class=DiscoverSeeder
 *
 * Mô tả điểm đến là văn bản ngắn tự viết; muốn phong phú hơn, nạp thêm từ Wikipedia/Wikivoyage.
 * Tên tỉnh/thành theo đơn vị hành chính sau đợt sáp nhập 07/2025 — hãy đối chiếu lại trước khi bảo vệ.
 */
class DiscoverSeeder extends Seeder
{
    /** Mã ngắn dùng trong bảng điểm bên dưới -> slug cảm hứng */
    private const CODE = [
        'bien' => 'bien',
        'nhien' => 'thien-nhien',
        'lanh' => 'chua-lanh',
        'van' => 'van-hoa',
        'am' => 'am-thuc',
        'que' => 'mien-que',
        'anh' => 'san-anh',
        'doi' => 'hai-nguoi',
    ];

    public function run(): void
    {
        $this->inspirations();
        $this->destinations();
        $this->linkTours();
    }

    private function inspirations(): void
    {
        $rows = [
            ['bien', 'Trốn ra biển', 'Biển · đảo · hoàng hôn', 'Những nơi dành cho những ngày bạn chỉ muốn nghe tiếng sóng.', 'fa-water'],
            ['thien-nhien', 'Thiên nhiên', 'Núi · rừng · thác nước', 'Đi giữa mây, rừng và những cung đường chưa kịp quen.', 'fa-leaf'],
            ['chua-lanh', 'Bình yên', 'Nghỉ dưỡng · chữa lành', 'Chậm lại một chút, ở yên một nơi và thở thật sâu.', 'fa-spa'],
            ['van-hoa', 'Văn hóa', 'Di sản · phố cổ · lễ hội', 'Nơi mỗi con phố, mái ngói và câu chuyện đều có tuổi.', 'fa-landmark'],
            ['am-thuc', 'Ẩm thực', 'Đặc sản · chợ đêm · quán quen', 'Đi để ăn, và nhớ một vùng đất qua hương vị của nó.', 'fa-utensils'],
            ['mien-que', 'Miền quê', 'Ruộng bậc thang · làng nghề · đồng lúa', 'Về với đồng lúa, dòng sông và nhịp sống chậm của làng quê Việt.', 'fa-seedling'],
            ['san-anh', 'Săn ảnh', 'Góc đẹp · bình minh · biển mây', 'Những khung hình đáng để bạn dậy thật sớm.', 'fa-camera'],
            ['hai-nguoi', 'Cho hai người', 'Lãng mạn · riêng tư · kỷ niệm', 'Một chuyến đi dành riêng cho hai người.', 'fa-heart'],
        ];

        foreach ($rows as $i => [$slug, $name, $tagline, $headline, $icon]) {
            DB::table('tbl_inspirations')->updateOrInsert(['slug' => $slug], [
                'name' => $name,
                'tagline' => $tagline,
                'headline' => $headline,
                'icon' => $icon,
                'coverImage' => "discover/inspirations/{$slug}.webp",
                'displayOrder' => $i + 1,
                'isActive' => 1,
            ]);
        }
    }

    private function destinations(): void
    {
        $inspirationIds = DB::table('tbl_inspirations')->pluck('inspirationId', 'slug');

        foreach ($this->destinationData() as [$slug, $name, $province, $domain, $lat, $lng, $desc, $scores]) {
            DB::table('tbl_destinations')->updateOrInsert(['slug' => $slug], [
                'name' => $name,
                'province' => $province,
                'domain' => $domain,
                'description' => $desc,
                'latitude' => $lat,
                'longitude' => $lng,
                'coverImage' => "discover/destinations/{$slug}.webp",
                'isActive' => 1,
            ]);

            $destinationId = DB::table('tbl_destinations')->where('slug', $slug)->value('destinationId');
            DB::table('tbl_destination_inspiration')->where('destinationId', $destinationId)->delete();

            $pivot = [];
            foreach ($scores as $code => $score) {
                $pivot[] = [
                    'destinationId' => $destinationId,
                    'inspirationId' => $inspirationIds[self::CODE[$code]],
                    'score' => $score,
                ];
            }
            DB::table('tbl_destination_inspiration')->insert($pivot);
        }
    }

    /** Gắn tour sẵn có vào điểm đến nếu cột `destination` của tour chứa tên điểm đến. */
    private function linkTours(): void
    {
        foreach (DB::table('tbl_destinations')->get(['destinationId', 'name']) as $d) {
            $like = '%' . addcslashes($d->name, '%_\\') . '%';
            DB::table('tbl_tours')
                ->whereNull('destinationId')
                ->where('destination', 'like', $like)
                ->update(['destinationId' => $d->destinationId]);
        }
    }

    /**
     * [slug, tên, tỉnh/thành, miền, vĩ độ, kinh độ, mô tả, điểm theo cảm hứng 0-100]
     * Điểm là đánh giá chủ quan của nhóm đồ án — chỉnh tự do; cặp không liệt kê = không thuộc cảm hứng đó.
     */
    private function destinationData(): array
    {
        return [
            // ===== MIỀN BẮC =====
            ['ha-noi', 'Hà Nội', 'Hà Nội', 'b', 21.028500, 105.854200, 'Thủ đô ngàn năm văn hiến với phố cổ, hồ Gươm và những quán ăn gắn với từng con ngõ.', ['van' => 95, 'am' => 95, 'anh' => 60, 'doi' => 55]],
            ['ha-long', 'Vịnh Hạ Long', 'Quảng Ninh', 'b', 20.910100, 107.183900, 'Hàng nghìn đảo đá vôi nhô lên giữa vịnh xanh, đẹp nhất khi ngắm hoàng hôn từ du thuyền.', ['bien' => 88, 'nhien' => 85, 'anh' => 90, 'doi' => 80, 'lanh' => 60]],
            ['cat-ba', 'Cát Bà', 'Hải Phòng', 'b', 20.727000, 107.048000, 'Đảo lớn nhất vịnh Hạ Long với rừng quốc gia, những bãi tắm nhỏ và hải sản tươi.', ['bien' => 80, 'nhien' => 80, 'lanh' => 60, 'am' => 60, 'anh' => 60]],
            ['hai-phong', 'Hải Phòng', 'Hải Phòng', 'b', 20.844900, 106.688100, 'Thành phố cảng của bánh đa cua, nem cua bể và vô số quán ăn vặt nổi tiếng.', ['am' => 85, 'van' => 55]],
            ['sa-pa', 'Sa Pa', 'Lào Cai', 'b', 22.336400, 103.843800, 'Thị trấn trong sương, ruộng bậc thang và những bản làng người Mông, người Dao dưới chân Fansipan.', ['nhien' => 90, 'que' => 85, 'anh' => 90, 'van' => 70, 'lanh' => 60, 'doi' => 55]],
            ['ha-giang', 'Hà Giang', 'Tuyên Quang', 'b', 22.823300, 104.983600, 'Đèo Mã Pí Lèng, cao nguyên đá Đồng Văn và mùa hoa tam giác mạch phủ hồng sườn núi.', ['nhien' => 95, 'anh' => 95, 'que' => 70, 'van' => 70]],
            ['cao-bang', 'Cao Bằng', 'Cao Bằng', 'b', 22.854500, 106.720000, 'Thác Bản Giốc, hang Pác Bó và những cánh đồng lúa chín vùng biên giới.', ['nhien' => 90, 'que' => 75, 'anh' => 80, 'van' => 55]],
            ['ninh-binh', 'Ninh Bình', 'Ninh Bình', 'b', 20.250600, 105.974500, 'Tràng An, Tam Cốc, hang Múa: núi đá vôi soi bóng xuống đồng lúa và dòng sông.', ['nhien' => 88, 'que' => 85, 'anh' => 90, 'van' => 75, 'lanh' => 70, 'doi' => 60]],
            ['moc-chau', 'Mộc Châu', 'Sơn La', 'b', 20.845000, 104.683000, 'Cao nguyên đồi chè, đồng hoa và những buổi sớm se lạnh.', ['nhien' => 80, 'que' => 85, 'anh' => 90, 'lanh' => 80, 'doi' => 70]],
            ['ta-xua', 'Tà Xùa', 'Sơn La', 'b', 21.289400, 104.409400, 'Sống lưng khủng long giữa biển mây, điểm săn mây nổi tiếng bậc nhất miền Bắc.', ['nhien' => 90, 'anh' => 98, 'que' => 50]],
            ['mai-chau', 'Mai Châu', 'Phú Thọ', 'b', 20.660000, 105.050000, 'Thung lũng lúa bên những bản người Thái, ngủ nhà sàn giữa đồng.', ['que' => 90, 'lanh' => 85, 'nhien' => 70, 'van' => 65, 'anh' => 70]],
            ['mu-cang-chai', 'Mù Cang Chải', 'Lào Cai', 'b', 21.856000, 104.083000, 'Ruộng bậc thang đẹp bậc nhất Việt Nam, rực vàng vào mùa lúa chín.', ['que' => 90, 'anh' => 98, 'nhien' => 80]],
            ['dien-bien', 'Điện Biên Phủ', 'Điện Biên', 'b', 21.386000, 103.016000, 'Chiến trường lịch sử cùng cánh đồng Mường Thanh rộng nhất vùng Tây Bắc.', ['van' => 80, 'que' => 70, 'nhien' => 60]],
            ['lang-son', 'Lạng Sơn', 'Lạng Sơn', 'b', 21.853700, 106.761500, 'Chợ Kỳ Lừa, vịt quay, phở chua và những dãy núi đá vùng biên giới.', ['am' => 80, 'van' => 60, 'nhien' => 55]],

            // ===== MIỀN TRUNG =====
            ['phong-nha', 'Phong Nha – Kẻ Bàng', 'Quảng Trị', 't', 17.589000, 106.283000, 'Hệ thống hang động hùng vĩ bậc nhất thế giới, có hang Sơn Đoòng và động Phong Nha.', ['nhien' => 95, 'anh' => 85, 'que' => 50]],
            ['hue', 'Huế', 'Huế', 't', 16.463700, 107.590900, 'Cố đô với Đại Nội, lăng tẩm, sông Hương và ẩm thực cung đình.', ['van' => 95, 'am' => 88, 'lanh' => 75, 'doi' => 70, 'anh' => 70]],
            ['da-nang', 'Đà Nẵng', 'Đà Nẵng', 't', 16.054400, 108.202200, 'Thành phố biển với Bà Nà, bán đảo Sơn Trà, cầu Rồng và bãi tắm Mỹ Khê.', ['bien' => 85, 'am' => 80, 'anh' => 75, 'doi' => 70, 'lanh' => 60, 'van' => 55]],
            ['hoi-an', 'Hội An', 'Đà Nẵng', 't', 15.880100, 108.338000, 'Phố cổ đèn lồng bên sông Hoài, nơi có cao lầu, tiệm may đo và làng rau Trà Quế.', ['van' => 95, 'am' => 88, 'doi' => 85, 'anh' => 90, 'lanh' => 60, 'que' => 55]],
            ['ly-son', 'Lý Sơn', 'Quảng Ngãi', 't', 15.383000, 109.122000, 'Đảo tiền tiêu với cổng Tò Vò, đồi cỏ xanh và những ruộng tỏi ven biển.', ['bien' => 92, 'anh' => 92, 'nhien' => 75, 'que' => 70, 'doi' => 65, 'lanh' => 60]],
            ['quy-nhon', 'Quy Nhơn', 'Gia Lai', 't', 13.776500, 109.223700, 'Bãi biển hoang sơ, Kỳ Co, Eo Gió và hải sản tươi rẻ.', ['bien' => 90, 'am' => 75, 'anh' => 80, 'lanh' => 70, 'doi' => 70]],
            ['phu-yen', 'Phú Yên', 'Đắk Lắk', 't', 13.347500, 109.283300, 'Gành Đá Đĩa, Mũi Điện: nơi đón bình minh đầu tiên trên đất liền Việt Nam.', ['bien' => 80, 'anh' => 92, 'que' => 80, 'nhien' => 70]],
            ['nha-trang', 'Nha Trang', 'Khánh Hòa', 't', 12.238800, 109.196700, 'Vịnh biển xanh, lặn ngắm san hô và hải sản tươi quanh năm.', ['bien' => 92, 'am' => 70, 'lanh' => 65, 'doi' => 60, 'anh' => 60]],
            ['phan-rang', 'Ninh Thuận', 'Khánh Hòa', 't', 11.564000, 108.989000, 'Vùng đất nắng gió với đồi cát, vườn nho, tháp Chăm và vịnh Vĩnh Hy.', ['anh' => 90, 'bien' => 75, 'van' => 70, 'que' => 70, 'am' => 55, 'lanh' => 50]],
            ['mui-ne', 'Mũi Né', 'Lâm Đồng', 't', 10.933300, 108.283300, 'Đồi cát bay, làng chài và biển lộng gió quanh năm.', ['anh' => 88, 'bien' => 75, 'am' => 60, 'lanh' => 55]],
            ['da-lat', 'Đà Lạt', 'Lâm Đồng', 't', 11.940400, 108.458300, 'Thành phố ngàn hoa với khí hậu mát, đồi thông, cà phê và những quán lên hơi sương.', ['doi' => 92, 'lanh' => 90, 'anh' => 85, 'nhien' => 75, 'am' => 75, 'que' => 55]],
            ['mang-den', 'Măng Đen', 'Quảng Ngãi', 't', 14.600000, 108.282000, 'Cao nguyên thông xanh yên tĩnh, được ví như Đà Lạt thu nhỏ của Tây Nguyên.', ['lanh' => 85, 'nhien' => 80, 'anh' => 70, 'doi' => 55]],
            ['buon-ma-thuot', 'Buôn Ma Thuột', 'Đắk Lắk', 't', 12.667000, 108.038000, 'Thủ phủ cà phê với thác nước, buôn làng và văn hóa cồng chiêng Tây Nguyên.', ['van' => 80, 'que' => 75, 'nhien' => 70, 'am' => 65]],

            // ===== MIỀN NAM =====
            ['ho-chi-minh', 'TP. Hồ Chí Minh', 'TP. Hồ Chí Minh', 'n', 10.776900, 106.700900, 'Đô thị năng động với ẩm thực đường phố, chợ Bến Thành và những công trình thời thuộc địa.', ['am' => 95, 'van' => 75, 'anh' => 55, 'doi' => 50]],
            ['phu-quoc', 'Phú Quốc', 'An Giang', 'n', 10.289900, 103.984000, 'Đảo ngọc với bãi Sao, hoàng hôn Hòn Thơm và những đặc sản như nước mắm, tiêu, hải sản.', ['bien' => 95, 'doi' => 90, 'lanh' => 85, 'anh' => 80, 'am' => 75, 'nhien' => 60]],
            ['con-dao', 'Côn Đảo', 'TP. Hồ Chí Minh', 'n', 8.693000, 106.609000, 'Quần đảo hoang sơ, nơi rùa biển lên đẻ trứng và còn nhiều di tích lịch sử.', ['bien' => 90, 'nhien' => 85, 'lanh' => 85, 'anh' => 85, 'doi' => 80, 'van' => 70]],
            ['vung-tau', 'Vũng Tàu', 'TP. Hồ Chí Minh', 'n', 10.346000, 107.084300, 'Thành phố biển gần Sài Gòn, hợp cho một chuyến đi cuối tuần.', ['bien' => 75, 'am' => 70, 'doi' => 55]],
            ['can-tho', 'Cần Thơ', 'Cần Thơ', 'n', 10.045200, 105.746900, 'Thủ phủ miền Tây với chợ nổi Cái Răng, vườn trái cây và bữa cơm sông nước.', ['que' => 92, 'am' => 85, 'van' => 70, 'anh' => 70]],
            ['ben-tre', 'Bến Tre', 'Vĩnh Long', 'n', 10.241700, 106.375900, 'Xứ dừa với kênh rạch, kẹo dừa và những chuyến xuồng len qua rừng dừa nước.', ['que' => 92, 'lanh' => 70, 'am' => 65]],
            ['chau-doc', 'Châu Đốc', 'An Giang', 'n', 10.700000, 105.116700, 'Rừng tràm Trà Sư, núi Sam và mắm: nơi giao thoa văn hóa Việt – Chăm – Khmer.', ['que' => 80, 'van' => 80, 'am' => 80, 'nhien' => 75, 'anh' => 70]],
            ['ha-tien', 'Hà Tiên', 'An Giang', 'n', 10.383300, 104.483300, 'Thị trấn biên giới bên biển, có Hòn Phụ Tử, Mũi Nai và chợ đêm.', ['bien' => 80, 'lanh' => 70, 'am' => 65, 'doi' => 60]],
            ['dat-mui', 'Đất Mũi', 'Cà Mau', 'n', 8.600000, 104.730000, 'Cực Nam Tổ quốc, rừng đước và cột mốc tọa độ quốc gia.', ['nhien' => 80, 'anh' => 75, 'que' => 70]],
            ['nui-ba-den', 'Núi Bà Đen', 'Tây Ninh', 'n', 11.383000, 106.175000, 'Nóc nhà Nam Bộ với cáp treo, chùa trên núi và biển mây buổi sáng.', ['anh' => 80, 'nhien' => 70, 'van' => 70]],
        ];
    }
}
