<?php

namespace App\Console\Commands;

use App\Services\Discover\WikipediaClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Soạn danh sách ẢNH ĐỀ XUẤT từ Wikimedia Commons cho địa điểm chưa có ảnh, để quản trị viên duyệt
 * (Quản trị → Dữ liệu địa điểm → Ảnh & mô tả). Lệnh KHÔNG tự gắn ảnh vào địa điểm.
 *
 *   php artisan places:media-suggest                 mọi điểm đến, tối đa 60 nơi
 *   php artisan places:media-suggest --dest=18 --per=4 --radius=300
 *
 * Cách tìm: ảnh Commons chụp trong bán kính --radius mét quanh tọa độ địa điểm (ít nhầm nhất); không có thì tìm theo
 * "tên + điểm đến". Chỉ giữ ảnh có giấy phép mở (config discover.wikipedia.free_licenses), ghi kèm tác giả + giấy phép.
 */
class PlacesMediaSuggest extends Command
{
    protected $signature = 'places:media-suggest {--dest=* : mã điểm đến} {--limit=60 : số địa điểm tối đa} {--per=4 : số ảnh đề xuất mỗi nơi} {--radius=250 : bán kính tìm theo vị trí (m)}';

    protected $description = 'Đề xuất ảnh Wikimedia Commons (giấy phép mở) cho địa điểm chưa có ảnh, chờ quản trị viên duyệt';

    public function handle(WikipediaClient $wiki): int
    {
        if (!Schema::hasTable('tbl_place_media_candidates')) {
            $this->error('Chưa chạy migration 2026_12_12_000001_place_media.');

            return self::FAILURE;
        }
        $per = max(1, min(8, (int) $this->option('per')));
        $places = DB::table('tbl_external_places as p')->join('tbl_destinations as d', 'd.destinationId', '=', 'p.destinationId')
            ->where('p.reviewStatus', 'published')->whereNull('p.coverImage')
            ->when($this->option('dest'), fn($q, $ids) => $q->whereIn('p.destinationId', array_map('intval', (array) $ids)))
            ->whereNotIn('p.placeId', DB::table('tbl_place_media_candidates')->where('status', 'new')->select('placeId'))
            ->orderByDesc('p.rank')->limit(max(1, (int) $this->option('limit')))
            ->get(['p.placeId', 'p.name', 'p.latitude', 'p.longitude', 'd.name as destName']);
        $width = 960;
        $total = 0;
        foreach ($places as $p) {
            $files = [];
            foreach ($wiki->geoFiles((float) $p->latitude, (float) $p->longitude, (int) $this->option('radius'), $per * 3) as $g) {
                $files[$g['file']] = ['dist' => $g['dist'], 'via' => 'geo'];
            }
            if (count($files) < $per) {
                foreach ($wiki->searchFiles($p->name . ' ' . $p->destName, $per * 2) as $f) {
                    $files[$f] ??= ['dist' => null, 'via' => 'name'];
                }
            }
            $kept = 0;
            foreach ($files as $file => $meta) {
                if ($kept >= $per) {
                    break;
                }
                usleep((int) config('discover.wikipedia.delay_us', 300000));
                $info = $wiki->imageInfo($file, $width);
                if (!$info || $info['url'] === '' || !$info['free']) {
                    continue;
                }
                DB::table('tbl_place_media_candidates')->insertOrIgnore([
                    'placeId' => $p->placeId, 'file' => mb_substr($file, 0, 255), 'thumbUrl' => mb_substr($info['url'], 0, 500), 'pageUrl' => $info['page'] ? mb_substr($info['page'], 0, 255) : null,
                    'license' => mb_substr($info['license'], 0, 60), 'artist' => mb_substr($info['artist'], 0, 255), 'distanceM' => $meta['dist'], 'via' => $meta['via'], 'createdAt' => now(),
                ]);
                $kept++;
            }
            $total += $kept;
            $this->line(sprintf('  #%d %s: %d ảnh đề xuất', $p->placeId, $p->name, $kept));
        }
        $this->info("Xong: {$total} ảnh đề xuất cho " . count($places) . ' nơi. Duyệt ở Quản trị → Dữ liệu địa điểm → Ảnh & mô tả.');

        return self::SUCCESS;
    }
}
