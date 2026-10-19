<?php

namespace App\Services\Content;

use Illuminate\Support\Facades\Http;

/** Truy vấn SPARQL Wikidata (CC0). Chỉ dùng trong lệnh artisan, có User-Agent định danh. */
class WikidataClient
{
    public const ENDPOINT = 'https://query.wikidata.org/sparql';

    /** @return list<array<string,array{value:string}>> */
    public function sparql(string $query): array
    {
        $res = Http::withHeaders(['User-Agent' => config('discover.user_agent'), 'Accept' => 'application/sparql-results+json'])
            ->timeout(70)
            ->get(self::ENDPOINT, ['query' => $query, 'format' => 'json']);

        if (!$res->ok()) {
            throw new \RuntimeException('Wikidata trả HTTP ' . $res->status() . ' (truy vấn quá nặng? thử --simple)');
        }

        return $res->json('results.bindings') ?? [];
    }

    /**
     * Món ăn gốc Việt Nam / lễ hội ở Việt Nam, có bài trên Wikipedia tiếng Việt.
     * Q881 = Việt Nam, Q746549 = món ăn, Q132241 = lễ hội, P495 = nước xuất xứ, P17 = quốc gia.
     * $simple=true: bỏ đường dẫn P279* (nhanh hơn, ít kết quả hơn) khi bị quá thời gian.
     */
    public static function query(string $kind, bool $simple, int $limit): string
    {
        $inst = $simple ? 'wdt:P31' : 'wdt:P31/wdt:P279*';
        $where = $kind === 'foods'
            ? "?item wdt:P495 wd:Q881 . ?item {$inst} wd:Q746549 ."
            : "?item wdt:P17 wd:Q881 . ?item {$inst} wd:Q132241 .";

        return "SELECT DISTINCT ?item ?label ?article WHERE { {$where}
            ?article schema:about ?item ; schema:isPartOf <https://vi.wikipedia.org/> .
            ?item rdfs:label ?label . FILTER(LANG(?label) = \"vi\") } LIMIT {$limit}";
    }

    public static function qid(string $uri): string
    {
        return substr(strrchr($uri, '/'), 1);
    }
}
