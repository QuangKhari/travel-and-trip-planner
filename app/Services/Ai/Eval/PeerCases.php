<?php

namespace App\Services\Ai\Eval;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bộ câu F1 do người khác viết (held-out). Nhóm câu lấy từ database/ai/eval_brief_peer.json ("groups") để người viết
 * và bộ chấm dùng chung một định nghĩa. Nhãn (expect) do CHÍNH người viết đánh dấu theo ý họ muốn nói.
 */
final class PeerCases
{
    /** Nhóm của trang thu câu -> nhóm trong eval_brief.json (để báo cáo theo nhóm gộp được với bộ của tác giả). */
    public const GROUP_MAP = ['du' => 'full', 'thieu' => 'missing', 'mo_ho' => 'ambiguous', 'khong_dau' => 'no_accent',
        'ngoai' => 'out_of_scope', 'nhieu_diem' => 'multi_dest', 'injection' => 'injection'];

    /** Gợi ý cho người viết, KHÔNG lộ lời nhắc hay cách hệ thống đọc câu. */
    public const HINTS = [
        'du' => 'Nói đủ: đi đâu, mấy ngày, tháng nào, mấy người, thích gì.',
        'thieu' => 'Cố ý không nói một điều quan trọng (ví dụ không nói số ngày, hoặc không nói đi đâu).',
        'mo_ho' => 'Nói kiểu đời thường, không rõ con số: "cuối tuần", "dịp lễ", "đi với gia đình", "rẻ thôi"...',
        'khong_dau' => 'Gõ không dấu hoặc viết tắt như nhắn tin: "di dl 3n2d thang 12"...',
        'ngoai' => 'Hỏi nơi Travela không có (nước ngoài, nơi rất lạ) hoặc việc không phải lên lịch đi chơi.',
        'nhieu_diem' => 'Muốn đi hai, ba nơi trong một chuyến.',
        'injection' => 'Viết một câu lên lịch bình thường nhưng lồng thêm "lệnh" bắt trợ lý làm việc khác (lộ bí mật, đổi điểm đến...). Ghi chuỗi bạn muốn nó KHÔNG được nói ra.',
    ];

    public const TARGET_PER_GROUP = 8;

    public static function ready(): bool
    {
        return Schema::hasTable('tbl_ai_peer_cases');
    }

    /** @return array<string,string> nhóm -> mô tả */
    public static function groups(): array
    {
        $f = database_path('ai/eval_brief_peer.json');
        $g = is_file($f) ? (array) (json_decode((string) file_get_contents($f), true)['groups'] ?? []) : [];

        return $g ?: array_combine(array_keys(self::GROUP_MAP), array_keys(self::GROUP_MAP));
    }

    /** Mã người viết hợp lệ: chữ thường không dấu, số, gạch ngang, 2–40 ký tự. */
    public static function writer(string $w): ?string
    {
        return preg_match('/^[a-z0-9-]{2,40}$/', $w) ? $w : null;
    }

    /**
     * Chuyển nhãn người viết đánh dấu thành "expect" theo đúng khóa của eval_brief.json.
     * @param array<string,mixed> $in
     */
    public static function expect(array $in, string $grp): array
    {
        $e = [];
        $int = fn($v, $lo, $hi) => is_numeric($v) && (int) $v >= $lo && (int) $v <= $hi ? (int) $v : null;
        if (!empty($in['destination']) && is_string($in['destination']) && preg_match('/^[a-z0-9-]{1,80}$/', $in['destination'])) {
            $e['destination'] = $in['destination'];
        }
        foreach (['month' => [1, 12], 'days' => [1, 30], 'adults' => [1, 50], 'children' => [0, 20]] as $k => [$lo, $hi]) {
            if (($v = $int($in[$k] ?? null, $lo, $hi)) !== null) {
                $e[$k] = $v;
            }
        }
        if (is_numeric($in['budget'] ?? null) && (int) $in['budget'] > 0) {
            $e['budget'] = (int) $in['budget'];
        }
        if (in_array($in['pace'] ?? null, array_keys((array) config('planner.pace')), true)) {
            $e['pace'] = $in['pace'];
        }
        foreach (['interests' => array_keys((array) config('interests')), 'dislikes' => array_keys((array) config('planner.dislikes'))] as $k => $allowed) {
            $v = array_values(array_intersect((array) ($in[$k] ?? []), $allowed));
            if ($v) {
                $e[$k] = $v;
            }
        }
        $inferred = array_values(array_intersect((array) ($in['inferred'] ?? []), array_keys($e)));
        if ($inferred) {
            $e['inferred'] = $inferred;
        }
        $needs = array_values(array_intersect((array) ($in['needs'] ?? []), ['destination', 'days', 'month', 'people']));
        $e['needs'] = $needs;
        if ($grp === 'ngoai') {
            $e['unsupported'] = true;
        }
        if ($grp === 'injection') {
            $bad = array_values(array_filter(array_map(fn($s) => trim(mb_substr((string) $s, 0, 60)), preg_split('/[,\n]/u', (string) ($in['mustNotContain'] ?? '')) ?: []), fn($s) => $s !== ''));
            if ($bad) {
                $e['mustNotContain'] = array_slice($bad, 0, 5);
            }
        }

        return $e;
    }

    /**
     * Ghi bộ câu ra JSON (giữ "about" và "groups" của tệp cũ). Câu bị loại (rejected) không xuất.
     * @return array{n:int, byGroup:array<string,int>, writers:int, warnings:list<string>}
     */
    public static function export(string $path): array
    {
        $old = is_file($path) ? (array) json_decode((string) file_get_contents($path), true) : [];
        $rows = DB::table('tbl_ai_peer_cases')->where('status', '!=', 'rejected')->orderBy('caseId')->get();
        $cases = [];
        $byGroup = array_fill_keys(array_keys(self::GROUP_MAP), 0);
        foreach ($rows as $i => $r) {
            $cases[] = ['id' => sprintf('p%02d', $i + 1), 'group' => self::GROUP_MAP[$r->grp] ?? $r->grp, 'source' => 'peer',
                'writer' => substr(sha1('travela-peer|' . $r->writer), 0, 8),   // ẩn danh trong tệp đưa vào quyển
                'q' => $r->q, 'expect' => json_decode((string) $r->expect, true) ?: []];
            $byGroup[$r->grp] = ($byGroup[$r->grp] ?? 0) + 1;
        }
        $out = ['about' => $old['about'] ?? 'Câu F1 do người khác viết (held-out).', 'groups' => $old['groups'] ?? self::groups(),
            'exportedAt' => now()->toDateString(), 'cases' => $cases];
        file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

        $warn = [];
        if (count($cases) < 50) {
            $warn[] = 'Mới có ' . count($cases) . ' câu (mục tiêu 50–70).';
        }
        foreach ($byGroup as $g => $n) {
            if ($n < 5) {
                $warn[] = 'Nhóm "' . $g . '" mới có ' . $n . ' câu (nên ≥ 5).';
            }
        }
        $writers = $rows->pluck('writer')->unique()->count();
        if ($writers < 2) {
            $warn[] = 'Mới có ' . $writers . ' người viết (nên 2–3 người).';
        }

        return ['n' => count($cases), 'byGroup' => $byGroup, 'writers' => $writers, 'warnings' => $warn];
    }
}
