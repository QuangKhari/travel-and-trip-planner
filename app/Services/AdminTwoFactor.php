<?php

namespace App\Services;

use App\Support\AesGcm;
use App\Support\AuditLog;
use App\Support\Totp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Đăng nhập hai lớp cho trang quản trị (Đợt C mục 18).
 * Bật: tạo khóa bí mật (giữ trong phiên tới khi xác nhận) -> người dùng quét QR -> nhập đúng một mã -> lưu khóa đã mã hóa + 8 mã dự phòng.
 * Đăng nhập: đúng mật khẩu chưa vào ngay; phải nhập mã 6 số (hoặc một mã dự phòng, dùng một lần).
 */
final class AdminTwoFactor
{
    private const CTX = 'tbl_admin.totpSecret';
    public const RECOVERY_COUNT = 8;
    public const PENDING_TTL = 300;   // giây chờ nhập mã sau khi đúng mật khẩu

    public static function ready(): bool
    {
        static $ok = null;

        return $ok ??= Schema::hasColumn('tbl_admin', 'totpSecret');
    }

    public static function enabled(int $adminId): bool
    {
        return self::ready() && (bool) DB::table('tbl_admin')->where('adminId', $adminId)->whereNotNull('totpEnabledAt')->value('adminId');
    }

    /** Bắt buộc cho vai trò nào (config travela.admin_2fa_required, ví dụ "admin" hoặc "admin,manager"). */
    public static function requiredFor(string $role): bool
    {
        $roles = array_filter(array_map('trim', explode(',', (string) config('travela.admin_2fa_required', ''))));

        return self::ready() && in_array($role, $roles, true);
    }

    /** Khóa đang chờ xác nhận trong phiên (tạo mới nếu chưa có). */
    public static function pendingSecret(): string
    {
        $s = session('admin2faSetup');
        if (!is_string($s) || strlen($s) < 16) {
            $s = Totp::secret();
            session(['admin2faSetup' => $s]);
        }

        return $s;
    }

    public static function uri(object $admin, string $secret): string
    {
        return Totp::uri((string) config('site.name', 'Travela') . ' Admin', (string) $admin->userName, $secret);
    }

    /** @return array{ok:bool, message:string, codes?:string[]} */
    public static function confirm(int $adminId, string $code): array
    {
        $secret = session('admin2faSetup');
        if (!is_string($secret) || $secret === '') {
            return ['ok' => false, 'message' => 'Phiên cài đặt đã hết. Tải lại trang và quét mã QR mới.'];
        }
        $step = Totp::verify($secret, $code);
        if ($step === null) {
            return ['ok' => false, 'message' => 'Mã không đúng. Kiểm tra giờ trên điện thoại rồi nhập mã đang hiện.'];
        }
        $codes = self::newRecovery();
        DB::table('tbl_admin')->where('adminId', $adminId)->update([
            'totpSecret' => AesGcm::encrypt($secret, self::CTX),
            'totpEnabledAt' => now(),
            'totpLastStep' => $step,
            'totpRecovery' => json_encode(array_map([self::class, 'hashCode'], $codes)),
        ]);
        session()->forget('admin2faSetup');
        AuditLog::record('admin.2fa_on', 'admin', $adminId, 'Bật đăng nhập hai lớp');

        return ['ok' => true, 'message' => 'Đã bật đăng nhập hai lớp.', 'codes' => $codes];
    }

    /**
     * Kiểm tra mã khi đăng nhập hoặc khi tắt: mã 6 số (không dùng lại được) hoặc mã dự phòng (xóa sau khi dùng).
     * @return 'totp'|'recovery'|null
     */
    public static function check(int $adminId, string $code): ?string
    {
        $row = DB::table('tbl_admin')->where('adminId', $adminId)->first(['totpSecret', 'totpEnabledAt', 'totpLastStep', 'totpRecovery']);
        if (!$row || !$row->totpEnabledAt || !$row->totpSecret) {
            return null;
        }
        $code = trim($code);
        if (preg_match('/^\d{3}\s?\d{3}$/', $code)) {
            $secret = (string) AesGcm::decrypt($row->totpSecret, self::CTX);
            $step = Totp::verify($secret, $code, $row->totpLastStep !== null ? (int) $row->totpLastStep : null);
            if ($step === null) {
                return null;
            }
            // cập nhật có điều kiện: hai yêu cầu cùng mã chạy song song thì chỉ một cái qua
            $n = DB::table('tbl_admin')->where('adminId', $adminId)
                ->where(fn($q) => $q->whereNull('totpLastStep')->orWhere('totpLastStep', '<', $step))
                ->update(['totpLastStep' => $step]);

            return $n ? 'totp' : null;
        }
        $hashes = json_decode((string) $row->totpRecovery, true) ?: [];
        $h = self::hashCode($code);
        $i = array_search($h, $hashes, true);
        if ($i === false) {
            return null;
        }
        unset($hashes[$i]);
        $n = DB::table('tbl_admin')->where('adminId', $adminId)->where('totpRecovery', $row->totpRecovery)
            ->update(['totpRecovery' => json_encode(array_values($hashes))]);
        if (!$n) {
            return null;
        }
        AuditLog::record('admin.2fa_recovery', 'admin', $adminId, 'Đăng nhập bằng mã dự phòng, còn ' . count($hashes) . ' mã');

        return 'recovery';
    }

    public static function recoveryLeft(int $adminId): int
    {
        return count(json_decode((string) DB::table('tbl_admin')->where('adminId', $adminId)->value('totpRecovery'), true) ?: []);
    }

    /** Tạo lại bộ mã dự phòng (bộ cũ hết hiệu lực). */
    public static function regenerate(int $adminId): array
    {
        $codes = self::newRecovery();
        DB::table('tbl_admin')->where('adminId', $adminId)->update(['totpRecovery' => json_encode(array_map([self::class, 'hashCode'], $codes))]);
        AuditLog::record('admin.2fa_codes', 'admin', $adminId, 'Tạo lại mã dự phòng');

        return $codes;
    }

    public static function disable(int $adminId, ?int $byAdminId = null): void
    {
        DB::table('tbl_admin')->where('adminId', $adminId)->update(['totpSecret' => null, 'totpEnabledAt' => null, 'totpLastStep' => null, 'totpRecovery' => null]);
        AuditLog::record('admin.2fa_off', 'admin', $adminId, $byAdminId && $byAdminId !== $adminId ? 'Quản trị viên đặt lại đăng nhập hai lớp' : 'Tắt đăng nhập hai lớp');
    }

    private static function newRecovery(): array
    {
        $out = [];
        while (count($out) < self::RECOVERY_COUNT) {
            $c = strtolower(substr(Totp::base32Encode(random_bytes(10)), 0, 10));
            $out[] = substr($c, 0, 5) . '-' . substr($c, 5);
        }

        return $out;
    }

    private static function hashCode(string $c): string
    {
        return hash('sha256', strtolower(preg_replace('/[^a-z0-9]/i', '', $c) ?? ''));
    }
}
