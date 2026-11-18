<?php

namespace App\Console\Commands;

use App\Services\Ai\Eval\PeerCases;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Bộ câu F1 do người khác viết (held-out):
 *   php artisan ai:peer link lan --days=30      in đường dẫn có chữ ký gửi cho người viết "lan"
 *   php artisan ai:peer list                    số câu theo người viết × nhóm
 *   php artisan ai:peer reject 12               loại câu trùng / không đọc được (KHÔNG sửa nhãn của người viết)
 *   php artisan ai:peer export                  ghi database/ai/eval_brief_peer.json để chạy ai:eval --file=...
 */
class AiPeer extends Command
{
    protected $signature = 'ai:peer {action : link | list | reject | export} {arg? : tên người viết (link) hoặc mã câu (reject)} {--days=30} {--out=database/ai/eval_brief_peer.json}';
    protected $description = 'Thu và xuất bộ câu đánh giá F1 do người khác viết';

    public function handle(): int
    {
        if (!PeerCases::ready()) {
            $this->error('Chưa có bảng tbl_ai_peer_cases. Chạy php artisan migrate.');

            return self::FAILURE;
        }

        return match ((string) $this->argument('action')) {
            'link' => $this->link(),
            'list' => $this->listing(),
            'reject' => $this->reject(),
            'export' => $this->export(),
            default => $this->fail2('action phải là link | list | reject | export'),
        };
    }

    private function link(): int
    {
        $w = PeerCases::writer((string) $this->argument('arg'));
        if (!$w) {
            return $this->fail2('Tên người viết: chữ thường không dấu, số, gạch ngang (vd lan, minh-2).');
        }
        $this->line(URL::temporarySignedRoute('peer.eval', now()->addDays(max(1, (int) $this->option('days'))), ['writer' => $w]));

        return self::SUCCESS;
    }

    private function listing(): int
    {
        $rows = DB::table('tbl_ai_peer_cases')->selectRaw('writer, grp, status, COUNT(*) n')->groupBy('writer', 'grp', 'status')->orderBy('writer')->get();
        $this->table(['Người viết', 'Nhóm', 'Trạng thái', 'Số câu'], $rows->map(fn($r) => [$r->writer, $r->grp, $r->status, $r->n])->all());

        return self::SUCCESS;
    }

    private function reject(): int
    {
        $n = DB::table('tbl_ai_peer_cases')->where('caseId', (int) $this->argument('arg'))->update(['status' => 'rejected']);

        return $n ? self::SUCCESS : $this->fail2('Không có câu mã ' . $this->argument('arg'));
    }

    private function export(): int
    {
        $r = PeerCases::export(base_path((string) $this->option('out')));
        $this->info('Đã ghi ' . $r['n'] . ' câu của ' . $r['writers'] . ' người viết vào ' . $this->option('out'));
        $this->table(['Nhóm', 'Số câu'], array_map(fn($g, $n) => [$g, $n], array_keys($r['byGroup']), $r['byGroup']));
        foreach ($r['warnings'] as $w) {
            $this->warn($w);
        }
        $this->line('Chạy: php artisan ai:eval --suite=brief --engine=llm --file=' . $this->option('out') . ' (3 lần) và --engine=rules để có mốc.');

        return self::SUCCESS;
    }

    private function fail2(string $m): int
    {
        $this->error($m);

        return self::FAILURE;
    }
}
