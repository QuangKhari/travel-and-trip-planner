<?php

namespace App\Console\Commands;

use App\Services\Planner\OpeningHours;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Nhập / xem / xóa giờ mở cửa cho địa điểm hoặc trải nghiệm (cú pháp opening_hours của OpenStreetMap).
 *   php artisan planner:hours place "Dân tộc học"                       tìm theo tên -> in mã (placeId) và giờ hiện có
 *   php artisan planner:hours place 12 "Tu-Su 08:30-17:30; Mo off"     ghi giờ cho mã 12
 *   php artisan planner:hours place 12 --show                           xem giờ
 *   php artisan planner:hours place 12 --clear                          xóa giờ (về "chưa biết")
 */
class PlannerHours extends Command
{
    protected $signature = 'planner:hours {type : place | experience} {id : mã (placeId / experienceId) hoặc một phần tên để tìm} {hours? : chuỗi kiểu OSM, ví dụ "Mo-Fr 08:00-17:00; Sa,Su off"} {--show : chỉ xem} {--clear : xóa giờ mở cửa của mục}';

    protected $description = 'Nhập / xem / xóa giờ mở cửa của một địa điểm hoặc trải nghiệm (dùng cho kiểm tra giờ mở cửa của Planner)';

    private const LABEL = [1 => 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy', 'Chủ nhật'];

    /** Chữ thường, bỏ dấu tiếng Việt (đ -> d) để tìm theo tên. */
    private static function fold(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = str_replace(['đ', 'Đ'], 'd', $s);

        return trim(preg_replace('/\s+/', ' ', \Illuminate\Support\Str::ascii($s)));
    }

    public function handle(OpeningHours $hours): int
    {
        $type = (string) $this->argument('type');
        if (!in_array($type, OpeningHours::TYPES, true)) {
            $this->error('type phải là place hoặc experience.');

            return self::FAILURE;
        }
        [$table, $pk] = $type === 'place' ? ['tbl_external_places', 'placeId'] : ['tbl_experiences', 'experienceId'];
        $arg = trim((string) $this->argument('id'));

        // không phải số: tìm theo tên để lấy đúng mã (mã khác nhau giữa các máy / CSDL)
        if (!ctype_digit($arg)) {
            if (!mb_check_encoding($arg, 'UTF-8') || str_contains($arg, '?') || str_contains($arg, "\u{FFFD}")) {
                $this->warn('Chữ gõ vào có vẻ đã bị hỏng dấu (PowerShell/cmd trên Windows). Hãy gõ không dấu, ví dụ: "bao tang", "dan toc".');
            }
            // tìm KHÔNG phân biệt dấu / hoa thường: so sánh bản không dấu ở PHP (không phụ thuộc collation của CSDL hay bảng mã của terminal)
            $needle = self::fold($arg);
            $rows = DB::table($table)->orderBy('name')->get([$pk, 'name'])
                ->filter(fn($r) => $needle !== '' && str_contains(self::fold((string) $r->name), $needle))->take(20)->values();
            if ($rows->isEmpty()) {
                $this->warn("Không có $type nào tên chứa “{$arg}”. Thử một phần tên ngắn hơn, không dấu (ví dụ: \"bao tang\").");
                $sample = DB::table($table)->orderBy($pk)->limit(10)->get([$pk, 'name']);
                if ($sample->isNotEmpty()) {
                    $this->line('Một vài ' . $type . ' đang có trong CSDL:');
                    $this->table(['Mã', 'Tên'], $sample->map(fn($r) => [$r->{$pk}, $r->name])->all());
                }

                return self::FAILURE;
            }
            $have = $hours->forItems($rows->map(fn($r) => ['type' => $type, 'refId' => (int) $r->{$pk}])->all());
            $this->table(['Mã', 'Tên', 'Giờ mở cửa'], $rows->map(fn($r) => [
                $r->{$pk},
                $r->name,
                isset($have["$type:" . $r->{$pk}]) ? 'đã có' : 'chưa có'
            ])->all());
            $this->line('Dùng mã ở cột đầu: php artisan planner:hours ' . $type . ' <mã> "Mo-Su 08:00-17:00"');

            return self::SUCCESS;
        }

        $id = (int) $arg;
        $name = DB::table($table)->where($pk, $id)->value('name');
        if (!$name) {
            $this->error("Không tìm thấy $type #$id.");

            return self::FAILURE;
        }
        if ($this->option('clear')) {
            $n = DB::table('tbl_opening_hours')->where('refType', $type)->where('refId', $id)->delete();
            $this->info("Đã xóa $n dòng giờ mở cửa của “{$name}” (#$id).");

            return self::SUCCESS;
        }
        if (!$this->option('show')) {
            $raw = (string) $this->argument('hours');
            if (!OpeningHours::parseOsm($raw)) {
                $this->error('Chưa đọc được giờ. Ví dụ: "Mo-Fr 08:00-17:00; Sa,Su 09:00-18:00" hoặc "24/7".');

                return self::FAILURE;
            }
            $n = $hours->store($type, $id, $raw);
            $this->info("Đã lưu $n dòng giờ mở cửa cho “{$name}” (#$id).");
        } else {
            $this->info("“{$name}” (#$id)");
        }
        $h = $hours->forItems([['type' => $type, 'refId' => $id]])["$type:$id"] ?? [];
        foreach (self::LABEL as $d => $l) {
            $this->line(mb_str_pad($l, 10) . ' ' . (OpeningHours::describe($h, $d) ?? 'chưa biết'));
        }

        return self::SUCCESS;
    }
}
