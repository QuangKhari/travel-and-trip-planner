<?php

namespace App\Services\Planner;

/**
 * Hiểu câu tìm KHÔNG cụ thể của người dùng, ví dụ "nhà hàng chay giá rẻ", "khách sạn 4 sao dưới 1 triệu",
 * "hoạt động trong nhà", "quán cà phê gần 2km".
 *
 * Hàm thuần (không đụng cơ sở dữ liệu hay mạng) để kiểm thử bằng chuỗi mẫu. Cách làm: tách câu thành từ, bỏ dấu để so khớp,
 * nhận diện cụm từ đã biết (loại địa điểm, mức giá, số tiền, ăn chay, trong nhà/ngoài trời, hạng sao, khoảng cách),
 * phần còn lại là "tên riêng". Còn tên riêng thì đây là tìm địa điểm cụ thể (giữ cách tìm theo tên như cũ);
 * hết tên riêng thì đây là tìm theo tiêu chí (hiện bộ lọc).
 *
 * Kết quả parse():
 *  cat        ?string  hotel|restaurant|cafe|bar|market|sight|experience|tour
 *  kinds      ?list    giới hạn loại con (vd bảo tàng: ['museum','gallery']), null = mọi loại của nhóm
 *  levels     ?[min,max] mức giá 1..4 (1 rẻ, 4 sang)
 *  minPrice, maxPrice ?int  số tiền (đồng); chỉ dùng được với mục có giá thật
 *  free       bool     "miễn phí"
 *  veg        bool     ăn chay
 *  cuisine    ?string  giá trị cuisine của OSM (seafood, japanese...)
 *  indoor     ?bool    true trong nhà, false ngoài trời
 *  stars      ?int     hạng sao khách sạn
 *  radiusKm   ?float   bán kính
 *  sort       ?string  near|rank|price
 *  rest       string   phần chữ còn lại (tên riêng), '' nếu không còn
 *  specific   bool     rest !== ''
 *  tags       list<string> nhãn tiếng Việt cho từng điều kiện đã hiểu (hiển thị cho người dùng)
 */
final class PlaceQuery
{
    /** cụm (đã bỏ dấu) => [nhóm, loại con|null]. Cụm dài được thử trước. */
    private const CATS = [
        'khach san' => ['hotel', null], 'nha nghi' => ['hotel', ['guest_house', 'motel']], 'homestay' => ['hotel', ['guest_house', 'apartment', 'chalet']],
        'hostel' => ['hotel', ['hostel']], 'resort' => ['hotel', ['resort']], 'cho o' => ['hotel', null], 'luu tru' => ['hotel', null],
        'nha hang' => ['restaurant', null], 'quan an' => ['restaurant', null], 'an uong' => ['restaurant', null], 'quan com' => ['restaurant', null],
        'ca phe' => ['cafe', null], 'cafe' => ['cafe', null], 'coffee' => ['cafe', null],
        'quan bar' => ['bar', null], 'bar' => ['bar', null], 'pub' => ['bar', null], 'quan nhau' => ['bar', null], 'quan ca phe' => ['cafe', null], 'quan cafe' => ['cafe', null],
        'cho' => ['market', null], 'cho dem' => ['market', null], 'sieu thi' => ['market', ['supermarket', 'mall']], 'trung tam thuong mai' => ['market', ['mall']],
        'diem tham quan' => ['sight', null], 'tham quan' => ['sight', null], 'diem du lich' => ['sight', null], 'danh lam' => ['sight', null],
        'bao tang' => ['sight', ['museum', 'gallery']], 'bai bien' => ['sight', ['beach']], 'thac nuoc' => ['sight', ['waterfall']],
        'chua' => ['sight', ['place_of_worship', 'temple']], 'di tich' => ['sight', ['historic']], 'ngam canh' => ['sight', ['viewpoint']],
        'cong vien' => ['sight', ['park', 'theme_park']], 'vuon thu' => ['sight', ['zoo', 'aquarium']],
        'trai nghiem' => ['experience', null], 'hoat dong' => ['experience', null], 'vui choi' => ['experience', null], 'tro choi' => ['experience', null],
        'tour' => ['tour', null], 'chuyen di tour' => ['tour', null],
    ];

    /** cụm đã bỏ dấu => mức giá [min,max] */
    private const LEVEL_WORDS = [
        'gia re' => [1, 1], 'binh dan' => [1, 1], 'tiet kiem' => [1, 1], 'sinh vien' => [1, 1], 're' => [1, 1],
        'tam trung' => [2, 2], 'trung binh' => [2, 2], 'vua phai' => [2, 2], 'vua tui tien' => [2, 2],
        'cao cap' => [3, 4], 'sang trong' => [3, 4], 'sang chanh' => [3, 4], 'luxury' => [3, 4], 'dat tien' => [3, 4],
    ];

