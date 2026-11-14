<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Web Push không kèm nội dung ("tickle"): máy chủ chỉ báo trình duyệt "có tin mới", service worker tự hỏi /thong-bao/moi-nhat
 * (cùng phiên đăng nhập) để lấy tiêu đề. Nhờ vậy không cần mã hóa nội dung (RFC 8291) hay thư viện ngoài; chỉ cần ký VAPID (RFC 8292, ES256)
 * bằng openssl có sẵn của PHP. Endpoint trả 404/410 (người dùng gỡ quyền) thì xóa đăng ký.
 */
final class WebPush
{
    public static function enabled(): bool
    {
        return (string) config('push.public') !== '' && (string) config('push.private') !== '' && Schema::hasTable('tbl_push_subscriptions');
    }

    /** @return array{public:string,private:string} khóa mới: public = base64url điểm không nén, private = base64(PEM) */
    public static function generateKeys(): array
    {
        $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($k, $pem);
        $d = openssl_pkey_get_details($k)['ec'];

        return ['public' => self::b64u("\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT)), 'private' => base64_encode($pem)];
    }

    public static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /** JWT ES256 cho VAPID: chữ ký DER của openssl đổi sang r||s 64 byte. */
    public static function jwt(string $audience, int $exp): string
    {
        $h = self::b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $p = self::b64u(json_encode(['aud' => $audience, 'exp' => $exp, 'sub' => (string) config('push.subject')]));
        $key = openssl_pkey_get_private(base64_decode((string) config('push.private')));
        openssl_sign($h . '.' . $p, $der, $key, OPENSSL_ALGO_SHA256);

        return $h . '.' . $p . '.' . self::b64u(self::derToRaw($der));
    }

    /** Chữ ký ECDSA dạng DER (SEQUENCE{INTEGER r, INTEGER s}) -> r||s, mỗi số 32 byte. */
    public static function derToRaw(string $der): string
    {
        $o = 2;
        if (ord($der[1]) & 0x80) {
            $o += ord($der[1]) & 0x7f;
        }
        $rl = ord($der[$o + 1]);
        $r = substr($der, $o + 2, $rl);
        $o2 = $o + 2 + $rl;
        $sl = ord($der[$o2 + 1]);
        $s = substr($der, $o2 + 2, $sl);
        $fix = fn(string $x) => str_pad(ltrim($x, "\0"), 32, "\0", STR_PAD_LEFT);

        return $fix($r) . $fix($s);
    }

    /** Gõ cửa mọi thiết bị đã đăng ký của các người dùng. @return int số lần gửi thành công */
    public function ping(array $userIds): int
    {
        if (!self::enabled() || !$userIds) {
            return 0;
        }
        $ok = 0;
        foreach (DB::table('tbl_push_subscriptions')->whereIn('userId', array_unique($userIds))->get() as $s) {
            $u = parse_url($s->endpoint);
            if (!$u || ($u['scheme'] ?? '') !== 'https' || empty($u['host'])) {
                continue;
            }
            try {
                $res = Http::timeout(5)->withHeaders([
                    'TTL' => (string) config('push.ttl', 21600), 'Urgency' => 'normal',
                    'Authorization' => 'vapid t=' . self::jwt($u['scheme'] . '://' . $u['host'], time() + 12 * 3600) . ', k=' . config('push.public'),
                ])->withBody('', 'application/octet-stream')->post($s->endpoint);
                if (in_array($res->status(), [404, 410], true)) {
                    DB::table('tbl_push_subscriptions')->where('subId', $s->subId)->delete();
                } elseif ($res->successful()) {
                    $ok++;
                } else {
                    DB::table('tbl_push_subscriptions')->where('subId', $s->subId)->update(['failedAt' => now()]);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $ok;
    }
}
