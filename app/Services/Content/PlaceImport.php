<?php

namespace App\Services\Content;

use App\Services\Planner\OpeningHours;
use App\Support\AuditLog;
use App\Support\Geo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Nhập địa điểm theo lô có duyệt (Đợt C mục 19).
 *
 * 1. stage(): đọc tệp CSV/JSON, chuẩn hóa từng dòng, kiểm tra (lỗi chặn duyệt / cảnh báo cần người xem), dò trùng
 *    với kho tbl_external_places và trong chính tệp, lưu vào tbl_import_rows. Chưa đụng tới kho địa điểm.
 * 2. Người duyệt (quản lý / admin) chọn dòng: approve() tạo địa điểm mới, hoặc bổ sung vào địa điểm trùng (chỉ điền ô đang trống);
 *    reject() ghi lý do. Mỗi quyết định có người và thời điểm, ghi nhật ký thao tác.
 * Địa điểm tạo từ lô ở trạng thái "reviewed"; tick "hiển thị ngay" thì "published".
 */
final class PlaceImport
{
    /** Cột của tệp mẫu; tên cột tiếng Việt tương ứng cũng được nhận. */
    public const COLUMNS = ['dest', 'name', 'category', 'kind', 'lat', 'lng', 'address', 'duration', 'price_min', 'price_max', 'indoor', 'hours', 'website', 'description', 'source', 'source_url'];

    private const ALIASES = [
        'diem_den' => 'dest', 'destination' => 'dest', 'ten' => 'name', 'nhom' => 'category', 'loai' => 'kind', 'vi_do' => 'lat', 'latitude' => 'lat',
        'kinh_do' => 'lng', 'lon' => 'lng', 'longitude' => 'lng', 'dia_chi' => 'address', 'thoi_luong' => 'duration', 'gia_tu' => 'price_min',
        'gia_den' => 'price_max', 'trong_nha' => 'indoor', 'gio_mo_cua' => 'hours', 'opening_hours' => 'hours', 'mo_ta' => 'description',
        'nguon' => 'source', 'lien_ket_nguon' => 'source_url', 'url' => 'source_url',
    ];

    public const MAX_ROWS = 2000;
    public const FAR_KM = 60;        // xa tâm điểm đến hơn thế: cảnh báo (có thể sai tọa độ hoặc sai điểm đến)
    public const DUP_NEAR_KM = 0.4;  // tên gần giống + gần nhau hơn thế: coi là trùng

    public static function ready(): bool
    {
        return Schema::hasTable('tbl_import_rows');
    }

    /** Tệp CSV mẫu (UTF-8 có BOM để Excel mở đúng tiếng Việt). */
    public static function template(): string
    {
        $rows = [
            self::COLUMNS,
            ['da-lat', 'Ga Đà Lạt', 'sight', 'historic', '11.941530', '108.454700', '1 Quang Trung, Phường 9', '45', '10000', '10000', '0', 'Mo-Su 07:00-17:00', '', 'Nhà ga kiến trúc Pháp 1932, có chuyến tàu ngắn đi Trại Mát.', 'Khảo sát thực địa 11/2026', ''],
            ['da-lat', 'Bánh căn Nhà Chung', 'food', 'restaurant', '11.943900', '108.436200', '1 Nhà Chung', '40', '30000', '60000', '1', 'Mo-Su 06:00-11:00', '', '', 'Google Maps', 'https://maps.app.goo.gl/...'],
        ];
        $out = "\xEF\xBB\xBF";
        foreach ($rows as $r) {
            $out .= implode(',', array_map(fn($v) => preg_match('/[",\n]/', $v) ? '"' . str_replace('"', '""', $v) . '"' : $v, $r)) . "\n";
        }

        return $out;
    }

