<?php

namespace App\Services;

use App\Services\Planner\OpeningHours;
use App\Support\Geo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bộ xác minh tính khả thi của tour: không chỉ kiểm dữ liệu hợp lệ mà còn xem lịch trình có tổ chức được trong thực tế không.
 *
 * Kiểm tra được TỰ ĐỘNG (có dữ liệu để kiểm): thời lượng - số ngày lịch trình, nội dung từng ngày, giá, bao gồm / không bao gồm, lịch mở bán,
 * thứ tự và độ dày mốc giờ trong ngày, điểm tham quan có trong dữ liệu địa điểm: giờ mở cửa của ngày đi (tbl_opening_hours) và thời gian di chuyển
 * giữa hai điểm liên tiếp (đường chim bay × hệ số đường vòng ÷ tốc độ trung bình, cùng công thức bộ lập kế hoạch).
 * Những gì KHÔNG có nguồn xác minh đáng tin cậy được đánh dấu "kiểm tra thủ công" (manual) chứ không suy đoán là ổn.
 * Giới hạn: lịch trình là văn bản tự do; chỉ nhận mốc giờ dạng 08:00 / 8h30 / 8 giờ 30 và tên điểm trùng dữ liệu địa điểm của điểm đến tour.
 */
class FeasibilityService
{
    /** 08:00 hoặc 8h / 8h30 / 8 giờ 30. Dấu ":" bắt buộc có phút để không nhầm "Ngày 1:" với 01:00. */
    private const TIME_RE = '/(?<![\d:])(?:([01]?\d|2[0-3])\s*:\s*([0-5]\d)|([01]?\d|2[0-3])\s*(?:h|giờ)\s*([0-5]\d)?)(?![\d])/iu';
    private const DEFAULT_STAY_MIN = 90;
    private const MIN_TRAVEL_BUFFER = 20;

