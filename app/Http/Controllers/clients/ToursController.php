<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Models\clients\Tours;
use App\Services\TourCatalog;
use Illuminate\Http\Request;

class ToursController extends Controller
{
    private $tours;
    private TourCatalog $catalog;

    public function __construct()
    {
        parent::__construct();
        $this->tours = new Tours();
        $this->catalog = new TourCatalog();
    }

    /** Trang /tours: render sẵn trang 1 theo tham số URL (chia sẻ link lọc được). */
    public function index(Request $request)
    {
        $title = 'Tours';
        [$minBound, $maxBound] = $this->catalog->priceBounds();
        $state = $this->readState($request, $minBound, $maxBound);

        $domain = $this->tours->getDomain();
        $domainsCount = [
            'b' => (int) optional($domain->firstWhere('domain', 'b'))->count,
            't' => (int) optional($domain->firstWhere('domain', 't'))->count,
            'n' => (int) optional($domain->firstWhere('domain', 'n'))->count,
        ];

        $popularTours = $this->tours->getPopularTours(3);
        $tours = $this->catalog->paginate($state, 9);
        $tours->withPath(route('tours'))->appends($request->except('page'));

        return view('clients.tours', compact('title', 'tours', 'domainsCount', 'popularTours', 'state', 'minBound', 'maxBound'));
    }

    /** AJAX: trả JSON { html, total, page, lastPage } cho trang /tours. */
    public function filterTours(Request $request)
    {
        [$minBound, $maxBound] = $this->catalog->priceBounds();
        $state = $this->readState($request, $minBound, $maxBound);

        $tours = $this->catalog->paginate($state, 9);
        $tours->withPath(route('tours'))->appends($request->except('page'));

        $html = view('clients.partials.filter-tours', compact('tours'))->render();

        if ($request->expectsJson()) {
            return response()->json([
                'html' => $html,
                'total' => $tours->total(),
                'page' => $tours->currentPage(),
                'lastPage' => $tours->lastPage(),
            ]);
        }

        return response($html);
    }

    /** Nhận ngày dạng Y-m-d (hoặc d/m/Y), trả về Y-m-d hoặc chuỗi rỗng nếu không hợp lệ. */
    private function readDate($value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        foreach (['Y-m-d', 'd/m/Y'] as $format) {
            try {
                $d = \Carbon\Carbon::createFromFormat($format, $value);
                if ($d && $d->format($format) === $value) {
                    return $d->format('Y-m-d');
                }
            } catch (\Throwable $e) {
            }
        }

        return '';
    }

    /** Đọc + làm sạch tham số lọc từ URL. */
    private function readState(Request $r, int $minBound, int $maxBound): array
    {
        $keyword = trim(mb_substr((string) $r->input('keyword', ''), 0, 100));

        $domain = in_array($r->input('domain'), ['b', 't', 'n'], true) ? $r->input('domain') : '';
        $days = array_key_exists((string) $r->input('days'), TourCatalog::DAY_GROUPS) ? (string) $r->input('days') : '';
        $rating = in_array((int) $r->input('rating'), [3, 4, 5], true) ? (int) $r->input('rating') : 0;
        $sort = in_array($r->input('sort'), TourCatalog::SORTS, true) ? $r->input('sort') : 'new';

        $min = $r->filled('min') && is_numeric($r->input('min')) ? max($minBound, (int) $r->input('min')) : $minBound;
        $max = $r->filled('max') && is_numeric($r->input('max')) ? min($maxBound, (int) $r->input('max')) : $maxBound;
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }

        $from = $this->readDate($r->input('from'));
        $to = $this->readDate($r->input('to'));

        return [
            'from' => $from,
            'to' => $to,
            'keyword' => $keyword,
            'domain' => $domain,
            'days' => $days,
            'rating' => $rating,
            'min' => $min,
            'max' => $max,
            'sort' => $sort,
            'page' => max(1, (int) $r->input('page', 1)),
        ];
    }
}