    private const CUISINE = [
        'hai san' => 'seafood', 'nhat' => 'japanese', 'han' => 'korean', 'pizza' => 'pizza', 'lau' => 'hotpot', 'nuong' => 'barbecue',
        'bbq' => 'barbecue', 'thai' => 'thai', 'trung hoa' => 'chinese', 'phuong tay' => 'western', 'banh mi' => 'sandwich', 'nhat ban' => 'japanese', 'han quoc' => 'korean', 'mon y' => 'italian', 'mon nhat' => 'japanese', 'mon han' => 'korean', 'mon hoa' => 'chinese', 'mon thai' => 'thai', 'mon au' => 'western',
    ];

    /** từ nối/không mang nghĩa lọc: bỏ khỏi phần tên riêng. */
    private const STOP = ['tim', 'kiem', 'o', 'tai', 'gan', 'nao', 'tot', 'ngon', 'dep', 'nhat', 'nhung', 'cac', 'mot', 'cho', 'co', 'va', 'voi', 'khu', 'vuc', 'quanh', 'day', 'giup', 'toi', 'minh', 'muon', 'di', 'la', 'view', 'gia', 'muc', 'noi', 'tieng', 'hot', 'de', 'duoc'];

    /** @return array<string,mixed> */
    public static function parse(string $q): array
    {
        $out = ['cat' => null, 'kinds' => null, 'levels' => null, 'minPrice' => null, 'maxPrice' => null, 'free' => false, 'veg' => false, 'cuisine' => null,
            'indoor' => null, 'stars' => null, 'radiusKm' => null, 'sort' => null, 'rest' => '', 'specific' => false, 'tags' => []];

        $q = mb_strtolower(trim($q));
        $q = preg_replace('/<\s*=?/u', ' duoi ', $q) ?? $q;
        $q = preg_replace('/>\s*=?/u', ' tren ', $q) ?? $q;
        $q = preg_replace('/(?<=\d|k|tr)\s*[-–—]\s*(?=\d)/u', ' den ', $q) ?? $q;
        $q = preg_replace('/[,;!?()"\']+/u', ' ', $q) ?? $q;
        $orig = preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!$orig) {
            return $out;
        }
        $tok = array_map([self::class, 'fold'], $orig);
        $n = count($tok);
        $used = array_fill(0, $n, false);
        $free = fn(int $i, int $len) => $i + $len <= $n && !in_array(true, array_slice($used, $i, $len), true);
        $take = function (int $i, int $len) use (&$used) { for ($k = $i; $k < $i + $len; $k++) { $used[$k] = true; } };
        $at = fn(int $i, int $len) => implode(' ', array_slice($tok, $i, $len));

        // 1) số tiền, hạng sao, khoảng cách (cần số nên làm trước)
        $numAt = function (int $i) use ($tok, $n, &$used): ?array {   // [giá trị, đơn vị|'', số token chiếm]
            if ($i >= $n || $used[$i] || !preg_match('/^(\d+(?:[.,]\d+)?)([a-z*]*)$/', $tok[$i], $m)) {
                return null;
            }
            $unit = $m[2];
            $len = 1;
            if ($unit === '' && $i + 1 < $n && !$used[$i + 1] && self::unitOf($tok[$i + 1]) !== null) {
                $unit = $tok[$i + 1];
                $len = 2;
            }

            return [(float) str_replace(',', '.', $m[1]), $unit, $len];
        };
        for ($i = 0; $i < $n; $i++) {
            $a = $numAt($i);
            if ($a === null) {
                continue;
            }
            [$num, $unit, $len] = $a;
            if ($unit === 'sao' || $unit === '*') {
                if ($num >= 1 && $num <= 5) { $out['stars'] = (int) $num; $take($i, $len); }
                continue;
            }
            if ($unit === 'km') {
                if ($num > 0 && $num <= 50) { $out['radiusKm'] = $num; $take($i, $len); }
                continue;
            }
            // khoảng "2 đến 5 triệu", "500k đến 1.5tr", "2-5 triệu": số đầu không có đơn vị thì lấy đơn vị của số sau
            $b2 = $i + $len < $n && $tok[$i + $len] === 'den' ? $numAt($i + $len + 1) : null;
            if ($b2 !== null && self::unitOf($b2[1]) !== null && !in_array($b2[1], ['sao', 'km'], true)) {
                $m2 = self::unitOf($b2[1]);
                $m1 = $unit === '' ? $m2 : (self::unitOf($unit) ?? $m2);
                $out['minPrice'] = (int) round($num * $m1);
                $out['maxPrice'] = (int) round($b2[0] * $m2);
                $take($i, $len + 1 + $b2[2]);
                if ($i > 0 && in_array($tok[$i - 1], ['tu', 'khoang'], true) && !$used[$i - 1]) { $take($i - 1, 1); }
                continue;
            }
            $mult = self::unitOf($unit);
            if ($mult === null) {
                if ($unit === '' && $num >= 1000) { $mult = 1.0; } else { continue; }   // số trần nhỏ hơn 1000 không phải tiền
            }
            $val = (int) round($num * $mult);
            $kw = $i > 0 && !$used[$i - 1] ? $tok[$i - 1] : '';
            $kw2 = $i > 1 && !$used[$i - 2] && !$used[$i - 1] ? $tok[$i - 2] . ' ' . $tok[$i - 1] : '';
            $role = 'max';
            $kwLen = 0;
            if ($kw2 === 'it nhat') { $role = 'min'; $kwLen = 2; }
            elseif (in_array($kw2, ['toi da', 'khong qua', 'nhieu nhat'], true)) { $kwLen = 2; }
            elseif (in_array($kw, ['duoi', 'max'], true)) { $kwLen = 1; }
            elseif (in_array($kw, ['tren', 'hon', 'tu'], true)) { $role = 'min'; $kwLen = 1; }
            if ($kwLen) { $take($i - $kwLen, $kwLen); }
            $take($i, $len);
            $out[$role === 'min' ? 'minPrice' : 'maxPrice'] = $val;
        }

