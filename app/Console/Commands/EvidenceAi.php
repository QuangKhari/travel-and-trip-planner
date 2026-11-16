<?php

namespace App\Console\Commands;

use App\Services\Planner\Reco\RecoEval;
use App\Services\Planner\Reco\RecoFeatures;
use App\Services\Planner\Reco\RecoLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Đợt F: số liệu AI cho chương thực nghiệm, lấy từ dữ liệu người dùng thật (không dùng dữ liệu giả lập).
 *   php artisan evidence:ai --since=2026-11-20 --md=docs/so-lieu/ai-thuc-te.md
 *
 *  L7  Cá nhân hóa đo ngoại tuyến: tách theo thời gian, AUC / NDCG@5 / Hit@5, chênh NDCG có KTC bootstrap.
 *  L8  Thử xen kẽ (RECO_MODE=interleave): số danh sách mô hình thắng / luật thắng, kiểm định dấu.
 *  AP5 La bàn: tỉ lệ đề xuất được áp dụng; cảnh báo theo dõi chuyến -> "đã ổn" (tỉ lệ, thời gian trung vị).
 *  AP2 Lời giải thích: bao nhiêu lần dùng lời AI, bao nhiêu lần chốt chặn thay bằng mẫu, vì sao.
 * Mục nào chưa đủ dữ liệu thì ghi rõ "chưa đủ", không suy ra.
 */
class EvidenceAi extends Command
{
    protected $signature = 'evidence:ai {--since= : chỉ tính từ ngày (Y-m-d), vd ngày bắt đầu buổi thử} {--md= : ghi Markdown}';

    protected $description = 'Tổng hợp số liệu AI từ dữ liệu thật: cá nhân hóa, thử xen kẽ, La bàn, lời giải thích';

    public function handle(): int
    {
        $since = $this->option('since') ?: null;
        $md = ['# Số liệu AI từ dữ liệu thật', '', 'Tạo bởi `php artisan evidence:ai' . ($since ? " --since=$since" : '') . '` lúc ' . now()->format('H:i d/m/Y') . '.', ''];
        $md = array_merge($md, $this->reco($since), $this->interleave($since), $this->laban($since), $this->explain($since));
        foreach ($md as $l) {
            $this->line($l);
        }
        if ($p = $this->option('md')) {
            @mkdir(dirname(base_path($p)), 0775, true);
            file_put_contents(base_path($p), implode("\n", $md) . "\n");
            $this->info("Đã ghi $p");
        }

        return self::SUCCESS;
    }

    private function reco(?string $since): array
    {
        $out = ['## L7 — Cá nhân hóa, đo ngoại tuyến', ''];
        $lab = RecoLog::labelled($since);
        $pos = count(array_filter($lab, fn($r) => $r['label'] === 1));
        $users = count(array_unique(array_column($lab, 'userId')));
        if (count($lab) < 200 || $pos < 20) {
            return array_merge($out, [sprintf('Chưa đủ dữ liệu: %d lượt hiện có nhãn (%d lượt được chọn) từ %d người. Cần ít nhất 200 lượt và 20 lượt được chọn để tách học / kiểm có ý nghĩa.', count($lab), $pos, $users), '']);
        }
        $f = (new RecoFeatures())->load(array_map(fn($r) => $r['refType'] . ':' . $r['refId'], $lab));
        $r = RecoEval::run($lab, $f);
        $names = ['pop' => 'Độ nổi bật', 'rules' => 'Sở thích theo luật (trước mức 5)', 'global' => 'Mô hình chung', 'model' => 'Mô hình + riêng từng người (mức 5)'];
        $out[] = sprintf('%d lượt hiện, %d người; học trên %d lượt cũ, kiểm trên %d lượt mới (%d danh sách có ít nhất một nơi được chọn).', $r['n'], $users, $r['train'], $r['test'], $r['lists']);
        $out[] = '';
        $out[] = '| Cách xếp | AUC | NDCG@5 | Hit@5 |';
        $out[] = '|---|---|---|---|';
        foreach ($names as $k => $n) {
            $m = $r['methods'][$k];
            $out[] = sprintf('| %s | %.3f | %.3f | %.3f |', $n, $m['auc'], $m['ndcg5'], $m['hit5']);
        }
        $d = $r['diff'];
        $out[] = '';
        $out[] = sprintf('Chênh NDCG@5 (mức 5 − luật): %+.3f, KTC 95%% [%+.3f; %+.3f] — %s.', $d['mean'], $d['lo'], $d['hi'],
            $d['lo'] > 0 ? 'tốt hơn, có ý nghĩa' : ($d['hi'] < 0 ? 'kém hơn, có ý nghĩa' : 'chưa thấy khác biệt có ý nghĩa'));
        $out[] = '';

        return $out;
    }

