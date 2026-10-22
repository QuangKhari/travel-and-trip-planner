@include('clients.blocks.header')

@php
    $base = 'clients/assets/images/';
    $art = fn($f) => asset($base . 'illustrations/' . $f);
    $wc = fn($f) => asset($base . 'watercolor/' . $f);
    $heroSrc = file_exists(public_path($base . 'destinations/hero-destination.jpg'))
        ? asset($base . 'destinations/hero-destination.jpg')
        : $art('scene-island-wide.svg');
    $bannerSrc = file_exists(public_path($base . 'destinations/banner-journey.jpg'))
        ? asset($base . 'destinations/banner-journey.jpg')
        : $art('scene-bay-wide.svg');
    $hasData = !empty($map['destinations']) && !empty($map['inspirations']);
@endphp

<main class="tvp tvf" id="tvpDest" data-art="{{ $art('') }}/">

    {{-- ===== HERO + TÌM KIẾM ===== --}}
    <section class="tvf-dhero" aria-labelledby="tvfDH1">
        <img class="tvf-deco tvf-dhero__wash" src="{{ $wc('wc-wash-teal.svg') }}" alt="" aria-hidden="true"
            width="900" height="620">
        <div class="tvf-dhero__photo">
            <img src="{{ $heroSrc }}" alt="" width="1400" height="800" fetchpriority="high">
        </div>
        <img class="tvf-deco tvf-leaf" src="{{ $wc('wc-leaf-c.svg') }}" alt="" aria-hidden="true" width="560"
            height="640" style="--r:-8deg;right:-50px;bottom:-50px;width:240px;transform:scaleX(-1)">
        <img class="tvf-deco tvf-leaf tvf-leaf--b" src="{{ $wc('wc-leaf-a.svg') }}" alt="" aria-hidden="true"
            width="560" height="640" style="--r:6deg;left:-70px;bottom:-40px;width:210px">
        <div class="tvf-dhero__tag" aria-hidden="true">Mỗi điểm đến là một câu chuyện đang chờ bạn</div>
        <div class="tvp-wrap tvf-dhero__in">
            <nav class="tvf-crumb" aria-label="breadcrumb"><a href="{{ route('home') }}">Trang chủ</a> › <span
                    aria-current="page">Điểm đến</span></nav>
            <span class="tvp-eyebrow">Khám phá Việt Nam</span>
            <h1 id="tvfDH1">Điểm đến</h1>
            <p class="tvf-dhero__sub">Những vùng đất tuyệt đẹp đang chờ bạn!</p>
            <p class="tvf-dhero__sub"><a href="{{ route('destinations.list') }}" style="color:inherit;text-decoration:underline">Xem danh sách có bộ lọc theo miền, tỉnh, tháng đẹp</a></p>
            <form class="tvf-search" id="tvpSearch" role="search" onsubmit="return false">
                <svg class="tvp-ic" viewBox="0 0 24 24" aria-hidden="true">
                    <circle cx="11" cy="11" r="7" />
                    <path d="M20 20l-4-4" />
                </svg>
                <input id="tvpQ" type="search" autocomplete="off" enterkeyhint="search"
                    placeholder="Tìm điểm đến, địa danh, thắng cảnh...  (nhấn / để tìm)" aria-label="Tìm điểm đến">
                <button type="submit" aria-label="Tìm"><svg class="tvp-ic" viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="11" cy="11" r="7" />
                        <path d="M20 20l-4-4" />
                    </svg></button>
            </form>
            @php
                // Gợi ý lấy từ dữ liệu thật: ưu tiên điểm đến đang có tour (trước đây gõ cứng Phú Yên, Hà Giang...)
                $allDest = collect($map['destinations'] ?? []);
                $withTours = $allDest->where('tourCount', '>', 0)->sortByDesc('tourCount');
                $quick = ($withTours->isNotEmpty() ? $withTours : $allDest)->take(4)->pluck('name');
            @endphp
            @if ($quick->isNotEmpty())
                <div class="tvf-quick" aria-label="Gợi ý tìm nhanh">
                    <span>{{ $withTours->isNotEmpty() ? 'Đang có tour:' : 'Gợi ý:' }}</span>
                    @foreach ($quick as $q)
                        <button type="button" data-q="{{ $q }}">{{ $q }}</button>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    @if ($hasData)
        {{-- ===== THANH LỌC THEO CẢM HỨNG ===== --}}
        <div class="tvf-sentinel" aria-hidden="true"></div>
        <div class="tvp-chipbar">
            <div class="tvp-wrap">
                <div class="tvp-chips" id="tvpChips" role="group" aria-label="Lọc điểm đến theo cảm hứng"></div>
            </div>
        </div>

        {{-- ===== BẢN ĐỒ CẢM XÚC ===== --}}
        <section class="tvp-mapsec" aria-labelledby="tvpMapH">
            <img class="tvf-deco tvf-leaf tvf-leaf--mapA" src="{{ $wc('wc-leaf-b.svg') }}" alt=""
                aria-hidden="true" width="560" height="640" loading="lazy">
            <img class="tvf-deco tvf-leaf tvf-leaf--mapB" src="{{ $wc('wc-leaf-a.svg') }}" alt=""
                aria-hidden="true" width="560" height="640" loading="lazy">
            <div class="tvp-wrap">
                <div class="tvp-mtabs" id="tvpTabs" role="group" aria-label="Chọn cảm hứng trên bản đồ"></div>
                <div class="tvp-mapgrid">
                    <div class="tvp-mapl">
                        <h2 id="tvpMapH"><span class="tvp-script">Khám phá</span>Việt Nam<br>qua bản đồ</h2>
                        <p class="tvp-mhead" id="tvpHead"></p>
                        <div class="tvp-mlit"><b id="tvpLit">0</b> điểm sáng theo cảm hứng bạn chọn</div>
                        <div class="tvp-mtop" id="tvpTop"></div>
                        <a class="tvp-btn tvp-btn--teal" id="tvpCta" href="{{ $map['exploreUrl'] }}">Xem các điểm
                            đến</a>
                    </div>
                    <div class="tvp-board" id="tvpBoard">
                        <div class="tvp-vnwrap" id="tvpVn">
                            <div class="tvp-pins" id="tvpPins"></div>
                        </div>
                        <div class="tvp-mapleg"><span><i style="background:#d9a441"></i>Hợp nhất</span><span><i
                                    style="background:#087f7b"></i>Phù hợp</span><span><i
                                    style="background:#9bb5b3"></i>Chưa sáng</span></div>
                        <div class="tvp-mpop" id="tvpPop" hidden></div>
                    </div>
                    <div class="tvp-regions">
                        <h3>Điểm đến theo vùng miền</h3>
                        <div id="tvpRegions"></div>
                    </div>
                </div>
            </div>
        </section>

        {{-- ===== ĐIỂM ĐẾN NỔI BẬT ===== --}}
        <div class="tvp-wrap">
            <div class="tvp-sech">
                <div>
                    <h2 id="tvpTitle">Điểm đến nổi bật</h2>
                    <p id="tvpCount" aria-live="polite"></p>
                </div>
                <button class="tvp-more" id="tvpReset" type="button" hidden>Xóa bộ lọc</button>
            </div>
            <div class="tvp-dgrid" id="tvpGrid" aria-busy="true">
                @for ($i = 0; $i < 8; $i++)
                    <div class="tvp-skel" aria-hidden="true"></div>
                @endfor
            </div>
            <noscript>
                <p class="tvp-empty ux-dest-empty">Trang này cần bật JavaScript để hiện danh sách điểm đến.
                    <a href="{{ route('tours') }}">Xem tất cả tour</a>
                </p>
            </noscript>
            <div class="tvp-loadmore"><button class="tvp-btn tvp-btn--ghost" id="tvpMore" type="button">Xem thêm
                    điểm
                    đến</button></div>
        </div>
    @else
        <div class="tvp-wrap">
            <div class="tvp-empty ux-dest-empty">
                <p>Danh sách điểm đến đang được cập nhật. Trong lúc chờ, bạn có thể xem toàn bộ tour hiện có.</p>
                <a class="tvp-btn tvp-btn--teal" href="{{ route('tours') }}">Xem tất cả tour</a>
                @if (app()->environment('local'))
                    <p class="ux-dev">Dev: chạy <code>php artisan migrate</code> và
                        <code>php artisan db:seed --class=DiscoverSeeder</code>.
                    </p>
                @endif
            </div>
        </div>
    @endif

    <div class="tvp-wrap">
        {{-- ===== BANNER ===== --}}
        <div class="tvf-banner tvf-rv">
            <div class="tvf-banner__bg"><img src="{{ $bannerSrc }}" alt="" width="1200"
                    height="420" loading="lazy"></div>
            <img class="tvf-deco tvf-leaf tvf-leaf--cta" src="{{ $wc('wc-leaf-c.svg') }}" alt=""
                aria-hidden="true" width="560" height="640" loading="lazy">
            <div>
                <h3>Những hành trình đang chờ bạn</h3>
                <p>Khám phá các tour trọn gói, lịch trình rõ ràng, giá minh bạch.</p>
            </div>
            <a class="tvp-btn tvp-btn--white" href="{{ route('tours') }}">Đặt tour ngay
                <svg class="tvp-ic" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M5 12h14M13 6l6 6-6 6" />
                </svg></a>
        </div>
    </div>

    {{-- ===== BÀI VIẾT LIÊN QUAN (config/blog.php) ===== --}}
    @if ($posts->count())
        <div class="tvp-sech">
            <div>
                <h2>Bài viết liên quan</h2>
            </div><a class="tvp-more" href="{{ route('blogs') }}">Xem tất cả</a>
        </div>
        <div class="tvp-posts">
            @foreach ($posts as $post)
                <a class="tvp-post tvp-rv" href="{{ route('blog-detail', ['slug' => $post['slug']]) }}">
                    <div class="tvp-post__img">
                        <img src="{{ asset($base . 'blog/' . $post['image']) }}" alt="" loading="lazy">
                        <span class="tvp-post__cat">{{ $post['category'] }}</span>
                    </div>
                    <div class="tvp-post__b">
                        <h3>{{ $post['title'] }}</h3>
                        <small>{{ \Carbon\Carbon::parse($post['date'])->format('d/m/Y') }} · {{ $post['read'] }}
                            phút đọc</small>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
    </div>
</main>

@if ($hasData)
    {{-- Dữ liệu cho bản đồ + bản đồ SVG (dùng chung partial với trang chủ) --}}
    <script type="application/json" id="tvpData">{!! json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
    <template id="tvpSvg">@include('clients.partials.vn-map-svg')</template>
    <script src="{{ asset('clients/assets/js/tv-destination.js') }}?v=2" defer></script>
@endif

@include('clients.blocks.footer')
