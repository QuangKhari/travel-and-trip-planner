<?php

namespace App\Services;

use App\Support\DiscoverImage;
use App\Support\VietnamMap;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Nguồn dữ liệu duy nhất cho "Khám phá theo cảm hứng".
 * Trang Home, trang /explore và API /api/v1/inspirations đều gọi lớp này,
 * nên nơi hiển thị không cần biết dữ liệu đến từ đâu (DB, sau này thêm thời tiết / POI).
 */
class InspirationService
{
    public const DOMAINS = ['b' => 'Miền Bắc', 't' => 'Miền Trung', 'n' => 'Miền Nam'];

    /** Câu viết tay hiển thị trên ảnh hero của từng cảm hứng. */
    public const QUOTES = [
        'bien' => 'Biển xanh là khởi đầu của những hành trình đẹp!',
        'thien-nhien' => 'Đi giữa mây và rừng, lòng nhẹ tênh.',
        'chua-lanh' => 'Chậm lại một nhịp, bình yên sẽ tự tìm đến.',
        'van-hoa' => 'Mỗi con phố cũ là một trang sử đang mở.',
        'am-thuc' => 'Nhớ một vùng đất bằng hương vị của nó.',
        'mien-que' => 'Về quê, nghe nhịp sống chậm của đồng lúa.',
        'san-anh' => 'Dậy sớm một chút, đổi lấy khung hình đáng nhớ.',
        'hai-nguoi' => 'Một chuyến đi, hai người, vạn kỷ niệm.',
    ];

    /** Các cảm hứng đang bật, kèm ảnh bìa và số điểm đến. */
    public function all(): Collection
    {
        $counts = DB::table('tbl_destination_inspiration as di')
            ->join('tbl_destinations as d', 'd.destinationId', '=', 'di.destinationId')
            ->where('d.isActive', 1)
            ->groupBy('di.inspirationId')
            ->selectRaw('di.inspirationId, COUNT(*) as total')
            ->get()
            ->pluck('total', 'inspirationId');

        return DB::table('tbl_inspirations')
            ->where('isActive', 1)
            ->orderBy('displayOrder')
            ->get()
            ->map(function ($row) use ($counts) {
                $row->image = DiscoverImage::url($row->coverImage);
                $row->destinationCount = (int) ($counts[$row->inspirationId] ?? 0);

                return $row;
            });
    }

    /**
     * Dữ liệu cho "Bản đồ cảm xúc" ở Home: mọi điểm đến đang bật kèm vị trí trên bản đồ (%)
     * và điểm theo từng cảm hứng. Front-end chỉ việc đọc JSON này.
     */
    public function mapData(): array
    {
        $inspirations = $this->all();
        $slugById = $inspirations->pluck('slug', 'inspirationId');

        $scores = DB::table('tbl_destination_inspiration')->get()->groupBy('destinationId');

        $tourCounts = DB::table('tbl_tours')
            ->whereNotNull('destinationId')
            ->where('availability', 1)
            ->where('quantity', '>', 0)
            ->whereDate('startDate', '>', now()->toDateString())
            ->groupBy('destinationId')
            ->selectRaw('destinationId, COUNT(*) as total')
            ->get()
            ->pluck('total', 'destinationId');

        try {
            $weatherRows = DB::table('tbl_destination_weather')->get()->keyBy('destinationId');
        } catch (\Throwable $e) {
            $weatherRows = collect(); // chưa chạy migration thời tiết: vẫn hiển thị trang
        }

        $destinations = DB::table('tbl_destinations')
            ->where('isActive', 1)
            ->orderBy('destinationId')
            ->get()
            ->map(function ($d) use ($scores, $slugById, $tourCounts, $weatherRows) {
                [$x, $y] = VietnamMap::project((float) $d->latitude, (float) $d->longitude);

                $own = [];
                foreach ($scores->get($d->destinationId, []) as $row) {
                    if (isset($slugById[$row->inspirationId])) {
                        $own[$slugById[$row->inspirationId]] = (int) $row->score;
                    }
                }

                $tours = (int) ($tourCounts[$d->destinationId] ?? 0);

                $w = isset($weatherRows[$d->destinationId]) ? json_decode($weatherRows[$d->destinationId]->payload, true) : null;

                return [
                    'slug' => $d->slug,
                    'name' => $d->name,
                    'province' => $d->province,
                    'domain' => $d->domain,
                    'domainName' => self::DOMAINS[$d->domain] ?? '',
                    'description' => $d->description,
                    'image' => DiscoverImage::url($d->coverImage),
                    'x' => $x,
                    'y' => $y,
                    'url' => route('destination.show', ['slug' => $d->slug]),
                    'weather' => $w ? ['temp' => $w['current']['temp'], 'label' => $w['current']['label'], 'icon' => $w['current']['icon']] : null,
                    'imageCredit' => $d->imageCredit,
                    'tourCount' => $tours,
                    'tourUrl' => $tours > 0 ? route('tours', ['keyword' => $d->name]) : null,
                    'scores' => $own,
                ];
            })
            ->values()
            ->all();

        return [
            'exploreUrl' => route('explore'),
            'inspirations' => $inspirations->map(fn($i) => [
                'slug' => $i->slug,
                'name' => $i->name,
                'tagline' => $i->tagline,
                'headline' => $i->headline,
                'icon' => $i->icon,
                'count' => $i->destinationCount,
                'image' => $i->image,
                'quote' => self::QUOTES[$i->slug] ?? $i->headline,
            ])->values()->all(),
            'destinations' => $destinations,
        ];
    }

    public function find(string $slug): ?object
    {
        $row = DB::table('tbl_inspirations')->where('slug', $slug)->where('isActive', 1)->first();
        if ($row) {
            $row->image = DiscoverImage::url($row->coverImage);
        }

        return $row;
    }

    /**
     * Điểm đến thuộc một cảm hứng, xếp theo score giảm dần.
     * Cùng một điểm đến xuất hiện ở nhiều cảm hứng nhưng ở vị trí khác nhau.
     *
     * @param string|null $domain 'b' | 't' | 'n' | null (tất cả)
     */
    public function destinations(object $inspiration, ?string $domain = null, int $limit = 24): Collection
    {
        $tourCount = DB::table('tbl_tours as t')
            ->selectRaw('COUNT(*)')
            ->whereColumn('t.destinationId', 'd.destinationId')
            ->where('t.availability', 1)
            ->where('t.quantity', '>', 0)
            ->whereDate('t.startDate', '>', now()->toDateString());

        $q = DB::table('tbl_destination_inspiration as di')
            ->join('tbl_destinations as d', 'd.destinationId', '=', 'di.destinationId')
            ->where('di.inspirationId', $inspiration->inspirationId)
            ->where('d.isActive', 1)
            ->select(
                'd.destinationId',
                'd.slug',
                'd.name',
                'd.province',
                'd.domain',
                'd.description',
                'd.latitude',
                'd.longitude',
                'd.coverImage',
                'd.imageCredit',
                'di.score'
            )
            ->selectSub($tourCount, 'tourCount')
            ->orderByDesc('di.score')
            ->orderBy('d.name')
            ->limit($limit);

        if ($domain !== null && isset(self::DOMAINS[$domain])) {
            $q->where('d.domain', $domain);
        }

        return $q->get()->map(function ($row) {
            $row->image = DiscoverImage::url($row->coverImage);
            $row->url = route('destination.show', ['slug' => $row->slug]);
            $row->domainName = self::DOMAINS[$row->domain] ?? '';
            $row->tourCount = (int) $row->tourCount;

            return $row;
        });
    }
}
