<?php

namespace App\Services\Planner;

use App\Services\Ai\AiGateway;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Đọc email xác nhận / vé (Đợt C mục 20) thành các ô của biểu mẫu "Lưu xác nhận đặt chỗ".
 * KHÔNG lưu gì: kết quả chỉ để điền sẵn, người dùng xem lại rồi tự bấm Lưu.
 *
 * - Chữ dán vào (email, tin nhắn): đọc bằng luật, không cần mạng, không gửi dữ liệu đi đâu.
 * - PDF: lấy chữ bằng pdftotext (nếu máy chủ có) rồi đọc bằng luật.
 * - Ảnh vé / ảnh chụp màn hình: cần mô hình AI đọc ảnh (AI_PLANNER_ENABLED + khóa); chưa bật thì báo để người dùng dán chữ hoặc nhập tay.
 *
 * Kết quả: ['ok', 'fields' => [kind,title,provider,code,startAt,endAt,price], 'found' => [...], 'missing' => [...], 'engine', 'message']
 */
final class BookingReader
{
    public const FIELDS = ['kind', 'title', 'provider', 'code', 'startAt', 'endAt', 'price'];

    private const AIRLINES = ['VN' => 'Vietnam Airlines', 'VJ' => 'Vietjet', 'QH' => 'Bamboo Airways', 'BL' => 'Pacific Airlines', 'VU' => 'Vietravel Airlines', '9G' => 'Sun PhuQuoc'];
    private const SITES = [
        'booking.com' => 'Booking.com', 'agoda' => 'Agoda', 'traveloka' => 'Traveloka', 'trip.com' => 'Trip.com', 'expedia' => 'Expedia', 'airbnb' => 'Airbnb',
        'klook' => 'Klook', 'vexere' => 'Vexere', 'mytour' => 'Mytour', 'ivivu' => 'iVIVU', 'dsvn' => 'Đường sắt VN', 'duong sat' => 'Đường sắt VN', 'futa' => 'Phương Trang', 'phuong trang' => 'Phương Trang',
    ];
    private const MONTHS_EN = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    public function __construct(private AiGateway $ai) {}

    /** Đọc chữ (email / tin nhắn). $hintDate: ngày đi của chuyến (Y-m-d) để đoán năm khi vé chỉ ghi ngày/tháng. */
    public function fromText(string $text, ?string $hintDate = null): array
    {
        $t = $this->clean($text);
        if (mb_strlen($t) < 12) {
            return $this->result([], 'rules', 'Nội dung quá ngắn để đọc.');
        }
        $f = $this->rules($t, $hintDate);

        return $this->result($f, 'rules');
    }

    public function fromFile(UploadedFile $file, ?int $userId, ?string $hintDate = null): array
    {
        $mime = (string) $file->getMimeType();
        if ($mime === 'application/pdf') {
            $text = $this->pdfText($file->getRealPath());
            if ($text === null) {
                return $this->result([], 'pdf', 'Máy chủ chưa đọc được PDF. Mở PDF, chép phần chữ và dán vào ô bên trên.');
            }
            if (mb_strlen(trim($text)) < 12) {
                return $this->result([], 'pdf', 'PDF này là ảnh quét, không có chữ để đọc. Dán nội dung email hoặc nhập tay.');
            }
            $r = $this->fromText($text, $hintDate);
            $r['engine'] = 'pdf';

            return $r;
        }
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return $this->result([], 'none', 'Chỉ đọc được PDF hoặc ảnh JPG / PNG / WebP.');
        }
        if ($this->ai->blocked('booking_read', $userId) !== null) {
            return $this->result([], 'none', 'Đọc ảnh vé cần bật trợ lý AI. Hãy dán nội dung email xác nhận, hoặc nhập tay — ảnh vẫn được đính kèm khi lưu.');
        }