        // 2) cụm từ nhiều chữ trước, chữ đơn sau
        for ($len = 3; $len >= 1; $len--) {
            for ($i = 0; $i + $len <= $n; $i++) {
                if (!$free($i, $len)) { continue; }
                $p = $at($i, $len);
                if ($len === 1 && $tok[$i] === 'chay' && $orig[$i] !== 'chay') { continue; }   // "chạy" (động từ) không phải ăn chay
                if ($len === 1 && in_array($p, ['cho', 'chua'], true) && $n > 1 && $orig[$i] === $p) { continue; }   // "cho"/"chưa" không dấu trong câu dài: không phải chợ/chùa
                if (isset(self::CATS[$p]) && $out['cat'] === null) {
                    [$out['cat'], $out['kinds']] = self::CATS[$p];
                    $out['tags'][] = self::catLabel($out['cat']) . ($out['kinds'] && count($tok) > 0 && $len <= 2 && !in_array($p, ['tham quan', 'khach san'], true) ? ' · ' . implode(' ', array_slice($orig, $i, $len)) : '');
                    $take($i, $len);
                } elseif (isset(self::LEVEL_WORDS[$p]) && $out['levels'] === null) {
                    $out['levels'] = self::LEVEL_WORDS[$p];
                    $take($i, $len);
                } elseif ($p === 'chay' || $p === 'an chay' || $p === 'thuc duong') {
                    $out['veg'] = true;
                    $take($i, $len);
                } elseif ($p === 'mien phi' || $p === 'free') {
                    $out['free'] = true;
                    $take($i, $len);
                } elseif (in_array($p, ['trong nha', 'troi mua', 'khi mua', 'co mai che'], true)) {
                    $out['indoor'] = true;
                    $take($i, $len);
                } elseif (in_array($p, ['ngoai troi', 'trong lanh', 'thoang mat'], true)) {
                    $out['indoor'] = false;
                    $take($i, $len);
                } elseif (isset(self::CUISINE[$p]) && $out['cuisine'] === null && ($len > 1 || $i === 0 || in_array($tok[$i - 1] ?? '', ['nha', 'hang', 'mon', 'quan', 'do'], true) || $out['cat'] === 'restaurant')) {
                    $out['cuisine'] = self::CUISINE[$p];
                    $take($i, $len);
                } elseif ($p === 'gan' || $p === 'gan nhat' || $p === 'gan day') {
                    $out['sort'] = 'near';
                    $take($i, $len);
                } elseif (in_array($p, ['noi bat', 'pho bien', 'nhieu nguoi thich', 'hang dau'], true)) {
                    $out['sort'] = 'rank';
                    $take($i, $len);
                }
            }
        }
        // "rẻ nhất": sắp theo giá
        if ($out['levels'] === [1, 1] || $out['maxPrice'] !== null) {
            $out['sort'] ??= null;
        }

        // 3) phần còn lại: bỏ từ nối, phần còn có nghĩa là tên riêng
        $rest = [];
        foreach ($tok as $i => $t) {
            if ($t === 'quan' && $orig[$i] === 'quán' && in_array($out['cat'], ['restaurant', 'cafe', 'bar'], true)) {
                continue;   // "quán" đi kèm loại quán, không phải tên riêng ("quận" là tên khu vực nên giữ lại)
            }
            if (!$used[$i] && !in_array($t, self::STOP, true)) {
                $rest[] = $orig[$i];
            }
        }
        $out['rest'] = trim(implode(' ', $rest));
        $out['specific'] = $out['rest'] !== '';

