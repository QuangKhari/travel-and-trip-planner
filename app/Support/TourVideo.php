<?php

namespace App\Support;

/**
 * Chuẩn hoá link video của tour.
 * Hỗ trợ: YouTube (watch / youtu.be / shorts / embed), Vimeo, và file trực tiếp .mp4 / .webm / .ogg.
 * Chỉ nhận http(s). Link lạ -> null (không hiện video, không gây lỗi trang).
 */
class TourVideo
{
    /**
     * @return array{type:string,provider:string,src:string,poster:?string,url:string}|null
     *  type: 'iframe' (YouTube/Vimeo) hoặc 'file' (thẻ <video>)
     */
    public static function parse(?string $url): ?array
    {
        $url = trim((string) $url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return null;
        }

        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return null;
        }

        $host = strtolower(preg_replace('/^www\./i', '', $parts['host']));
        $path = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $q);

        // ---- YouTube ----
        if ($host === 'youtu.be' || in_array($host, ['youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com'], true)) {
            $id = null;
            if ($host === 'youtu.be') {
                $id = trim($path, '/');
            } elseif (!empty($q['v']) && is_string($q['v'])) {
                $id = $q['v'];
            } elseif (preg_match('#^/(?:embed|shorts|live|v)/([^/?]+)#', $path, $m)) {
                $id = $m[1];
            }

            if ($id && preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
                return [
                    'type'     => 'iframe',
                    'provider' => 'youtube',
                    'src'      => "https://www.youtube-nocookie.com/embed/{$id}?autoplay=1&rel=0&modestbranding=1",
                    'poster'   => "https://i.ytimg.com/vi/{$id}/hqdefault.jpg",
                    'url'      => $url,
                ];
            }

            return null;
        }

        // ---- Vimeo ----
        if ($host === 'vimeo.com' || $host === 'player.vimeo.com') {
            if (preg_match('#/(\d{6,})#', $path, $m)) {
                return [
                    'type'     => 'iframe',
                    'provider' => 'vimeo',
                    'src'      => "https://player.vimeo.com/video/{$m[1]}?autoplay=1",
                    'poster'   => null,
                    'url'      => $url,
                ];
            }

            return null;
        }

        // ---- File video trực tiếp ----
        if (preg_match('#\.(mp4|webm|ogg)$#i', $path)) {
            return [
                'type'     => 'file',
                'provider' => 'file',
                'src'      => $url,
                'poster'   => null,
                'url'      => $url,
            ];
        }

        return null;
    }

    /** Dùng khi lưu: trả về link gốc đã kiểm tra, hoặc null nếu để trống / không hợp lệ. */
    public static function normalize(?string $url): ?string
    {
        $v = self::parse($url);

        return $v ? $v['url'] : null;
    }
}
