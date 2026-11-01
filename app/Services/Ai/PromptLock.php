<?php

namespace App\Services\Ai;

use App\Services\Ai\Brief\BriefPrompt;
use App\Services\Ai\Copilot\CopilotPrompt;
use App\Services\Ai\Explain\ExplainPrompt;

/**
 * Đóng băng lời nhắc (AI-7). Mỗi tính năng có "dấu vân tay" = sha256 của lời nhắc hệ thống + mẫu lời nhắc người dùng + schema,
 * dựng với dữ liệu mẫu CỐ ĐỊNH. Khóa lưu ở database/ai/prompt_lock.json kèm prompt_version.
 * PromptLockTest báo lỗi nếu lời nhắc đổi mà prompt_version KHÔNG đổi: số liệu đánh giá luôn gắn đúng một phiên bản lời nhắc.
 *   php artisan ai:prompts            xem trạng thái
 *   php artisan ai:prompts --lock     ghi khóa mới (sau khi tăng prompt_version trong config/ai.php)
 */
final class PromptLock
{
    public const PATH = 'ai/prompt_lock.json';

    /** @return array<string,string> feature => sha256 */
    public static function fingerprints(): array
    {
        $dests = [['name' => 'Hà Nội'], ['name' => 'Đà Nẵng']];

        return [
            'brief' => self::hash(BriefPrompt::system($dests, '2026-11-01'), BriefPrompt::user('mẫu'), json_encode(BriefPrompt::schema(14), JSON_UNESCAPED_UNICODE)),
            'explain' => self::hash(ExplainPrompt::system(), ExplainPrompt::user('mẫu'), json_encode(ExplainPrompt::schema(), JSON_UNESCAPED_UNICODE)),
            'copilot' => self::hash(CopilotPrompt::system(3), CopilotPrompt::user('mẫu', [['role' => 'user', 'text' => 'a']], 'mẫu', ['i1'], ['b'], 2), json_encode(CopilotPrompt::schema(), JSON_UNESCAPED_UNICODE)),
        ];
    }

    /** @return array<string,array{version:string,hash:string}> */
    public static function stored(): array
    {
        $p = database_path(self::PATH);

        return is_file($p) ? (array) (json_decode((string) file_get_contents($p), true)['features'] ?? []) : [];
    }

    /** @return array<string,array{version:string,hash:string,lockedVersion:?string,lockedHash:?string,state:string}> */
    public static function status(): array
    {
        $out = [];
        $stored = self::stored();
        foreach (self::fingerprints() as $f => $h) {
            $v = (string) config('ai.prompt_version.' . $f, 'v0');
            $s = $stored[$f] ?? null;
            $state = !$s ? 'chưa khóa' : ($s['hash'] === $h ? ($s['version'] === $v ? 'khớp' : 'đổi version nhưng lời nhắc như cũ') : ($s['version'] === $v ? 'LỜI NHẮC ĐỔI MÀ CHƯA TĂNG VERSION' : 'phiên bản mới, chưa ghi khóa'));
            $out[$f] = ['version' => $v, 'hash' => $h, 'lockedVersion' => $s['version'] ?? null, 'lockedHash' => $s['hash'] ?? null, 'state' => $state];
        }

        return $out;
    }

    public static function write(): void
    {
        $features = [];
        foreach (self::fingerprints() as $f => $h) {
            $features[$f] = ['version' => (string) config('ai.prompt_version.' . $f, 'v0'), 'hash' => $h];
        }
        file_put_contents(database_path(self::PATH), json_encode(['note' => 'Sinh bởi php artisan ai:prompts --lock. Không sửa tay.', 'features' => $features],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    }

    private static function hash(string ...$parts): string
    {
        // bỏ \r: tệp lưu kiểu CRLF trên Windows không được coi là "đổi lời nhắc"
        return hash('sha256', str_replace("\r\n", "\n", implode("\n\u{1F}\n", $parts)));
    }
}