        // nhãn hiển thị cho từng điều kiện đã hiểu
        if ($out['levels']) { $out['tags'][] = 'Giá ' . self::levelLabel($out['levels']); }
        if ($out['maxPrice'] !== null && $out['minPrice'] !== null) { $out['tags'][] = self::money($out['minPrice']) . ' đến ' . self::money($out['maxPrice']); }
        elseif ($out['maxPrice'] !== null) { $out['tags'][] = 'Dưới ' . self::money($out['maxPrice']); }
        elseif ($out['minPrice'] !== null) { $out['tags'][] = 'Từ ' . self::money($out['minPrice']); }
        if ($out['free']) { $out['tags'][] = 'Miễn phí'; }
        if ($out['veg']) { $out['tags'][] = 'Ăn chay'; }
        if ($out['cuisine']) { $out['tags'][] = 'Món: ' . self::cuisineLabel($out['cuisine']); }
        if ($out['indoor'] !== null) { $out['tags'][] = $out['indoor'] ? 'Trong nhà' : 'Ngoài trời'; }
        if ($out['stars']) { $out['tags'][] = $out['stars'] . ' sao'; }
        if ($out['radiusKm']) { $out['tags'][] = 'Trong ' . rtrim(rtrim(number_format($out['radiusKm'], 1, ',', ''), '0'), ',') . ' km'; }

        return $out;
    }

    /** Có điều kiện lọc hay loại nào được hiểu không (để quyết định hiện bộ lọc). */
    public static function hasCriteria(array $i): bool
    {
        return $i['cat'] !== null || $i['levels'] !== null || $i['maxPrice'] !== null || $i['minPrice'] !== null || $i['free'] || $i['veg']
            || $i['cuisine'] !== null || $i['indoor'] !== null || $i['stars'] !== null;
    }

    /** Bỏ dấu tiếng Việt, về chữ thường. */
    public static function fold(string $s): string
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (['a' => 'àáạảãâầấậẩẫăằắặẳẵ', 'e' => 'èéẹẻẽêềếệểễ', 'i' => 'ìíịỉĩ', 'o' => 'òóọỏõôồốộổỗơờớợởỡ', 'u' => 'ùúụủũưừứựửữ', 'y' => 'ỳýỵỷỹ', 'd' => 'đ'] as $base => $chars) {
                foreach (mb_str_split($chars) as $c) {
                    $map[$c] = $base;
                }
            }
        }

        return strtr(mb_strtolower($s), $map);
    }

    private static function unitOf(string $u): ?float
    {
        return match ($u) {
            'k', 'nghin', 'ngan', 'n' => 1000.0,
            'tr', 'trieu', 'cu', 'm' => 1000000.0,
            'd', 'dong', 'vnd' => 1.0,
            'sao', 'km' => 1.0,   // chỉ để nhận ra đơn vị đứng riêng; xử lý ở chỗ gọi
            default => null,
        };
    }

    public static function catLabel(string $cat): string
    {
        return ['hotel' => 'Khách sạn, lưu trú', 'restaurant' => 'Nhà hàng, quán ăn', 'cafe' => 'Quán cà phê', 'bar' => 'Quán bar', 'market' => 'Chợ, mua sắm',
            'sight' => 'Điểm tham quan', 'experience' => 'Trải nghiệm', 'tour' => 'Tour'][$cat] ?? $cat;
    }

    public static function levelLabel(array $l): string
    {
        $n = ['', 'rẻ', 'vừa phải', 'cao', 'sang'];

        return $l[0] === $l[1] ? $n[$l[0]] : ($l === [3, 4] ? 'cao trở lên' : $n[$l[0]] . ' đến ' . $n[$l[1]]);
    }

    public static function cuisineLabel(string $c): string
    {
        return ['seafood' => 'hải sản', 'japanese' => 'Nhật', 'korean' => 'Hàn', 'pizza' => 'pizza', 'hotpot' => 'lẩu', 'barbecue' => 'nướng', 'italian' => 'Ý',
            'thai' => 'Thái', 'chinese' => 'Hoa', 'western' => 'Âu', 'sandwich' => 'bánh mì', 'vietnamese' => 'Việt'][$c] ?? $c;
    }

    public static function money(int $v): string
    {
        if ($v >= 1000000) { return rtrim(rtrim(number_format($v / 1000000, 1, ',', '.'), '0'), ',') . ' triệu'; }
        if ($v >= 1000) { return number_format($v / 1000, 0, ',', '.') . 'k'; }

        return $v . 'đ';
    }
}