    /**
     * Đọc tệp thành danh sách dòng (mảng cột => giá trị).
     * @return array{rows: list<array{line:int, raw:array<string,string>}>, error: ?string}
     */
    public static function parse(string $content, string $fileName): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        if (str_ends_with(strtolower($fileName), '.json') || preg_match('/^\s*[\[{]/', $content)) {
            $j = json_decode($content, true);
            if (!is_array($j)) {
                return ['rows' => [], 'error' => 'Tệp JSON không đọc được.'];
            }
            $list = array_is_list($j) ? $j : ($j['places'] ?? []);
            $rows = [];
            foreach ($list as $i => $r) {
                if (is_array($r)) {
                    $rows[] = ['line' => $i + 1, 'raw' => self::keys($r)];
                }
            }

            return self::cap($rows);
        }

        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $content);
        rewind($fh);
        $first = fgets($fh) ?: '';
        $sep = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';   // Excel tiếng Việt hay lưu bằng dấu chấm phẩy
        rewind($fh);
        $head = fgetcsv($fh, 0, $sep, '"', '');
        if (!$head) {
            return ['rows' => [], 'error' => 'Tệp trống.'];
        }
        $head = array_map(fn($h) => self::key((string) $h), $head);
        if (!in_array('name', $head, true)) {
            return ['rows' => [], 'error' => 'Không thấy cột "name" (tên địa điểm). Dùng tệp mẫu để đúng tên cột.'];
        }
        $rows = [];
        $line = 1;
        while (($r = fgetcsv($fh, 0, $sep, '"', '')) !== false) {
            $line++;
            if ($r === [null] || implode('', $r) === '') {
                continue;
            }
            $raw = [];
            foreach ($head as $i => $k) {
                $raw[$k] = trim((string) ($r[$i] ?? ''));
            }
            $rows[] = ['line' => $line, 'raw' => $raw];
        }
        fclose($fh);

        return self::cap($rows);
    }

    private static function cap(array $rows): array
    {
        if (!$rows) {
            return ['rows' => [], 'error' => 'Tệp không có dòng dữ liệu nào.'];
        }
        if (count($rows) > self::MAX_ROWS) {
            return ['rows' => [], 'error' => 'Tối đa ' . self::MAX_ROWS . ' dòng mỗi lô. Chia tệp nhỏ hơn.'];
        }

        return ['rows' => $rows, 'error' => null];
    }

    private static function keys(array $r): array
    {
        $out = [];
        foreach ($r as $k => $v) {
            $out[self::key((string) $k)] = is_scalar($v) ? trim((string) $v) : '';
        }

        return $out;
    }

    private static function key(string $h): string
    {
        $k = trim(strtolower(Str::ascii($h)));
        $k = preg_replace('/[^a-z0-9]+/', '_', $k) ?? $k;
        $k = trim($k, '_');

        return self::ALIASES[$k] ?? $k;
    }

    /**
     * Chuẩn hóa + kiểm tra một dòng. $dests: slug => object điểm đến; $seen: tên đã gặp trong lô (dò trùng nội bộ).
     * @return array{data:array, issues:list<array{level:string,code:string,msg:string}>, destinationId:?int, matchPlaceId:?int}
     */
    public static function check(array $raw, array $dests, array &$seen): array
    {
        $issues = [];
        $err = function (string $code, string $msg) use (&$issues) { $issues[] = ['level' => 'error', 'code' => $code, 'msg' => $msg]; };
        $warn = function (string $code, string $msg) use (&$issues) { $issues[] = ['level' => 'warn', 'code' => $code, 'msg' => $msg]; };
        $num = fn($v) => ($v === null || $v === '') ? null : (is_numeric(str_replace([' ', ','], ['', '.'], (string) $v)) ? (float) str_replace([' ', ','], ['', '.'], (string) $v) : false);

        $d = [
            'name' => mb_substr(preg_replace('/\s+/u', ' ', (string) ($raw['name'] ?? '')) ?? '', 0, 160),
            'category' => strtolower((string) ($raw['category'] ?? '')),
            'kind' => strtolower(preg_replace('/\s+/', '_', trim((string) ($raw['kind'] ?? ''))) ?? '') ?: null,
            'address' => mb_substr((string) ($raw['address'] ?? ''), 0, 255) ?: null,
            'website' => mb_substr((string) ($raw['website'] ?? ''), 0, 255) ?: null,
            'description' => (string) ($raw['description'] ?? '') ?: null,
            'hours' => (string) ($raw['hours'] ?? '') ?: null,
            'source' => mb_substr((string) ($raw['source'] ?? ''), 0, 120) ?: null,
            'sourceUrl' => mb_substr((string) ($raw['source_url'] ?? ''), 0, 255) ?: null,
        ];
        $d['name'] = trim($d['name']);
        if ($d['name'] === '') {
            $err('no_name', 'Thiếu tên địa điểm.');
        }

        $map = ['tham_quan' => 'sight', 'sight' => 'sight', 'see' => 'sight', 'an_uong' => 'food', 'food' => 'food', 'eat' => 'food'];
        $catKey = trim(preg_replace('/[^a-z]+/', '_', strtolower(Str::ascii($d['category']))) ?? '', '_');
        if ($catKey === '' && $d['kind']) {
            $catKey = in_array($d['kind'], ['restaurant', 'cafe', 'fast_food', 'food_court', 'bar', 'street_food'], true) ? 'food' : 'sight';
        }
        $d['category'] = $map[$catKey] ?? null;
        if (!$d['category']) {
            $err('bad_category', 'Nhóm phải là sight (tham quan) hoặc food (ăn uống).');
        }

        $dest = $dests[strtolower((string) ($raw['dest'] ?? ''))] ?? null;
        if (!$dest) {
            $err('no_dest', 'Không có điểm đến "' . ($raw['dest'] ?? '') . '" (dùng mã slug, ví dụ da-lat).');
        }

        $lat = $num($raw['lat'] ?? null);
        $lng = $num($raw['lng'] ?? null);
        if ($lat === null || $lng === null || $lat === false || $lng === false || $lat < 8 || $lat > 24 || $lng < 102 || $lng > 110) {
            $err('bad_coords', 'Tọa độ thiếu hoặc nằm ngoài Việt Nam (vĩ độ 8–24, kinh độ 102–110). Kiểm tra có bị đảo vĩ/kinh độ không.');
            $lat = $lng = null;
        }
        $d['lat'] = $lat !== null ? round($lat, 6) : null;
        $d['lng'] = $lng !== null ? round($lng, 6) : null;
        if ($dest && $lat !== null) {
            $km = Geo::distanceKm((float) $dest->latitude, (float) $dest->longitude, $lat, $lng);
            if ($km > self::FAR_KM) {
                $warn('far', sprintf('Cách trung tâm %s %d km: kiểm tra tọa độ hoặc điểm đến.', $dest->name, (int) round($km)));
            }
        }

        $dur = $num($raw['duration'] ?? null);
        $d['duration'] = ($dur && $dur >= 5 && $dur <= 720) ? (int) $dur : null;
        if ($dur !== null && $d['duration'] === null) {
            $warn('bad_duration', 'Thời lượng phải từ 5 đến 720 phút; bỏ giá trị này.');
        }
        $pmin = $num($raw['price_min'] ?? null);
        $pmax = $num($raw['price_max'] ?? null);
        if ($pmin === false || $pmax === false || ($pmin !== null && $pmin < 0) || ($pmax !== null && $pmax < 0)) {
            $err('bad_price', 'Giá phải là số không âm (đồng).');
            $pmin = $pmax = null;
        } elseif ($pmin !== null && $pmax !== null && $pmin > $pmax) {
            $err('price_order', 'Giá từ lớn hơn giá đến.');
        }
        $d['priceMin'] = $pmin;
        $d['priceMax'] = $pmax ?? $pmin;
        $in = strtolower(Str::ascii((string) ($raw['indoor'] ?? '')));
        $d['indoor'] = in_array($in, ['1', 'co', 'yes', 'true', 'x'], true) ? 1 : (in_array($in, ['0', 'khong', 'no', 'false'], true) ? 0 : null);

        if ($d['hours']) {
            if (!OpeningHours::parseOsm($d['hours'])) {
                $warn('hours_unparsed', 'Không đọc được giờ mở cửa (dùng dạng "Mo-Su 08:00-17:00"); bỏ qua giờ.');
                $d['hours'] = null;
            }
        } else {
            $warn('no_hours', 'Chưa có giờ mở cửa: bộ xếp lịch sẽ không cảnh báo đóng cửa cho nơi này.');
        }
        if (!$d['source']) {
            $err('no_source', 'Thiếu nguồn dữ liệu (ví dụ "Khảo sát thực địa", "Trang chính thức", "Google Maps").');
        }
        if ($d['website'] && !preg_match('#^https?://#i', $d['website'])) {
            $warn('bad_url', 'Website không bắt đầu bằng http(s)://; bỏ giá trị này.');
            $d['website'] = null;
        }
        if ($d['sourceUrl'] && !preg_match('#^https?://#i', $d['sourceUrl'])) {
            $d['sourceUrl'] = null;
        }
        if ($d['description'] && mb_strlen($d['description']) > 2000) {
            $d['description'] = mb_substr($d['description'], 0, 2000);
            $warn('long_desc', 'Mô tả dài quá 2000 ký tự, đã cắt.');
        }

        // Trùng trong chính tệp
        $norm = self::norm($d['name']);
        $key = ($dest->destinationId ?? 0) . '|' . $norm;
        if ($norm !== '' && isset($seen[$key])) {
            $warn('dup_batch', 'Trùng tên với dòng ' . $seen[$key] . ' trong cùng tệp.');
        }
        // Trùng với kho đã có
        $match = null;
        if ($dest && $lat !== null && $norm !== '') {
            $match = self::findDuplicate((int) $dest->destinationId, $d['name'], $lat, $lng);
            if ($match) {
                $warn('dup_existing', 'Có thể trùng "' . $match->name . '" (#' . $match->placeId . ', cách ' . (int) round($match->km * 1000) . ' m). Duyệt sẽ bổ sung vào địa điểm này thay vì tạo mới.');
            }
        }

        return ['data' => $d, 'issues' => $issues, 'destinationId' => $dest->destinationId ?? null, 'matchPlaceId' => $match->placeId ?? null, 'normKey' => $key];
    }

    /** Tên rút gọn để so trùng: bỏ dấu, chữ thường, bỏ tiền tố chung. */
    public static function norm(string $name): string
    {
        $s = strtolower(Str::ascii($name));
        $s = preg_replace('/\b(quan|nha hang|cafe|ca phe|coffee|chua|den|bao tang|cong vien|khu du lich|kdl)\b/', ' ', $s) ?? $s;

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $s) ?? $s);
    }

    private static function findDuplicate(int $destId, string $name, float $lat, float $lng): ?object
    {
        [$minLat, $maxLat, $minLng, $maxLng] = array_values(Geo::bbox($lat, $lng, 3));
        $cands = DB::table('tbl_external_places')->where('destinationId', $destId)
            ->whereBetween('latitude', [$minLat, $maxLat])->whereBetween('longitude', [$minLng, $maxLng])
            ->get(['placeId', 'name', 'latitude', 'longitude']);
        $n = self::norm($name);
        $best = null;
        foreach ($cands as $c) {
            $km = Geo::distanceKm($lat, $lng, (float) $c->latitude, (float) $c->longitude);
            $cn = self::norm($c->name);
            $same = $cn === $n;
            similar_text($cn, $n, $pct);
            $close = $pct >= 88 || (strlen($n) >= 6 && (str_contains($cn, $n) || str_contains($n, $cn)));
            if (($same && $km <= 2) || ($close && $km <= self::DUP_NEAR_KM)) {
                if (!$best || $km < $best->km) {
                    $c->km = $km;
                    $best = $c;
                }
            }
        }

        return $best;
    }

    /**
     * Tạo lô từ nội dung tệp. @return array{batchId:?int, error:?string, total:int, bad:int, warn:int, dup:int}
     */
    public static function stage(string $content, string $fileName, ?int $adminId, ?string $note = null): array
    {
        $p = self::parse($content, $fileName);
        if ($p['error']) {
            return ['batchId' => null, 'error' => $p['error'], 'total' => 0, 'bad' => 0, 'warn' => 0, 'dup' => 0];
        }
        $dests = [];
        foreach (DB::table('tbl_destinations')->get(['destinationId', 'slug', 'name', 'latitude', 'longitude']) as $d) {
            $dests[strtolower($d->slug)] = $d;
        }

        return DB::transaction(function () use ($p, $dests, $fileName, $adminId, $note) {
            $bid = (int) DB::table('tbl_import_batches')->insertGetId([
                'kind' => 'places', 'fileName' => mb_substr($fileName, 0, 160), 'note' => $note ? mb_substr($note, 0, 255) : null,
                'createdBy' => $adminId, 'createdAt' => now(),
            ]);
            $seen = [];
            $sum = ['bad' => 0, 'warn' => 0, 'dup' => 0];
            $ins = [];
            foreach ($p['rows'] as $r) {
                $c = self::check($r['raw'], $dests, $seen);
                if (!isset($seen[$c['normKey']]) && $c['data']['name'] !== '') {
                    $seen[$c['normKey']] = $r['line'];
                }
                $levels = array_column($c['issues'], 'level');
                $level = in_array('error', $levels, true) ? 'error' : (in_array('warn', $levels, true) ? 'warn' : 'ok');
                $sum['bad'] += $level === 'error' ? 1 : 0;
                $sum['warn'] += $level === 'warn' ? 1 : 0;
                $sum['dup'] += $c['matchPlaceId'] ? 1 : 0;
                $ins[] = [
                    'batchId' => $bid, 'line' => $r['line'], 'destinationId' => $c['destinationId'],
                    'data' => json_encode($c['data'], JSON_UNESCAPED_UNICODE), 'issues' => json_encode($c['issues'], JSON_UNESCAPED_UNICODE),
                    'level' => $level, 'matchPlaceId' => $c['matchPlaceId'],
                ];
            }
            foreach (array_chunk($ins, 200) as $chunk) {
                DB::table('tbl_import_rows')->insert($chunk);
            }
            DB::table('tbl_import_batches')->where('batchId', $bid)->update(['total' => count($ins)] + $sum);
            AuditLog::record('import.stage', 'system', $bid, sprintf('Nạp lô địa điểm "%s": %d dòng, %d lỗi, %d trùng', $fileName, count($ins), $sum['bad'], $sum['dup']));

            return ['batchId' => $bid, 'error' => null, 'total' => count($ins)] + $sum;
        });
    }

    /**
     * Duyệt các dòng: tạo địa điểm mới, hoặc bổ sung vào địa điểm trùng. Dòng có lỗi hoặc đã quyết định thì bỏ qua.
     * @param list<int> $rowIds
     * @return array{created:int, merged:int, skipped:int}
     */
    public static function approve(int $batchId, array $rowIds, int $adminId, bool $publish = false): array
    {
        $out = ['created' => 0, 'merged' => 0, 'skipped' => 0];
        $rows = DB::table('tbl_import_rows')->where('batchId', $batchId)->whereIn('rowId', $rowIds)->get();
        $hours = app(OpeningHours::class);
        foreach ($rows as $r) {
            if ($r->status !== 'pending' || $r->level === 'error' || !$r->destinationId) {
                $out['skipped']++;
                continue;
            }
            $d = json_decode($r->data, true);
            DB::transaction(function () use ($r, $d, $adminId, $publish, $hours, &$out) {
                // khóa dòng: hai người bấm duyệt cùng lúc thì chỉ một lần có hiệu lực
                $n = DB::table('tbl_import_rows')->where('rowId', $r->rowId)->where('status', 'pending')
                    ->update(['status' => 'deciding', 'decidedBy' => $adminId, 'decidedAt' => now()]);
                if (!$n) {
                    $out['skipped']++;
                    return;
                }
                $existing = $r->matchPlaceId ? DB::table('tbl_external_places')->where('placeId', $r->matchPlaceId)->first() : null;
                if ($existing) {
                    $fill = [];
                    foreach (['kind' => 'kind', 'address' => 'address', 'durationMinutes' => 'duration', 'priceMin' => 'priceMin', 'priceMax' => 'priceMax',
                        'indoor' => 'indoor', 'website' => 'website', 'description' => 'description'] as $col => $k) {
                        if (($existing->$col === null || $existing->$col === '') && ($d[$k] ?? null) !== null) {
                            $fill[$col] = $d[$k];
                        }
                    }
                    if ($fill && empty($existing->factsSource)) {
                        $fill += ['factsSource' => $d['source'], 'factsUrl' => $d['sourceUrl'], 'factsCheckedAt' => now()];
                    }
                    // tick "Hiển thị ngay": địa điểm có sẵn (vd nạp từ OSM, đang nháp / đã duyệt) cũng được công khai,
                    // nếu không điểm đến vẫn báo 0 nơi dù lô đã duyệt hết
                    if ($publish && $existing->reviewStatus !== 'published' && $existing->reviewStatus !== 'rejected') {
                        $fill += ['reviewStatus' => 'published', 'reviewedBy' => $adminId, 'reviewedAt' => now()];
                    }
                    if ($fill) {
                        DB::table('tbl_external_places')->where('placeId', $existing->placeId)->update($fill);
                    }
                    $placeId = (int) $existing->placeId;
                    $hasHours = DB::table('tbl_opening_hours')->where('refType', 'place')->where('refId', $placeId)->exists();
                    if ($d['hours'] && !$hasHours) {
                        $hours->store('place', $placeId, $d['hours'], 'import');
                    }
                    $status = 'merged';
                    $out['merged']++;
                } else {
                    $placeId = (int) DB::table('tbl_external_places')->insertGetId([
                        'destinationId' => $r->destinationId, 'source' => 'import', 'externalId' => 'b' . $r->batchId . '-' . $r->line,
                        'name' => $d['name'], 'category' => $d['category'], 'kind' => $d['kind'], 'address' => $d['address'],
                        'latitude' => $d['lat'], 'longitude' => $d['lng'], 'rank' => 0, 'durationMinutes' => $d['duration'],
                        'priceMin' => $d['priceMin'], 'priceMax' => $d['priceMax'], 'indoor' => $d['indoor'], 'website' => $d['website'],
                        'description' => $d['description'], 'descriptionSource' => $d['description'] ? $d['source'] : null,
                        'factsSource' => $d['source'], 'factsUrl' => $d['sourceUrl'], 'factsCheckedAt' => now(),
                        'reviewStatus' => $publish ? 'published' : 'reviewed', 'reviewedBy' => $adminId, 'reviewedAt' => now(),
                        'reviewNote' => 'Nhập từ lô #' . $r->batchId . ', dòng ' . $r->line, 'syncedAt' => now(),
                    ]);
                    if ($d['hours']) {
                        $hours->store('place', $placeId, $d['hours'], 'import');
                    }
                    $status = 'created';
                    $out['created']++;
                }
                DB::table('tbl_import_rows')->where('rowId', $r->rowId)->update(['status' => $status, 'placeId' => $placeId]);
            });
        }
        if ($out['created'] + $out['merged']) {
            PublicCache::flush();
            AuditLog::record('import.approve', 'system', $batchId, sprintf('Duyệt lô #%d: tạo %d, bổ sung %d%s', $batchId, $out['created'], $out['merged'], $publish ? ', hiển thị ngay' : ''));
        }

        return $out;
    }

    /** @param list<int> $rowIds */
    public static function reject(int $batchId, array $rowIds, int $adminId, string $note): int
    {
        $n = DB::table('tbl_import_rows')->where('batchId', $batchId)->whereIn('rowId', $rowIds)->where('status', 'pending')
            ->update(['status' => 'rejected', 'decidedBy' => $adminId, 'decidedAt' => now(), 'note' => mb_substr($note, 0, 255) ?: null]);
        if ($n) {
            AuditLog::record('import.reject', 'system', $batchId, sprintf('Loại %d dòng ở lô #%d%s', $n, $batchId, $note ? ': ' . $note : ''));
        }

        return $n;
    }

    /**
     * Số địa điểm đang hiển thị theo nhóm cho các điểm đến của lô, và số sẽ thêm nếu duyệt hết dòng hợp lệ.
     * @return list<array{dest:string, sight:int, food:int, addSight:int, addFood:int}>
     */
    public static function coverage(int $batchId): array
    {
        $pending = DB::table('tbl_import_rows as r')->where('r.batchId', $batchId)->where('r.status', 'pending')
            ->where('r.level', '!=', 'error')->whereNull('r.matchPlaceId')->whereNotNull('r.destinationId')
            ->get(['r.destinationId', 'r.data']);
        $add = [];
        foreach ($pending as $p) {
            $cat = json_decode($p->data, true)['category'] ?? 'sight';
            $add[$p->destinationId][$cat] = ($add[$p->destinationId][$cat] ?? 0) + 1;
        }
        $destIds = DB::table('tbl_import_rows')->where('batchId', $batchId)->whereNotNull('destinationId')->distinct()->pluck('destinationId')->all();
        if (!$destIds) {
            return [];
        }
        $have = DB::table('tbl_external_places')->whereIn('destinationId', $destIds)->where('reviewStatus', 'published')
            ->groupBy('destinationId', 'category')->selectRaw('destinationId, category, COUNT(*) as n')->get();
        $h = [];
        foreach ($have as $x) {
            $h[$x->destinationId][$x->category] = (int) $x->n;
        }
        $names = DB::table('tbl_destinations')->whereIn('destinationId', $destIds)->pluck('name', 'destinationId');
        $out = [];
        foreach ($destIds as $id) {
            $out[] = ['dest' => (string) ($names[$id] ?? $id), 'sight' => $h[$id]['sight'] ?? 0, 'food' => $h[$id]['food'] ?? 0,
                'addSight' => $add[$id]['sight'] ?? 0, 'addFood' => $add[$id]['food'] ?? 0];
        }

        return $out;
    }
}
