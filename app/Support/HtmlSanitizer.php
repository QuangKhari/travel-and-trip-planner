<?php

namespace App\Support;

use App\Services\UnsafeHtmlException;
use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Làm sạch HTML do người dùng/admin nhập (mô tả tour, lịch trình).
 * Lớp 1: chặn các mẫu XSS rõ ràng.
 * Lớp 2: HTMLPurifier loại bỏ HTML/attribute/protocol nguy hiểm còn sót lại.
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

    public static function cleanOrReject(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        if (self::containsDangerousMarkup($html)) {
            throw new UnsafeHtmlException(
                'Nội dung mô tả chứa mã HTML/JavaScript không được phép. Vui lòng xóa nội dung nguy hiểm rồi thử lại.'
            );
        }

        return self::clean($html);
    }

    private static function containsDangerousMarkup(string $html): bool
    {
        $patterns = [
            '/<\s*script\b/i',
            '/<\s*(iframe|object|embed|applet|svg|math|style)\b/i',
            '/<[^>]*\bon[a-z][\w:-]*\s*=/i',
            '/<[^>]*\bsrcdoc\s*=/i',
            '/<[^>]*\b(?:href|src|action|formaction|xlink:href|poster|background)\s*=\s*["\']?\s*(?:javascript|vbscript)\s*:/i',
            '/<[^>]*\b(?:href|src|action|formaction|xlink:href|poster|background)\s*=\s*["\']?\s*data\s*:/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html) === 1) {
                return true;
            }
        }

        return false;
    }
}
