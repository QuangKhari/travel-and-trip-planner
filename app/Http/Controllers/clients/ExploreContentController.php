<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Content\ContentCatalog;
use App\Services\Content\UserMarks;
use Illuminate\Http\Request;

/**
 * B2, B4, B5: danh sách có lọc phía server và trang chi tiết cho ẩm thực, lễ hội, lịch trình, danh sách điểm đến.
 * Chỉ hiển thị nội dung published (ContentCatalog bảo đảm). Bộ lọc nằm trên URL, phân trang 12 mục.
 */
class ExploreContentController extends Controller
{
    public function __construct(private ContentCatalog $catalog)
    {
        parent::__construct();
    }

    private function page(Request $r): int
    {
        return max(1, min(500, (int) $r->query('page', 1)));
    }

    private function userId(Request $r): ?int
    {
        return $r->session()->has('userId') ? (int) $r->session()->get('userId') : null;
    }

    /** @return array<string,bool> loại đánh dấu hiện có của người dùng cho một mục */
    private function marks(Request $r, string $refType, string $slug, UserMarks $marks): array
    {
        $uid = $this->userId($r);
        $id = $uid ? $marks->resolve($refType, $slug) : null;

        return array_fill_keys($id ? $marks->typesFor($uid, $refType, $id) : [], true);
    }

    // GET /diem-den/tat-ca
    public function destinations(Request $r)
    {
        $res = $this->catalog->destinations($r->query(), $this->page($r));
        $inspirations = \Illuminate\Support\Facades\DB::table('tbl_inspirations')->where('isActive', 1)->orderBy('displayOrder')->pluck('name', 'slug')->all();
        $provinces = array_combine($this->catalog->provinces(), $this->catalog->provinces());
        $months = collect(range(1, 12))->mapWithKeys(fn($m) => [$m => "Tháng $m"])->all();

        return view('clients.guide.destinations', [
            'title' => 'Tất cả điểm đến',
            'items' => $res['items'],
            'filters' => $res['filters'],
            'total' => $res['total'],
            'regions' => ContentCatalog::REGIONS,
            'provinces' => $provinces,
            'inspirations' => $inspirations,
            'months' => $months,
        ]);
    }

    // GET /am-thuc
    public function foods(Request $r)
    {
        $res = $this->catalog->foods($r->query(), $this->page($r));
        $groups = $this->catalog->foodGroups();
        $groupCounts = $this->catalog->foodGroupCounts($res['filters']['mien'], $res['filters']['tinh']);
        $provinces = $this->catalog->foodProvinces();

        return view('clients.guide.foods', [
            'hero' => $this->catalog->featuredFoods(3),
            'groupCounts' => $groupCounts,
            'provinces' => array_combine($provinces, $provinces),
            'title' => 'Ẩm thực',
            'items' => $res['items'],
            'filters' => $res['filters'],
            'total' => $res['total'],
            'regions' => ContentCatalog::REGIONS,
            'groups' => array_combine($groups, $groups),
        ]);
    }

    // GET /am-thuc/{slug}
    public function food(Request $r, string $slug, UserMarks $marks)
    {
        $food = $this->catalog->food($slug);
        abort_unless($food, 404);

        return view('clients.guide.food', [
            'title' => $food->name,
            'food' => $food,
            'cover' => \App\Support\DiscoverImage::url($food->coverImage),
            'sameDest' => $food->destinationId ? $this->catalog->foodsOf((int) $food->destinationId, 6, (int) $food->foodId) : collect(),
            'variants' => $this->catalog->foodVariants($food),
            'events' => $food->destinationId ? $this->catalog->eventsOf((int) $food->destinationId, 4) : collect(),
            'marks' => $this->marks($r, 'food', $food->slug, $marks),
        ]);
    }

    // GET /le-hoi  (?che-do=lich -> redirect sang /le-hoi/lich)
    public function events(Request $r)
    {
        $res = $this->catalog->events($r->query(), $this->page($r));

        return view('clients.guide.events', [
            'title' => 'Lễ hội',
            'items' => $res['items'],
            'filters' => $res['filters'],
            'total' => $res['total'],
            'regions' => ContentCatalog::REGIONS,
            'months' => collect(range(1, 12))->mapWithKeys(fn($m) => [$m => "Tháng $m"])->all(),
        ]);
    }