    /** @return array{verdict:string, counts:array{error:int,warn:int,info:int,manual:int}, findings:list<array>, date:?string, matchedPlaces:int, days:int} */
    public function check(int $tourId, ?string $date = null): array
    {
        $f = [];
        $add = function (?int $day, string $level, string $code, string $msg, bool $manual = false) use (&$f) {
            $f[] = ['day' => $day, 'level' => $level, 'code' => $code, 'message' => $msg, 'manual' => $manual];
        };
        $tour = DB::table('tbl_tours')->where('tourId', $tourId)->first();
        if (!$tour) {
            return ['verdict' => 'blocked', 'counts' => ['error' => 1, 'warn' => 0, 'info' => 0, 'manual' => 0], 'findings' => [['day' => null, 'level' => 'error', 'code' => 'no_tour', 'message' => 'Không tìm thấy tour.', 'manual' => false]], 'date' => null, 'matchedPlaces' => 0, 'days' => 0];
        }
        $timeline = DB::table('tbl_timeline')->where('tourId', $tourId)->orderBy('timeLineId')->get(['title', 'description']);
        $days = DepartureService::durationDays($tour);

        // --- cấu trúc
        if ($timeline->count() !== $days) {
            $add(null, 'error', 'duration_mismatch', "Tour ghi {$days} ngày nhưng lịch trình có {$timeline->count()} ngày.");
        }
        if ((float) $tour->priceAdult <= 0) {
            $add(null, 'error', 'price_zero', 'Giá người lớn bằng 0 hoặc chưa nhập.');
        }
        if ((float) $tour->priceChild > (float) $tour->priceAdult && (float) $tour->priceAdult > 0) {
            $add(null, 'warn', 'price_child', 'Giá trẻ em cao hơn giá người lớn.');
        }
        foreach (['includes' => 'Giá đã bao gồm', 'excludes' => 'Giá không bao gồm'] as $col => $label) {
            if (property_exists($tour, $col) && trim(strip_tags((string) $tour->{$col})) === '') {
                $add(null, 'warn', 'no_' . $col, "Chưa khai báo \"{$label}\": khách không biết giá gồm những gì.");
            }
        }
        $titles = [];
        foreach ($timeline as $i => $t) {
            $d = $i + 1;
            $text = $this->plain((string) $t->description);
            if (mb_strlen($text) < 20) {
                $add($d, 'error', 'day_empty', "Ngày {$d} chưa có nội dung đủ để khách hiểu (dưới 20 ký tự).");
            }
            $key = mb_strtolower(trim((string) $t->title));
            if ($key !== '' && isset($titles[$key])) {
                $add($d, 'warn', 'day_title_dup', "Ngày {$d} trùng tiêu đề với ngày {$titles[$key]}.");
            }
            $titles[$key] = $d;
        }

        // --- lịch mở bán
        $deps = DB::table('tbl_tour_availability')->where('tourId', $tourId)->where('status', 'open')->where('departureDate', '>', today()->toDateString())->orderBy('departureDate')->get();
        if ($deps->isEmpty()) {
            $add(null, 'warn', 'no_departure', 'Tour chưa có ngày khởi hành nào đang mở bán.');
        }
        foreach ($deps as $dep) {
            if ((int) $dep->minParticipants > (int) $dep->totalSlots) {
                $add(null, 'error', 'min_gt_slots', 'Ngày ' . $dep->departureDate . ': số khách tối thiểu (' . $dep->minParticipants . ') lớn hơn tổng chỗ (' . $dep->totalSlots . '), không thể đủ điều kiện khởi hành.');
            }
        }
        $date = $date ?: ($deps->first()->departureDate ?? null);
        if (!$date) {
            $add(null, 'info', 'no_date', 'Chưa có ngày khởi hành để đối chiếu giờ mở cửa theo thứ trong tuần.', true);
        }

        // --- địa điểm trong dữ liệu của điểm đến
        $places = $this->places((string) $tour->destination);
        if (!$places) {
            $add(null, 'info', 'no_places', 'Điểm đến chưa có dữ liệu địa điểm: không đối chiếu được giờ mở cửa và thời gian di chuyển.', true);
        }
        $hours = [];
        $matchedTotal = 0;

        // --- từng ngày
        foreach ($timeline as $i => $t) {
            $d = $i + 1;
            $items = $this->segments($this->plain((string) $t->description, true));
            if (count($items) === 0) {
                $add($d, 'info', 'no_times', "Ngày {$d} không có mốc giờ nên chưa kiểm tra được thứ tự, độ dày và giờ mở cửa.", true);
                continue;
            }
            $prev = null;
            foreach ($items as $k => $it) {
                if ($prev && $it['min'] < $prev['min']) {
                    $add($d, 'error', 'time_order', "Ngày {$d}: mốc " . $this->hm($it['min']) . ' đứng sau mốc ' . $this->hm($prev['min']) . ' (thứ tự giờ bị lệch).');
                } elseif ($prev && $it['min'] - $prev['min'] < 15) {
                    $add($d, 'warn', 'gap_short', "Ngày {$d}: hai mốc " . $this->hm($prev['min']) . ' và ' . $this->hm($it['min']) . ' cách nhau dưới 15 phút.');
                }
                if ($it['min'] < 300) {
                    $add($d, 'warn', 'too_early', "Ngày {$d}: có mốc " . $this->hm($it['min']) . ' (trước 05:00), cần xác nhận với nhà cung cấp và khách.');
                }
                if ($it['min'] > 1350) {
                    $add($d, 'warn', 'too_late', "Ngày {$d}: có mốc " . $this->hm($it['min']) . ' (sau 22:30).');
                }
                $prev = $it;
            }
            if (count($items) > 9) {
                $add($d, 'warn', 'too_dense', "Ngày {$d} có " . count($items) . ' mốc giờ: lịch rất dày, cần chừa thời gian dự phòng.');
            }
            if (!$places) {
                continue;
            }
            // khớp điểm tham quan -> giờ mở cửa + di chuyển
            $matched = [];
            foreach ($items as $k => $it) {
                $p = $this->match($it['text'], $places);
                if ($p) {
                    $end = $items[$k + 1]['min'] ?? ($it['min'] + self::DEFAULT_STAY_MIN);
                    $matched[] = ['k' => $k, 'start' => $it['min'], 'end' => max($end, $it['min'] + 1), 'p' => $p];
                    $matchedTotal++;
                }
            }
            if ($matched) {
                $need = array_values(array_filter(array_map(fn ($m) => ['type' => 'place', 'refId' => (int) $m['p']->placeId], $matched), fn ($x) => !isset($hours['place:' . $x['refId']])));
                if ($need && Schema::hasTable('tbl_opening_hours')) {
                    foreach ((new OpeningHours())->forItems($need) as $key => $v) {
                        $hours[$key] = $v;
                    }
                    foreach ($need as $x) {
                        $hours['place:' . $x['refId']] = $hours['place:' . $x['refId']] ?? null;
                    }
                }
            }
            $dow = $date ? OpeningHours::dowOf($date, $d) : null;
            foreach ($matched as $m) {
                $h = $hours['place:' . $m['p']->placeId] ?? null;
                if (!$h) {
                    $add($d, 'info', 'no_hours', "Ngày {$d}: chưa có nguồn giờ mở cửa của \"{$m['p']->name}\"; kiểm tra thủ công.", true);
                    continue;
                }
                $r = OpeningHours::check($h, $dow, $m['start'], min($m['end'], $m['start'] + self::DEFAULT_STAY_MIN));
                if ($r) {
                    $when = OpeningHours::describe($h, $dow);
                    $add($d, 'error', 'closed_' . $r, "Ngày {$d}: \"{$m['p']->name}\" " . ($r === 'closed' ? 'đóng cửa' : 'ngoài giờ mở cửa') . ' vào ' . $this->hm($m['start']) . ' (ngày đi ' . $date . ($when ? ', giờ mở: ' . $when : '') . ').');
                }
            }
            for ($j = 1; $j < count($matched); $j++) {
                $a = $matched[$j - 1]['p'];
                $b = $matched[$j]['p'];
                if ($a->placeId === $b->placeId) {
                    continue;
                }
                $km = Geo::distanceKm((float) $a->latitude, (float) $a->longitude, (float) $b->latitude, (float) $b->longitude) * 1.3;
                if ($km < 0.5) {
                    continue;
                }
                $min = (int) round($km / 40 * 60);
                $gap = $matched[$j]['start'] - $matched[$j - 1]['start'];
                if ($gap < $min + self::MIN_TRAVEL_BUFFER) {
                    $add($d, 'warn', 'travel', "Ngày {$d}: từ \"{$a->name}\" đến \"{$b->name}\" cần khoảng {$min} phút (~" . round($km, 1) . " km) nhưng hai mốc chỉ cách {$gap} phút.");
                }
            }
        }
        $counts = ['error' => 0, 'warn' => 0, 'info' => 0, 'manual' => 0];
        foreach ($f as $x) {
            $counts[$x['level']]++;
            $counts['manual'] += $x['manual'] ? 1 : 0;
        }
        $verdict = $counts['error'] > 0 ? 'blocked' : ($counts['warn'] > 0 ? 'review' : 'ok');

        return ['verdict' => $verdict, 'counts' => $counts, 'findings' => $f, 'date' => $date, 'matchedPlaces' => $matchedTotal, 'days' => $days];
    }

