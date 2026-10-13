<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\InspirationService;
use Illuminate\Http\Request;

class ExploreController extends Controller
{
    /** GET /explore?inspiration=bien[&domain=t] */
    public function index(Request $request, InspirationService $service)
    {
        $slug = (string) $request->query('inspiration', '');
        $inspiration = preg_match('/^[a-z0-9-]{1,60}$/', $slug) ? $service->find($slug) : null;

        // Thiếu hoặc sai cảm hứng: quay về khu vực chọn cảm hứng trên trang chủ
        if (!$inspiration) {
            return redirect(route('home') . '#kham-pha');
        }

        $domain = $request->query('domain');
        $domain = is_string($domain) && isset(InspirationService::DOMAINS[$domain]) ? $domain : null;

        $title = $inspiration->name;
        $destinations = $service->destinations($inspiration, $domain);
        $others = $service->all()->where('slug', '!=', $inspiration->slug)->values();
        $domains = InspirationService::DOMAINS;

        return view('clients.explore', compact('title', 'inspiration', 'destinations', 'others', 'domain', 'domains'));
    }
}
