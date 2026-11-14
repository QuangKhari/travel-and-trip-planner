<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Gom câu hỏi THẬT người dùng đã gửi La bàn (tbl_ai_runs.inputText) thành bộ mẫu nháp để người làm đồ án gắn nhãn.
 * Ẩn danh: bỏ email, số điện thoại, dãy số dài; không lấy userId / tripId. Câu trùng (sau khi chuẩn hóa) chỉ lấy một.
 * Mẫu ra có "source": "harvest" và "expect": {"kind": "?"}: phải sửa "?" thành proposal / reply / tripedit / any trước khi chạy ai:eval.
 *   php artisan ai:eval-harvest --since=2026-11-01 --limit=40 --out=database/ai/eval_copilot_harvest.json
 */
class AiEvalHarvest extends Command
{
    protected $signature = 'ai:eval-harvest {--feature=copilot} {--since= : chỉ lấy từ ngày này (Y-m-d)} {--limit=40} {--out=database/ai/eval_copilot_harvest.json}';

    protected $description = 'Gom câu hỏi thật (đã ẩn danh) gửi La bàn thành bộ mẫu nháp để gắn nhãn';

    public static function scrub(string $s): string
    {
        $s = preg_replace('/[\w.+-]+@[\w-]+\.[\w.]+/u', '[email]', $s);
        $s = preg_replace('/(?:\+?84|0)(?:[\s.-]?\d){8,10}/u', '[sđt]', $s);
        $s = preg_replace('/\d{7,}/u', '[số]', $s);

        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    public function handle(): int
    {
        $q = DB::table('tbl_ai_runs')->where('feature', $this->option('feature'))->whereNotNull('inputText')->orderByDesc('runId');
        if ($this->option('since')) {
            $q->where('createdAt', '>=', $this->option('since'));
        }
        $seen = [];
        $cases = [];
        foreach ($q->limit(2000)->get(['inputText', 'outcome']) as $r) {
            $t = self::scrub((string) $r->inputText);
            $k = mb_strtolower(preg_replace('/[^\p{L}\p{N} ]/u', '', $t));
            if (mb_strlen($t) < 4 || isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $cases[] = ['id' => sprintf('h%02d', count($cases) + 1), 'group' => 'that', 'source' => 'harvest',
                'trip' => ['slug' => 'ha-noi', 'days' => 2, 'startDate' => '2026-12-08', 'pace' => 'normal', 'interests' => ['culture', 'food']],
                'request' => $t, 'expect' => ['kind' => '?'], 'note' => 'Lúc chạy thật: ' . $r->outcome];
            if (count($cases) >= (int) $this->option('limit')) {
                break;
            }
        }
        if (!$cases) {
            $this->warn('Không có câu nào. Câu hỏi chỉ được lưu khi bật ghi nội dung (AI_LOG_TEXT=true trong .env); bật lên rồi cho người dùng thử một thời gian.');
        }
        $path = base_path($this->option('out'));
        file_put_contents($path, json_encode(['about' => 'Câu hỏi thật đã ẩn danh. Sửa trip cho khớp câu hỏi và expect.kind trước khi chạy ai:eval.', 'cases' => $cases], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->info(count($cases) . ' câu → ' . $this->option('out'));

        return self::SUCCESS;
    }
}