    // GET /le-hoi/lich?thang=&nam=
    public function eventCalendar(Request $r)
    {
        $month = is_numeric($r->query('thang')) && (int) $r->query('thang') >= 1 && (int) $r->query('thang') <= 12 ? (int) $r->query('thang') : (int) date('n');
        $year = is_numeric($r->query('nam')) && (int) $r->query('nam') >= 2024 && (int) $r->query('nam') <= 2032 ? (int) $r->query('nam') : (int) date('Y');
        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $prev = $first->modify('-1 month');
        $next = $first->modify('+1 month');

        return view('clients.guide.event-calendar', [
            'title' => 'Lịch lễ hội tháng ' . $month . '/' . $year,
            'days' => $this->catalog->eventCalendar($year, $month),
            'year' => $year,
            'month' => $month,
            'daysInMonth' => (int) $first->format('t'),
            'offset' => (int) $first->format('N') - 1, // thứ Hai = 0
            'prevUrl' => route('events.calendar', ['thang' => (int) $prev->format('n'), 'nam' => (int) $prev->format('Y')]),
            'nextUrl' => route('events.calendar', ['thang' => (int) $next->format('n'), 'nam' => (int) $next->format('Y')]),
        ]);
    }

    // GET /le-hoi/{slug}
    public function event(Request $r, string $slug, UserMarks $marks)
    {
        $event = $this->catalog->event($slug);
        abort_unless($event, 404);

        return view('clients.guide.event', [
            'title' => $event->name,
            'event' => $event,
            'cover' => \App\Support\DiscoverImage::url($event->coverImage),
            'dates' => $this->catalog->eventDates($event, 3),
            'foods' => $event->destinationId ? $this->catalog->foodsOf((int) $event->destinationId, 4) : collect(),
            'marks' => $this->marks($r, 'event', $event->slug, $marks),
            'lunarText' => $this->lunarText($event),
        ]);
    }

    private function lunarText(object $e): ?string
    {
        if ($e->isLunar) {
            if (!$e->lunarDay) {
                return null;
            }

            return $e->lunarMonth ? "Ngày {$e->lunarDay} tháng {$e->lunarMonth} âm lịch" : "Ngày {$e->lunarDay} âm lịch hằng tháng";
        }
        if ($e->solarMonth) {
            return $e->solarDay ? "Ngày {$e->solarDay}/{$e->solarMonth} dương lịch" : "Tháng {$e->solarMonth} dương lịch";
        }

        return null;
    }

    // GET /lich-trinh
    public function itineraries(Request $r)
    {
        $res = $this->catalog->itineraries($r->query(), $this->page($r));
        $days = $this->catalog->itineraryDays();

        return view('clients.guide.itineraries', [
            'title' => 'Lịch trình gợi ý',
            'items' => $res['items'],
            'filters' => $res['filters'],
            'total' => $res['total'],
            'regions' => ContentCatalog::REGIONS,
            'dayOptions' => collect($days)->mapWithKeys(fn($d) => [$d => "$d ngày"])->all(),
        ]);
    }

    // GET /lich-trinh/{slug}
    public function itinerary(string $slug)
    {
        $data = $this->catalog->itinerary($slug);
        abort_unless($data, 404);

        return view('clients.guide.itinerary', [
            'title' => $data['itinerary']->title,
            'it' => $data['itinerary'],
            'days' => $data['days'],
            'points' => $data['points'],
            'cover' => \App\Support\DiscoverImage::url($data['itinerary']->coverImage),
            'stats' => $data['stats'],
            'tours' => app(\App\Services\Content\TourMatcher::class)->forDestinations($data['destIds'], 3),
            'plannerUrl' => config('postcards.planner_url') ? url(config('postcards.planner_url')) : null,
        ]);
    }
}