        return $this->fromImage((string) file_get_contents($file->getRealPath()), $mime, $userId, $hintDate);
    }

    /** Ảnh vé qua mô hình đọc ảnh, rồi kiểm lại từng ô bằng cùng luật như chữ (ngày hợp lệ, mã đúng dạng, giá là số). */
    public function fromImage(string $bytes, string $mime, ?int $userId, ?string $hintDate = null): array
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'kind' => ['type' => 'string', 'enum' => ['hotel', 'flight', 'train', 'bus', 'ticket', 'unknown']],
                'title' => ['type' => 'string', 'description' => 'Tên khách sạn, hoặc số hiệu + chặng (VN 1543 SGN-DLI), hoặc tên vé'],
                'provider' => ['type' => 'string', 'description' => 'Hãng / nơi đặt (Vietnam Airlines, Agoda...)'],
                'code' => ['type' => 'string', 'description' => 'Mã đặt chỗ / mã xác nhận, đúng như in trên vé'],
                'start' => ['type' => 'string', 'description' => 'Ngày giờ nhận phòng / khởi hành, dạng YYYY-MM-DD HH:MM'],
                'end' => ['type' => 'string', 'description' => 'Ngày giờ trả phòng / đến nơi, dạng YYYY-MM-DD HH:MM, để trống nếu không có'],
                'price' => ['type' => 'number', 'description' => 'Tổng tiền đã trả, đơn vị đồng; 0 nếu không thấy'],
            ],
            'required' => ['kind', 'title', 'code', 'start'],
        ];
        $sys = 'Bạn đọc ảnh vé / xác nhận đặt chỗ du lịch. Chỉ chép thông tin THẤY RÕ trên ảnh, không đoán. Ô nào không thấy thì để chuỗi rỗng.';
        $r = $this->ai->json('booking_read', $userId, $sys, 'Đọc ảnh xác nhận đặt chỗ này.' . ($hintDate ? " Chuyến đi bắt đầu khoảng $hintDate." : ''), $schema,
            ['images' => [['mime' => $mime, 'data' => base64_encode($bytes)]]]);
        $this->ai->log(['feature' => 'booking_read', 'userId' => $userId, 'engine' => 'llm', 'outcome' => $r['ok'] ? 'ok' : (string) ($r['reason'] ?? 'error'),
            'model' => $r['model'] ?? null, 'inputChars' => strlen($bytes), 'in' => $r['in'] ?? null, 'out' => $r['out'] ?? null, 'ms' => $r['ms'] ?? 0, 'error' => $r['error'] ?? null]);
        if (!$r['ok']) {
            return $this->result([], 'ai', 'Chưa đọc được ảnh lúc này. Dán nội dung email hoặc nhập tay.');
        }
        $d = (array) $r['data'];
        $f = [];
        if (in_array($d['kind'] ?? '', ['hotel', 'flight', 'train', 'bus', 'ticket'], true)) {
            $f['kind'] = $d['kind'];
        }
        foreach (['title' => 120, 'provider' => 20] as $k => $max) {
            if (trim((string) ($d[$k] ?? '')) !== '') {
                $f[$k] = mb_substr(trim((string) $d[$k]), 0, $max);
            }
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9\-_. ]{2,39}$/', trim((string) ($d['code'] ?? '')))) {
            $f['code'] = strtoupper(trim((string) $d['code']));
        }
        foreach (['start' => 'startAt', 'end' => 'endAt'] as $k => $to) {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{1,2}):(\d{2}))?/', trim((string) ($d[$k] ?? '')), $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $f[$to] = sprintf('%s-%s-%sT%02d:%s', $m[1], $m[2], $m[3], (int) ($m[4] ?? 0), $m[5] ?? '00');
            }
        }
        if (is_numeric($d['price'] ?? null) && $d['price'] > 0 && $d['price'] < 1e9) {
            $f['price'] = (int) round((float) $d['price']);
        }

        return $this->result($f, 'ai');
    }

    private function result(array $f, string $engine, ?string $message = null): array
    {
        $found = array_values(array_intersect(self::FIELDS, array_keys($f)));
        $needed = ['kind', 'title', 'startAt'];
        $missing = array_values(array_diff(array_merge($needed, ['code']), $found));
        $ok = $message === null && count(array_intersect($needed, $found)) >= 2;
        if ($message === null) {
            $message = $ok
                ? 'Đã điền ' . count($found) . ' ô. Kiểm tra lại các ô được tô rồi bấm "Lưu xác nhận".'
                : 'Chỉ đọc được ' . count($found) . ' ô. Kiểm tra và điền nốt phần còn thiếu.';
        }

        return ['ok' => $ok, 'fields' => $f, 'found' => $found, 'missing' => $missing, 'engine' => $engine, 'message' => $message];
    }

    private function clean(string $s): string
    {
        if (stripos($s, '<html') !== false || preg_match('/<(td|div|p|br|table)\b/i', $s)) {
            $s = preg_replace('/<(br|\/p|\/div|\/tr|\/h\d)\b[^>]*>/i', "\n", $s) ?? $s;
            $s = preg_replace('/<\/t[dh]>/i', ' ', $s) ?? $s;
            $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $s = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $s);
        $s = preg_replace('/[ \t]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\n\s*\n+/u', "\n", $s) ?? $s;

        return trim(mb_substr($s, 0, 20000));
    }

    private function pdfText(string $path): ?string
    {
        $bin = trim((string) @shell_exec('command -v pdftotext 2>/dev/null'));
        if ($bin === '') {
            return null;
        }
        $p = new Process([$bin, '-layout', '-l', '3', $path, '-']);
        $p->setTimeout(10);
        $p->run();

        return $p->isSuccessful() ? $p->getOutput() : null;
    }

    /** @return array<string,mixed> */
    public function rules(string $t, ?string $hintDate = null): array
    {
        $ascii = strtolower(Str::ascii($t));
        $f = [];

        // ---- nơi đặt / hãng
        foreach (self::SITES as $needle => $name) {
            if (str_contains($ascii, $needle)) {
                $f['provider'] = $name;
                break;
            }
        }

        // ---- loại
        $flightNo = null;
        if (preg_match('/\b(VN|VJ|QH|BL|VU|9G)\s?-?(\d{2,4})\b/', $t, $m)) {
            $flightNo = $m[1] . ' ' . $m[2];
            $f['provider'] = self::AIRLINES[$m[1]];
        }
        $train = preg_match('/\b(SE\d{1,2}|SNT\d{1,2}|SPT\d{1,2}|SQN\d{1,2}|TN\d{1,2}|NA\d{1,2}|SP\d{1,2}|HP\d|LC\d)\b/', $t, $tm) ? $tm[1] : null;
        $score = [
            'hotel' => preg_match_all('/nhan phong|tra phong|check-?in|check-?out|khach san|hotel|resort|homestay|villa|so dem|\bdem\b|nights?|phong\b|room/', $ascii),
            'flight' => preg_match_all('/chuyen bay|flight|cat canh|hanh ly|baggage|boarding|san bay|airport|cua ra tau bay|gate/', $ascii) + ($flightNo ? 3 : 0),
            'train' => preg_match_all('/\btau\b|toa\b|giuong|ga\s+[a-z]|train|duong sat|carriage/', $ascii) + ($train ? 3 : 0),
            'bus' => preg_match_all('/xe khach|nha xe|limousine|giuong nam|ben xe|xe giuong|bus\b|vexere|phuong trang|futa/', $ascii),
            'ticket' => preg_match_all('/ve tham quan|ve vao cong|ve vao cua|admission|e-?ticket for|show|tour ticket|ve show|cap treo/', $ascii),
        ];
        arsort($score);
        if (reset($score) > 0) {
            $f['kind'] = (string) key($score);
        }
        $kind = $f['kind'] ?? null;

        // ---- mã đặt chỗ
        $labels = '(?:m[ãa]\s*(?:đặt\s*chỗ|đặt\s*phòng|xác\s*nhận|vé|đơn(?:\s*hàng)?|đặt\s*vé)|s[ốo]\s*xác\s*nhận|booking\s*(?:code|reference|ref\.?|number|no\.?|id)|confirmation\s*(?:number|code|no\.?|#)?|reservation\s*(?:number|code|no\.?)|itinerary\s*(?:number|no\.?)|pnr|order\s*(?:number|id|no\.?))';
        if (preg_match('/' . $labels . '\s*[:#]?\s*(?:là\s*)?([A-Z0-9][A-Z0-9\-.]{3,30})/iu', $t, $m)) {
            $code = rtrim($m[1], '.-');
            if (strlen($code) <= 40 && preg_match('/\d|^[A-Z]{5,7}$/', $code)) {
                $f['code'] = strtoupper($code);
            }
        }

        // ---- ngày giờ
        $year = $hintDate ? (int) substr($hintDate, 0, 4) : (int) date('Y');
        $dates = $this->dates($t, $year, $hintDate);
        $pick = function (string $labelRe) use ($t, $dates) {
            if (!preg_match('/' . $labelRe . '/iu', $t, $m, PREG_OFFSET_CAPTURE)) {
                return null;
            }
            $at = $m[0][1];
            foreach ($dates as $d) {
                if ($d['pos'] >= $at && $d['pos'] - $at < 120) {
                    return $d;
                }
            }

            return null;
        };
        if ($kind === 'hotel') {
            $in = $pick('nhận\s*phòng|check-?\s?in|ngày\s*đến|arrival');
            $out = $pick('trả\s*phòng|check-?\s?out|ngày\s*đi|departure');
            $in ??= $dates[0] ?? null;
            $out ??= ($in && isset($dates[1]) && $dates[1]['date'] > $in['date']) ? $dates[1] : null;
            if ($in) {
                $f['startAt'] = $in['date'] . 'T' . ($in['time'] ?? '14:00');
            }
            if ($out && (!$in || $out['date'] > $in['date'])) {
                $f['endAt'] = $out['date'] . 'T' . ($out['time'] ?? '12:00');
            }
        } elseif ($dates) {
            $dep = $pick('khởi\s*hành|giờ\s*(?:bay|đi|chạy)|cất\s*cánh|departure|depart|ngày\s*đi|lên\s*xe|giờ\s*tàu') ?? $dates[0];
            $f['startAt'] = $dep['date'] . 'T' . ($dep['time'] ?? '08:00');
            $arr = $pick('đến\s*nơi|hạ\s*cánh|arrival|arrive|giờ\s*đến');
            if (!$arr) {
                foreach ($dates as $d) {
                    if ($d['pos'] > $dep['pos'] && ($d['time'] ?? null) && ($d['date'] . $d['time']) > ($dep['date'] . ($dep['time'] ?? ''))) {
                        $arr = $d;
                        break;
                    }
                }
            }
            if ($arr && ($arr['time'] ?? null) && ($arr['date'] . 'T' . $arr['time']) > $f['startAt']) {
                $f['endAt'] = $arr['date'] . 'T' . $arr['time'];
            }
        }

        // ---- giá
        $money = '(\d{1,3}(?:[.,\s]\d{3})+|\d{4,10})(?:[.,]00)?';
        $price = null;
        if (preg_match('/(?:tổng\s*(?:cộng|tiền|thanh\s*toán|giá)?|thành\s*tiền|đã\s*(?:thanh\s*toán|trả)|total(?:\s*(?:price|amount|paid))?|amount\s*paid|grand\s*total)\s*[:\-]?\s*(?:VND|VNĐ|₫|đ)?\s*' . $money . '\s*(VND|VNĐ|₫|đ|dong|đồng)?/iu', $t, $m)) {
            $price = $m[1];
        } elseif (preg_match_all('/' . $money . '\s*(?:VND|VNĐ|₫|đ\b|đồng)/iu', $t, $mm)) {
            $price = collect($mm[1])->sortByDesc(fn($v) => (int) preg_replace('/\D/', '', $v))->first();
        }
        if ($price !== null) {
            $n = (int) preg_replace('/\D/', '', $price);
            if ($n >= 1000 && $n < 1000000000) {
                $f['price'] = $n;
            }
        }

        // ---- tên
        $route = $this->route($t);
        if ($kind === 'flight' && ($flightNo || $route)) {
            $f['title'] = trim(($flightNo ?? 'Chuyến bay') . ($route ? ' ' . $route : ''));
        } elseif ($kind === 'train') {
            $f['title'] = trim('Tàu ' . ($train ?? '') . ($route ? ' ' . $route : ''));
        } elseif ($kind === 'hotel' && ($name = $this->hotelName($t))) {
            $f['title'] = $name;
        } elseif ($kind === 'bus') {
            $op = preg_match('/nhà\s*xe\s*:?\s*([\p{L}0-9 ]{2,30}?)\s*(?:\n|,|$)/iu', $t, $m) ? 'Xe ' . trim($m[1]) : ($f['provider'] ?? 'Xe khách');
            $f['title'] = trim($op . ($route ? ' ' . $route : ''));
        }
        if (empty($f['title']) && preg_match('/^(?:subject|tiêu\s*đề|chủ\s*đề)\s*:\s*(.{4,120})$/imu', $t, $m)) {
            $f['title'] = trim(preg_replace('/^(?:re|fw|fwd)\s*:\s*/i', '', $m[1]) ?? $m[1]);
        }
        if (!empty($f['title'])) {
            $f['title'] = mb_substr(trim($f['title']), 0, 120);
        }
        if (!empty($f['provider'])) {
            $f['provider'] = mb_substr($f['provider'], 0, 20);
        }

        return $f;
    }

    /** Chặng: "SGN → DLI", "SGN-DLI", "(SGN) ... (DLI)", "Sài Gòn - Nha Trang" sau chữ "từ ... đến". */
    private function route(string $t): ?string
    {
        $codes = array_keys((array) config('travel_apis.airport_names', []));
        if ($codes) {
            $c = implode('|', $codes);
            if (preg_match('/\b(' . $c . ')\b\s*(?:→|->|-|–|—|(?i:to)|đi|\s)\s*\(?\b(' . $c . ')\b/u', $t, $m) && $m[1] !== $m[2]) {
                return $m[1] . '→' . $m[2];
            }
            if (preg_match_all('/\((' . $c . ')\)/', $t, $mm) && count(array_unique($mm[1])) >= 2) {
                $u = array_values(array_unique($mm[1]));

                return $u[0] . '→' . $u[1];
            }
        }
        if (preg_match('/ga\s*đi\s*:\s*([\p{L} .]{2,30}?)(?:\s{2,}|\s+giờ|\n|$).*?ga\s*đến\s*:\s*([\p{L} .]{2,30}?)(?:\s{2,}|\s+giờ|\n|$)/isu', $t, $m)) {
            return trim($m[1]) . ' → ' . trim($m[2]);
        }
        if (preg_match('/\b(?:SE|SNT|SPT|SQN|TN|NA|SP)\d{1,2}\s+([\p{L} .]{2,30}?)\s*(?:→|->|-|–)\s*([\p{L} .]{2,30}?)\s*$/mu', $t, $m)) {
            return trim($m[1]) . ' → ' . trim($m[2]);
        }
        if (preg_match('/(?:từ|tuyến|chặng|nơi\s*đi|điểm\s*đi)\s*:?\s*([\p{L} .]{2,30}?)\s*(?:→|->|-|–|đến|tới|nơi\s*đến|điểm\s*đến)\s*:?\s*([\p{L} .]{2,30})(?:\n|,|$)/iu', $t, $m)) {
            return trim($m[1]) . ' → ' . trim($m[2]);
        }

        return null;
    }

    private function hotelName(string $t): ?string
    {
        if (preg_match('/(?:khách\s*sạn|tên\s*khách\s*sạn|chỗ\s*nghỉ|nơi\s*lưu\s*trú|property|hotel\s*name|accommodation)\s*[:\-]\s*([^\n]{3,100})/iu', $t, $m)) {
            return trim($m[1], " .\t");
        }
        if (preg_match('/^([^\n]{2,80}\b(?:Hotel|Resort|Homestay|Villa|Hostel|Boutique|Inn|Lodge)\b[^\n]{0,40})$/mu', $t, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/\b((?:Khách\s*sạn|Resort|Homestay|Villa)\s+[\p{Lu}][^\n,.]{2,60})/u', $t, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    /**
     * Mọi ngày (kèm giờ gần đó) trong văn bản, theo vị trí xuất hiện.
     * @return list<array{date:string,time:?string,pos:int}>
     */
    private function dates(string $t, int $year, ?string $hintDate): array
    {
        $out = [];
        $add = function (int $pos, int $y, int $mo, int $d, int $len) use (&$out, $t, $year, $hintDate) {
            if ($y > 0 && $y < 100) {
                $y += 2000;
            }
            if ($y === 0) {
                $y = $year;
                // không có năm: ngày đã qua so với chuyến thì là năm sau
                if ($hintDate && sprintf('%04d-%02d-%02d', $y, $mo, $d) < date('Y-m-d', strtotime($hintDate . ' -60 days'))) {
                    $y++;
                }
            }
            if (!checkdate($mo, $d, $y) || $y < 2000 || $y > 2100) {
                return;
            }
            $time = null;
            // giờ cùng dòng với ngày: trước ngày (gần nhất) hoặc ngay sau ngày
            $after = strtok(substr($t, $pos + $len, 40) . "\n", "\n");
            $nl = strrpos(substr($t, 0, $pos), "\n");
            $from = max($nl === false ? 0 : $nl + 1, $pos - 40);
            $before = substr($t, $from, $pos - $from);
            $re = '/\b([01]?\d|2[0-3])\s*[:hH]\s*([0-5]\d)\b/';
            if (preg_match_all($re, (string) $before, $tb) && $tb[0]) {
                $time = sprintf('%02d:%s', (int) end($tb[1]), end($tb[2]));
            } elseif (preg_match($re, (string) $after, $tm)) {
                $time = sprintf('%02d:%s', (int) $tm[1], $tm[2]);
            }
            $out[] = ['date' => sprintf('%04d-%02d-%02d', $y, $mo, $d), 'time' => $time, 'pos' => $pos];
        };
        // 05/12/2026 · 05-12-26 · 05.12.2026 · 05/12
        preg_match_all('/(?<![\d\/.\-])(\d{1,2})[\/.\-](\d{1,2})(?:[\/.\-](\d{2,4}))?(?![\d\/\-]|\.\d)/', $t, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $x) {
            if (!isset($x[3]) && str_contains($x[0][0], '.')) {
                continue;   // "1.250" là số tiền, không phải ngày
            }
            $add($x[0][1], isset($x[3]) ? (int) $x[3][0] : 0, (int) $x[2][0], (int) $x[1][0], strlen($x[0][0]));
        }
        // 2026-12-05
        preg_match_all('/\b(20\d{2})-(\d{2})-(\d{2})\b/', $t, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $x) {
            $add($x[0][1], (int) $x[1][0], (int) $x[2][0], (int) $x[3][0], strlen($x[0][0]));
        }
        // 5 tháng 12 năm 2026 · 05 Tháng 12, 2026
        preg_match_all('/\b(\d{1,2})\s*tháng\s*(\d{1,2})(?:\s*(?:năm|,)?\s*(20\d{2}))?/iu', $t, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $x) {
            $add($x[0][1], isset($x[3]) ? (int) $x[3][0] : 0, (int) $x[2][0], (int) $x[1][0], strlen($x[0][0]));
        }
        // 5 Dec 2026 · Dec 5, 2026 · Sat, 05 December 2026
        preg_match_all('/\b(\d{1,2})\s+(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?,?\s+(20\d{2})\b/i', $t, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $x) {
            $add($x[0][1], (int) $x[3][0], self::MONTHS_EN[strtolower($x[2][0])], (int) $x[1][0], strlen($x[0][0]));
        }
        preg_match_all('/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?\s+(\d{1,2}),?\s+(20\d{2})\b/i', $t, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $x) {
            $add($x[0][1], (int) $x[3][0], self::MONTHS_EN[strtolower($x[1][0])], (int) $x[2][0], strlen($x[0][0]));
        }
        usort($out, fn($a, $b) => $a['pos'] <=> $b['pos']);

        return $out;
    }
}