    private function interleave(?string $since): array
    {
        $out = ['## L8 — Thử xen kẽ (team-draft interleaving)', ''];
        $lab = array_filter(RecoLog::labelled($since), fn($r) => in_array($r['policy'], ['il-A', 'il-B'], true));
        $lists = [];
        foreach ($lab as $r) {
            $lists[$r['listId']][$r['policy']] = ($lists[$r['listId']][$r['policy']] ?? 0) + $r['label'];
        }
        $a = $b = $tie = 0;
        foreach ($lists as $l) {
            [$x, $y] = [$l['il-A'] ?? 0, $l['il-B'] ?? 0];
            if ($x || $y) {
                $x > $y ? $a++ : ($y > $x ? $b++ : $tie++);
            }
        }
        $n = $a + $b;
        if (!$lists) {
            return array_merge($out, ['Chưa có dữ liệu: bật RECO_MODE=interleave trong vòng thử 2 (sau khi đã có mô hình từ vòng 1).', '']);
        }
        $p = $n ? RecoInterleaveReport::signTest(max($a, $b), $n) : 1.0;
        $out[] = '| Danh sách đã hiện | Có lượt chọn | Luật thắng | Mô hình thắng | Hòa | Mô hình thắng (%) | p (kiểm định dấu) |';
        $out[] = '|---|---|---|---|---|---|---|';
        $out[] = sprintf('| %d | %d | %d | %d | %d | %s | %s |', count($lists), $n + $tie, $a, $b, $tie, $n ? round(100 * $b / $n, 1) . '%' : '–', $n ? number_format($p, 4, ',', '') : '–');
        $out[] = '';
        $out[] = $n < 30 ? 'Dưới 30 danh sách có thắng thua: chỉ mang tính định hướng.' : ($p < 0.05 ? ($b > $a ? 'Mô hình được chọn nhiều hơn luật, có ý nghĩa (p < 0,05).' : 'Luật được chọn nhiều hơn mô hình, có ý nghĩa (p < 0,05).') : 'Chưa khác biệt có ý nghĩa.');
        $out[] = '';

        return $out;
    }

