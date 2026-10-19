<?php

namespace App\Console\Commands;

use App\Services\Content\ImportRaw;
use App\Services\Content\WikidataClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ImportWikidata extends Command
{
    protected $signature = 'content:import-wikidata
        {kind : foods | events}
        {--limit=500 : Số kết quả tối đa}
        {--simple : Truy vấn nhẹ (không đi theo lớp con), dùng khi bị quá thời gian}';

    protected $description = 'A3: tìm ứng viên món ăn/lễ hội trên Wikidata (CC0), lưu vùng đệm tbl_import_raw và xuất CSV để chọn';

    public function handle(WikidataClient $wd): int
    {
        $kind = $this->argument('kind');
        if (!in_array($kind, ['foods', 'events'], true)) {
            $this->error('kind phải là foods hoặc events');

            return self::FAILURE;
        }

        try {
            $rows = $wd->sparql(WikidataClient::query($kind, (bool) $this->option('simple'), (int) $this->option('limit')));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $csv = "\xEF\xBB\xBFqid,ten_vi,bai_wikipedia_vi,url\n";   // BOM để Excel đọc đúng tiếng Việt
        $new = 0;
        foreach ($rows as $r) {
            $qid = WikidataClient::qid($r['item']['value']);
            $url = $r['article']['value'];
            $title = ImportRaw::titleFromUrl($url);
            $label = $r['label']['value'];
            if (ImportRaw::put('wikidata', $qid, ['qid' => $qid, 'kind' => $kind, 'label' => $label, 'viTitle' => $title, 'viUrl' => $url]) === 'new') {
                $new++;
            }
            $csv .= sprintf("%s,\"%s\",\"%s\",%s\n", $qid, str_replace('"', '""', $label), str_replace('"', '""', (string) $title), $url);
        }

        $path = "imports/wikidata-{$kind}-" . now()->format('Ymd') . '.csv';
        Storage::disk('local')->put($path, $csv);

        $this->info(count($rows) . " kết quả ({$new} mới trong vùng đệm). CSV: storage/app/{$path}");
        $this->line('Chọn dòng phù hợp rồi thêm tên bài (viTitle) vào database/seeders/data/demo_content.json, sau đó chạy content:import-articles.');

        return self::SUCCESS;
    }
}