    /** Văn bản thuần; $lines = true giữ ngắt dòng để tách mốc giờ. */
    private function plain(string $html, bool $lines = false): string
    {
        $s = preg_replace('/<\s*(br|\/p|\/li|\/div|\/h[1-6])[^>]*>/i', "\n", $html);
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $lines ? trim($s) : trim(preg_replace('/\s+/u', ' ', $s));
    }

    /** @return list<array{min:int,text:string}> mỗi mốc giờ cùng đoạn văn bản đến mốc kế tiếp */
    private function segments(string $text): array
    {
        if (!preg_match_all(self::TIME_RE, $text, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return [];
        }
        // bỏ cách nói về THỜI LƯỢNG ("khoảng 2h", "mất 3 giờ") để không nhầm với mốc giờ trong ngày
        $m = array_values(array_filter($m, function ($x) use ($text) {
            $colon = ($x[1][0] ?? '') !== '';
            $before = mb_strtolower(substr($text, max(0, $x[0][1] - 14), min(14, $x[0][1])));

            return $colon || !preg_match('/(khoảng|mất|tầm|trong|hơn|gần|chừng|ước|sau)\s*$/u', $before);
        }));
        $out = [];
        $n = count($m);
        foreach ($m as $i => $x) {
            $start = $x[0][1] + strlen($x[0][0]);
            $end = $i + 1 < $n ? $m[$i + 1][0][1] : strlen($text);
            $colon = ($x[1][0] ?? '') !== '';
            $h = (int) ($colon ? $x[1][0] : $x[3][0]);
            $mi = $colon ? (int) $x[2][0] : (int) (($x[4][0] ?? '') !== '' ? $x[4][0] : 0);
            $out[] = ['min' => $h * 60 + $mi, 'text' => substr($text, $start, max(0, $end - $start))];
        }

        return $out;
    }

    private function hm(int $min): string
    {
        return sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
    }

    /** Địa điểm của điểm đến (tên đủ dài để khớp đáng tin). @return list<object> */
    private function places(string $destination): array
    {
        if (!Schema::hasTable('tbl_external_places') || !Schema::hasTable('tbl_destinations')) {
            return [];
        }
        $destId = DB::table('tbl_destinations')->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($destination))])->value('destinationId');
        if (!$destId) {
            return [];
        }

        return DB::table('tbl_external_places')->where('destinationId', $destId)->orderByDesc('rank')->limit(800)->get(['placeId', 'name', 'latitude', 'longitude'])
            ->filter(fn ($p) => mb_strlen(trim($p->name)) >= 5)->map(function ($p) {
                $p->norm = $this->norm($p->name);

                return $p;
            })->values()->all();
    }

    private function norm(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($s))));
    }

    /** Điểm có tên dài nhất xuất hiện trong đoạn văn bản. */
    private function match(string $text, array $places): ?object
    {
        $t = ' ' . $this->norm($text) . ' ';
        $best = null;
        foreach ($places as $p) {
            if ($p->norm !== '' && str_contains($t, ' ' . $p->norm . ' ') && (!$best || mb_strlen($p->norm) > mb_strlen($best->norm))) {
                $best = $p;
            }
        }

        return $best;
    }
}