    private function laban(?string $since): array
    {
        $out = ['## AP5 — La bàn: đề xuất được áp dụng, cảnh báo được xử lý', ''];
        if (Schema::hasTable('tbl_ai_runs')) {
            $q = DB::table('tbl_ai_runs')->whereIn('outcome', ['applied', 'skipped']);
            if ($since) {
                $q->where('createdAt', '>=', $since);
            }
            $rows = $q->select('feature', DB::raw("sum(outcome = 'applied') a"), DB::raw("sum(outcome = 'skipped') s"))->groupBy('feature')->get();
            if ($rows->isEmpty()) {
                $out[] = 'Chưa có đề xuất nào được người dùng áp dụng hoặc bỏ qua.';
            } else {
                $out[] = '| Tính năng | Áp dụng | Bỏ qua | Tỉ lệ áp dụng |';
                $out[] = '|---|---|---|---|';
                foreach ($rows as $r) {
                    $out[] = sprintf('| %s | %d | %d | %s%% |', $r->feature, $r->a, $r->s, round(100 * $r->a / max(1, $r->a + $r->s)));
                }
            }
            $out[] = '';
        }
        // cảnh báo theo dõi chuyến: ref = "<id cảnh báo>:<hash>:<ngày>", "đã ổn" = "<id cảnh báo>:<giờ>"
        $ev = DB::table('tbl_trip_events')->whereIn('kind', ['alert', 'resolved'])->when($since, fn($q) => $q->where('createdAt', '>=', $since))
            ->orderBy('createdAt')->get(['tripId', 'kind', 'ref', 'createdAt']);
        $open = [];
        $mins = [];
        $total = 0;
        foreach ($ev as $e) {
            $id = $e->kind === 'alert' ? implode(':', array_slice(explode(':', (string) $e->ref), 0, -2)) : implode(':', array_slice(explode(':', (string) $e->ref), 0, -1));
            $key = $e->tripId . '|' . $id;
            if ($e->kind === 'alert') {
                $open[$key] ??= strtotime($e->createdAt);
                $total++;
            } elseif (isset($open[$key])) {
                $mins[] = (strtotime($e->createdAt) - $open[$key]) / 60;
                unset($open[$key]);
            }
        }
        if (!$total) {
            $out[] = 'Theo dõi chuyến: chưa phát hiện cảnh báo nào trong khoảng này.';
        } else {
            sort($mins);
            $out[] = sprintf('Theo dõi chuyến: %d cảnh báo được phát hiện, %d đã được xử lý (%d%%), thời gian từ lúc phát hiện tới khi hết cảnh báo: trung vị %s.',
                $total, count($mins), round(100 * count($mins) / $total), $mins ? self::dur(StudyReport::quartiles($mins)[1]) : '–');
            $out[] = 'Lưu ý: "đã xử lý" là cảnh báo biến mất ở lần kiểm tra sau (người dùng sửa lịch hoặc tình huống tự hết, ví dụ dự báo đổi), không phân biệt được hai trường hợp.';
        }
        $out[] = '';

        return $out;
    }

    private function explain(?string $since): array
    {
        $out = ['## AP2 — Lời giải thích cảnh báo (chốt chặn bịa)', ''];
        if (!Schema::hasTable('tbl_ai_runs')) {
            return array_merge($out, ['Chưa có bảng nhật ký AI.', '']);
        }
        $rows = DB::table('tbl_ai_runs')->where('feature', 'explain')->where('guardFlags', 'like', '%alert%')
            ->when($since, fn($q) => $q->where('createdAt', '>=', $since))->get(['engine', 'outcome', 'guardFlags']);
        if ($rows->isEmpty()) {
            return array_merge($out, ['Chưa có lượt giải thích cảnh báo nào.', '']);
        }
        $llm = $rows->where('engine', 'llm')->count();
        $guard = $rows->where('outcome', 'guard_replaced')->count();
        $other = $rows->count() - $llm - $guard;
        $why = [];
        foreach ($rows->where('outcome', 'guard_replaced') as $r) {
            foreach ((array) json_decode((string) $r->guardFlags, true) as $f) {
                if ($f !== 'alert') {
                    $k = explode(':', (string) $f)[0];
                    $why[$k] = ($why[$k] ?? 0) + 1;
                }
            }
        }
        $label = ['number' => 'con số không có trong dữ kiện', 'option' => 'nhắc cách sửa không có trong danh sách', 'long' => 'quá 2 câu', 'empty' => 'trả về rỗng'];
        $out[] = sprintf('%d lượt: %d dùng lời AI đã qua kiểm tra, %d bị chốt chặn thay bằng mẫu, %d dùng mẫu vì AI tắt / lỗi / hết lượt.', $rows->count(), $llm, $guard, $other);
        if ($why) {
            $out[] = 'Lý do chốt chặn: ' . implode('; ', array_map(fn($k, $n) => ($label[$k] ?? $k) . ' ' . $n, array_keys($why), $why)) . '.';
        }
        $out[] = 'Lời bị chặn không tới người dùng, nên số "bịa lọt" chỉ đo được bằng chấm tay các lời đã qua (xem ai:grade).';
        $out[] = '';

        return $out;
    }

    private static function dur(float $min): string
    {
        return $min >= 1440 ? number_format($min / 1440, 1, ',', '') . ' ngày' : ($min >= 60 ? number_format($min / 60, 1, ',', '') . ' giờ' : (int) round($min) . ' phút');
    }
}
