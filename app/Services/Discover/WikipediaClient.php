<?php

namespace App\Services\Discover;

use Illuminate\Support\Facades\Http;

/**
 * Lấy mô tả / tọa độ / ảnh từ Wikipedia + Wikimedia Commons.
 * Chỉ chạy trong lệnh artisan (discover:import-wikipedia), không gọi khi người dùng mở trang.
 */
class WikipediaClient
{
    private function http()
    {
        return Http::withHeaders(['User-Agent' => config('discover.user_agent'), 'Accept' => 'application/json'])
            ->timeout(config('discover.http_timeout'))
            ->retry(2, 800, throw: false);
    }

    /**
     * @return array{title:string,extract:string,lat:?float,lng:?float,url:?string,imageFile:?string}|null
     */
    public function summary(string $title): ?array
    {
        $lang = config('discover.wikipedia.lang', 'vi');
        $url = "https://{$lang}.wikipedia.org/api/rest_v1/page/summary/" . rawurlencode(str_replace(' ', '_', $title));
        $res = $this->http()->get($url, ['redirect' => 'true']);
        if (!$res->ok()) {
            return null;
        }

        return self::parseSummary($res->json() ?? []);
    }

    /** @internal tách ra để test không cần mạng */
    public static function parseSummary(array $j): ?array
    {
        if (empty($j['extract']) || ($j['type'] ?? '') === 'disambiguation') {
            return null;
        }
        $img = $j['originalimage']['source'] ?? $j['thumbnail']['source'] ?? null;

        return [
            'title' => (string) ($j['title'] ?? ''),
            'extract' => trim((string) $j['extract']),
            'lat' => isset($j['coordinates']['lat']) ? (float) $j['coordinates']['lat'] : null,
            'lng' => isset($j['coordinates']['lon']) ? (float) $j['coordinates']['lon'] : null,
            'url' => $j['content_urls']['desktop']['page'] ?? null,
            'imageFile' => $img ? self::commonsFileName($img) : null,
        ];
    }

    /** upload.wikimedia.org/wikipedia/commons/a/ab/Ten.jpg  hoặc  .../thumb/a/ab/Ten.jpg/320px-Ten.jpg  ->  Ten.jpg */
    public static function commonsFileName(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $parts = array_values(array_filter(explode('/', $path), 'strlen'));
        $i = array_search('thumb', $parts, true);
        if ($i !== false) {
            $name = $parts[$i + 3] ?? null;     // thumb/a/ab/Ten.jpg/320px-Ten.jpg
        } else {
            $name = end($parts) ?: null;
        }

        return $name ? rawurldecode($name) : null;
    }

    /**
     * Thông tin ảnh trên Commons: URL đã thu nhỏ + giấy phép + tác giả.
     *
     * @return array{url:string,page:?string,license:string,artist:string,free:bool}|null
     */
    public function imageInfo(string $fileName, int $width): ?array
    {
        $res = $this->http()->get('https://commons.wikimedia.org/w/api.php', [
            'action' => 'query',
            'format' => 'json',
            'formatversion' => 2,
            'titles' => 'File:' . $fileName,
            'prop' => 'imageinfo',
            'iiprop' => 'url|extmetadata',
            'iiurlwidth' => $width,
            'iiextmetadatafilter' => 'LicenseShortName|Artist|Credit|NonFree',
        ]);
        if (!$res->ok()) {
            return null;
        }

        return self::parseImageInfo($res->json() ?? []);
    }

    /** @internal */
    public static function parseImageInfo(array $j): ?array
    {
        $info = $j['query']['pages'][0]['imageinfo'][0] ?? null;
        if (!$info) {
            return null;
        }
        $meta = $info['extmetadata'] ?? [];
        $license = trim((string) ($meta['LicenseShortName']['value'] ?? ''));
        $artist = trim(html_entity_decode(strip_tags((string) ($meta['Artist']['value'] ?? ''))));
        $artist = preg_replace('/\s+/', ' ', $artist);

        return [
            'url' => $info['thumburl'] ?? $info['url'] ?? '',
            'page' => $info['descriptionurl'] ?? null,
            'license' => $license,
            'artist' => $artist ?: 'Wikimedia Commons',
            'free' => self::isFreeLicense($license) && empty($meta['NonFree']['value']),
        ];
    }

    public static function isFreeLicense(string $license): bool
    {
        if ($license === '') {
            return false;
        }
        foreach (config('discover.wikipedia.free_licenses', []) as $prefix) {
            if (stripos($license, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    /** Tải ảnh -> nhị phân (null nếu lỗi). Chỉ gọi tới máy chủ ảnh của Wikimedia. */
    public function download(string $url): ?string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (!in_array($host, ['upload.wikimedia.org', 'thumb.wikimedia.org'], true)) {
            return null;
        }
        $res = $this->http()->get($url);

        return $res->ok() ? $res->body() : null;
    }
}
