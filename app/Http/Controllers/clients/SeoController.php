<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** C4: /sitemap.xml và /robots.txt (động, để dùng đúng tên miền của môi trường). */
class SeoController extends Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    public function robots()
    {
        $disallow = ['/admin', '/login', '/register', '/logout', '/user-profile', '/change-password-profile', '/change-avatar-profile',
            '/create-booking', '/apply-coupon', '/checkBooking', '/tour-booked', '/cancel-booking', '/my-tours', '/reviews',
            '/xac-thuc-email', '/xac-thuc-email-moi', '/dat-lai-mat-khau', '/doi-mat-khau', '/quen-mat-khau',
            '/danh-dau', '/khay-chuyen-di', '/so-tay-du-hanh', '/search', '/search-voice-text', '/filter-tours', '/api/'];
        $txt = "User-agent: *\n" . implode("\n", array_map(fn($p) => 'Disallow: ' . $p, $disallow)) . "\n\nSitemap: " . url('/sitemap.xml') . "\n";

        return response($txt, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemap()
    {
        $xml = Cache::remember('sitemap.xml.v1', 3600, fn() => $this->build());

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    private function build(): string
    {
        $urls = [];
        $add = function (string $loc, mixed $lastmod = null, string $freq = 'weekly', string $prio = '0.6') use (&$urls) {
            $urls[] = ['loc' => $loc, 'lastmod' => $lastmod ? date('Y-m-d', strtotime((string) $lastmod)) : null, 'freq' => $freq, 'prio' => $prio];
        };

        foreach ([['home', '1.0'], ['destination', '0.9'], ['foods.index', '0.8'], ['experiences.index', '0.8'], ['mustdo.index', '0.7'], ['events.index', '0.8'], ['itineraries.index', '0.8'],
            ['guides.index', '0.8'], ['seasons.now', '0.7'], ['seasons.matrix', '0.7'], ['tours', '0.8'], ['data.sources', '0.3']] as [$name, $prio]) {
            $add(route($name), null, 'weekly', $prio);
        }
        foreach (range(1, 12) as $m) {
            $add(route('seasons.matrix', ['thang' => $m]), null, 'monthly', '0.5');
        }
        foreach (DB::table('tbl_destinations')->where('isActive', 1)->get() as $d) {
            $add(route('destination.show', ['slug' => $d->slug]), $d->updatedAt ?? $d->createdAt ?? null, 'weekly', '0.8');
        }
        foreach (DB::table('tbl_foods')->where('reviewStatus', 'published')->get() as $r) {
            $add(route('foods.show', ['slug' => $r->slug]), $r->updatedAt ?? $r->createdAt ?? null, 'monthly', '0.6');
        }
        foreach (DB::table('tbl_experiences')->where('reviewStatus', 'published')->get() as $r) {
            $add(route('experiences.show', ['slug' => $r->slug]), $r->updatedAt ?? $r->createdAt ?? null, 'monthly', '0.6');
        }
        foreach (DB::table('tbl_events')->where('reviewStatus', 'published')->get() as $r) {
            $add(route('events.show', ['slug' => $r->slug]), $r->updatedAt ?? $r->createdAt ?? null, 'monthly', '0.6');
        }
        foreach (DB::table('tbl_itineraries')->where('reviewStatus', 'published')->get() as $r) {
            $add(route('itineraries.show', ['slug' => $r->slug]), $r->updatedAt ?? $r->createdAt ?? null, 'monthly', '0.6');
        }
        $guides = DB::table('tbl_guides as g')->leftJoin('tbl_guide_categories as c', 'c.categoryId', '=', 'g.categoryId')
            ->where('g.reviewStatus', 'published')->get(['g.slug', 'g.updatedOn', 'c.slug as catSlug']);
        foreach ($guides->pluck('catSlug')->map(fn($s) => $s ?: 'khac')->unique() as $cat) {
            $add(route('guides.category', ['category' => $cat]), null, 'weekly', '0.6');
        }
        foreach ($guides as $g) {
            $add(route('guides.show', ['category' => $g->catSlug ?: 'khac', 'slug' => $g->slug]), $g->updatedOn, 'monthly', '0.7');
        }

        $x = fn(string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $u) {
            $out .= "  <url><loc>{$x($u['loc'])}</loc>" . ($u['lastmod'] ? "<lastmod>{$u['lastmod']}</lastmod>" : '')
                . "<changefreq>{$u['freq']}</changefreq><priority>{$u['prio']}</priority></url>\n";
        }

        return $out . "</urlset>\n";
    }
}
