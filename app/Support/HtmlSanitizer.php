<?php

namespace App\Support;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Làm sạch HTML do người dùng/admin nhập (mô tả tour, lịch trình).
 * Chỉ giữ các thẻ định dạng cơ bản; loại bỏ script, onerror, iframe, javascript:...
 */
class HtmlSanitizer
{
    private static ?HTMLPurifier $purifier = null;

    public static function clean(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        if (self::$purifier === null) {
            $cachePath = storage_path('app/purifier');
            if (!is_dir($cachePath)) {
                @mkdir($cachePath, 0775, true);
            }

            $config = HTMLPurifier_Config::createDefault();
            $config->set('Cache.SerializerPath', $cachePath);
            $config->set(
                'HTML.Allowed',
                'p,br,strong,b,em,i,u,s,ul,ol,li,h1,h2,h3,h4,h5,h6,blockquote,'
                    . 'a[href|title|target],img[src|alt|width|height],'
                    . 'table,thead,tbody,tr,th,td,span,div'
            );
            // Chỉ cho http, https, mailto (không javascript:, không data:)
            $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
            $config->set('Attr.AllowedFrameTargets', ['_blank']);
            $config->set('HTML.TargetNoopener', true);

            self::$purifier = new HTMLPurifier($config);
        }

        return self::$purifier->purify($html);
    }
}
