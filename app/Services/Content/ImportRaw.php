<?php

namespace App\Services\Content;

use Illuminate\Support\Facades\DB;

/**
 * Vùng đệm nạp dữ liệu thô (tbl_import_raw): lưu phản hồi nguồn ngoài để chạy lại không phải gọi API
 * và để truy vết nguồn gốc. source: wikidata | wikipedia | osm | openmeteo | commons.
 */
class ImportRaw
{
    /** @return 'new'|'changed'|'same' */
    public static function put(string $source, string $sourceId, array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $json);
        $q = DB::table('tbl_import_raw')->where('source', $source)->where('sourceId', $sourceId);
        $row = $q->first();

        if (!$row) {
            DB::table('tbl_import_raw')->insert(compact('source', 'sourceId') + ['payload' => $json, 'hash' => $hash, 'fetchedAt' => now()]);

            return 'new';
        }
        if ($row->hash === $hash) {
            $q->update(['fetchedAt' => now()]);

            return 'same';
        }
        $q->update(['payload' => $json, 'hash' => $hash, 'fetchedAt' => now()]);

        return 'changed';
    }

    public static function get(string $source, string $sourceId): ?array
    {
        $row = DB::table('tbl_import_raw')->where('source', $source)->where('sourceId', $sourceId)->first();

        return $row ? json_decode($row->payload, true) : null;
    }

    /** Tên bài Wikipedia từ URL https://vi.wikipedia.org/wiki/Ph%E1%BB%9F -> "Phở". */
    public static function titleFromUrl(?string $url): ?string
    {
        if (!$url || !preg_match('~/wiki/([^?#]+)~', $url, $m)) {
            return null;
        }

        return str_replace('_', ' ', rawurldecode($m[1]));
    }
}
