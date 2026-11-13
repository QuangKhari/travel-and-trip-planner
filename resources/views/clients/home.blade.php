@include('clients.blocks.header_home')
{{--
  Trang chủ — "Bắt đầu từ cảm hứng, đi trọn hành trình". CSS: tv3.css + tv3-home.css, JS: tv-home.js.
  Thứ tự: 1 Hero (hai lối vào) → 2 Bản đồ cảm hứng → 3 Tháng N nên đi đâu → 4 Tour sắp khởi hành
          → 5 Đến nơi, sống như người địa phương (Chơi gì · Ăn gì · Lễ hội) → 6 Kể một câu, nhận trọn lịch trình
          → 7 Niềm tin + Cẩm nang → Footer.
  Cả trang đổi theo cảm hứng đang chọn ở khối 2 (sự kiện "tv:inspiration"): màu nhấn, nhãn "Ưu tiên cho cảm hứng",
  thứ tự nơi ở khối 3 và tour ở khối 4. Khối nào chưa có dữ liệu thì tự ẩn.
--}}
@php
    $hDest = collect($inspirationMap['destinations'] ?? [])->keyBy('slug');
    $homeTours = collect($tours);
    $season = array_slice($feed['seasonMix'] ?? [], 0, 5);
    // khối 5 "Đến nơi, sống như người địa phương": tối đa 6 lát mỗi tab, ưu tiên mục có ảnh
    $withImg = fn($items) => collect($items)->sortBy(fn($c) => empty($c->image) ? 1 : 0)->take(6)->values()->all();
    $foods = $withImg($feed['foods'] ?? []);
    $exps = $withImg($feed['experiences'] ?? []);
    $events = array_slice($feed['events'] ?? [], 0, 6); // lễ hội giữ thứ tự ngày diễn ra
    $reads = array_slice(!empty($feed['guides']) ? $feed['guides'] : $feed['itins'] ?? [], 0, 3);
    $readsAreGuides = !empty($feed['guides']);
    $kindIcon = [
        'destination' => 'fa-map-marker-alt',
        'event' => 'fa-calendar-star',
        'food' => 'fa-utensils',
        'experience' => 'fa-hiking',
        'itinerary' => 'fa-route',
        'guide' => 'fa-book-open',
    ];
    $firstIns = $inspirationMap['inspirations'][0] ?? null;

    // Khối 7: số liệu thật, một đánh giá thật (không có thì không hiện)
    $trust = ['tours' => 0, 'completed' => 0, 'reviews' => 0, 'rating' => 0.0];
    $review = null;
    try {
        $trust['tours'] = (int) DB::table('tbl_tours')->where('availability', 1)->count();
        $trust['completed'] = (int) DB::table('tbl_booking')->where('bookingStatus', 'f')->count();
        $trust['reviews'] = (int) \App\Support\ReviewScope::visible(DB::table('tbl_reviews'))->count();
        $trust['rating'] = round((float) \App\Support\ReviewScope::visible(DB::table('tbl_reviews'))->avg('rating'), 1);
        $review = DB::table('tbl_reviews as r')
            ->join('tbl_users as u', 'u.userId', '=', 'r.userId')
            ->when(\App\Support\ReviewScope::enabled(), fn($q) => $q->where('r.isHidden', 0))
            ->whereNotNull('r.comment')
            ->where('r.rating', '>=', 4)
            ->orderByDesc('r.rating')
            ->orderByDesc('r.timestamp')
            ->limit(20)
            ->select('r.comment', 'r.rating', 'u.fullName')
            ->get()
            ->first(fn($r) => mb_strlen(trim($r->comment)) >= 20);
    } catch (\Throwable $e) {
        report($e);
    }
    $siteStats = app(\App\Services\Content\SiteStats::class)->get();
@endphp

<main class="v3 h3" id="hxMain">
    {{-- ===== 1. HERO ===== --}}
    @include('clients.blocks.home_hero')

    {{-- ===== 2. BẢN ĐỒ CẢM HỨNG ===== --}}
    @include('clients.blocks.interest_section')

    @php
        $forIns = $firstIns
            ? '<span class="hx-for"><i class="fas ' . e($firstIns['icon']) . ' hx-insicon" aria-hidden="true"></i> Ưu tiên cho cảm hứng <b class="hx-insname">' . e($firstIns['name']) . '</b></span>'
            : '';
    @endphp

    {{-- ===== 3. THÁNG NÀY NÊN ĐI ĐÂU ===== --}}
    @if (count($season))
        <section class="v3-sec" id="dang-vao-mua" aria-labelledby="h3-season">
            <div class="v3-wrap">
                <div class="v3-head v3-rv">
                    <div class="v3-head__t">
                        {!! $forIns !!}
                        <h2 id="h3-season">Tháng {{ $month }} nên đi đâu?</h2>
                        <p>Nơi đang vào mùa đẹp và lễ hội sắp diễn ra.</p>
                    </div>
                    <a class="v3-more" href="{{ route('seasons.matrix') }}">Xem đủ 12 tháng</a>
                </div>
                <div class="h3-season" id="hxSeason">
                    @foreach ($season as $i => $c)
                        @php $wx = $c->kind === 'destination' ? $hDest[$c->slug]['weather'] ?? null : null; @endphp
                        <a class="h3-tile v3-rv {{ $i === 0 ? 'is-big' : '' }}" style="--d: {{ $i }}"
                            href="{{ $c->url }}" data-type="{{ $c->kind }}" data-slug="{{ $c->slug }}">
                            @if ($c->image)
                                <img src="{{ $c->image }}" alt="" loading="lazy">
                            @else
                                <span class="h3-tile__ph" aria-hidden="true"><i
                                        class="fal {{ $kindIcon[$c->kind] ?? 'fa-map-marker-alt' }}"></i></span>
                            @endif
                            @if ($wx && $wx['temp'] !== null)
                                <span class="hx-wx" title="Thời tiết hiện tại: {{ $wx['label'] }}"><i
                                        class="fas {{ $wx['icon'] }}" aria-hidden="true"></i>
                                    {{ round($wx['temp']) }}°</span>
                            @endif
                            <span class="h3-tile__in">
                                @if (!empty($c->chip))
                                    <span class="h3-chip">{{ $c->chip }}</span>
                                @endif
                                <b>{{ $c->title }}</b>
                                <small>{{ $c->meta }}</small>
                                @if (!empty($c->text))
                                    <span class="h3-tile__txt">{{ $c->text }}</span>
                                @endif
                            </span>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- ===== 4. TOUR SẮP KHỞI HÀNH (4 tour hiện; tour hợp cảm hứng đang chọn được đưa lên trước) ===== --}}
    @if ($homeTours->isNotEmpty())
        <section class="v3-sec" id="tour-sap-khoi-hanh" aria-labelledby="h3-tours">
            <div class="v3-wrap">
                <div class="v3-head v3-rv">
                    <div class="v3-head__t">
                        {!! $forIns !!}
                        <h2 id="h3-tours">Tour sắp khởi hành</h2>
                        <p>Giá và số chỗ còn lại cập nhật trực tiếp, đặt và thanh toán ngay.</p>
                    </div>
                    <a class="v3-more" href="{{ route('tours') }}">Xem tất cả tour</a>
                </div>
                <div class="trc-grid h3-tours" id="hxTours">
                    @foreach ($homeTours as $i => $tour)
                        <div class="hx-tour" data-dest="{{ $tour->destSlug ?? '' }}" data-n="{{ $i }}"
                            @if ($i >= 4) hidden @endif>
                            @include('clients.partials.tour-card', ['tour' => $tour])
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- ===== 5. ĐẾN NƠI, SỐNG NHƯ NGƯỜI ĐỊA PHƯƠNG ===== --}}
    @php
        $xTabs = array_values(
            array_filter(
                [
                    ['exp', 'Chơi gì', $exps, route('experiences.index'), 'Tất cả trải nghiệm', 'fa-hiking'],
                    ['food', 'Ăn gì', $foods, route('foods.index'), 'Tất cả món ăn', 'fa-utensils'],
                    ['evt', 'Lễ hội sắp tới', $events, route('events.index'), 'Tất cả lễ hội', 'fa-calendar-star'],
                ],
                fn($t) => count($t[2]) > 0,
            ),
        );
    @endphp
    @if (count($xTabs))
        <section class="v3-sec" id="trai-nghiem-le-hoi" aria-labelledby="h3-more">
            <div class="v3-wrap">
                <div class="v3-head v3-rv">
                    <div class="v3-head__t">
                        <h2 id="h3-more">Đến nơi, sống như người địa phương</h2>
                        <p>Việc đáng làm, món nên thử và lễ hội sắp diễn ra.</p>
                    </div>
                    @if (count($xTabs) > 1)
                        <div class="h3-xtabs" role="tablist" aria-label="Chơi gì, ăn gì, lễ hội">
                            @foreach ($xTabs as $i => $t)
                                <button type="button" role="tab" id="h3x-tab-{{ $t[0] }}"
                                    aria-controls="h3x-{{ $t[0] }}"
                                    aria-selected="{{ $i === 0 ? 'true' : 'false' }}"
                                    @if ($i) tabindex="-1" @endif><i class="fal {{ $t[5] }}"
                                        aria-hidden="true"></i>{{ $t[1] }}</button>
                            @endforeach
                        </div>
                    @endif
                </div>
                @foreach ($xTabs as $i => $t)
                    <div class="h3-xpane" id="h3x-{{ $t[0] }}" role="tabpanel"
                        aria-labelledby="h3x-tab-{{ $t[0] }}" @if ($i) hidden @endif>
                        {{-- Dải ảnh mở rộng (như "Món ngon nên thử" bản cũ): rê chuột/focus vào lát nào thì lát đó nở ra --}}
                        <ul class="lx" data-lx>
                            @foreach ($t[2] as $j => $c)
                                @php
                                    $chip = $t[0] === 'evt' ? $c->meta ?? null : $c->chip ?? null;
                                    if (!$chip && $t[0] === 'food' && !empty($c->must)) {
                                        $chip = 'Phải thử';
                                    }
                                    $place = $t[0] === 'evt' ? $c->badge ?? null : $c->meta ?? null;
                                @endphp
                                <li class="lx-item{{ $j === 0 ? ' is-on' : '' }}">
                                    <a class="lx-link" href="{{ $c->url }}">
                                        @if ($c->image)
                                            <img src="{{ $c->image }}" alt="" loading="lazy" decoding="async">
                                        @else
                                            <span class="lx-ph" aria-hidden="true"><i class="fal {{ $t[5] }}"></i></span>
                                        @endif
                                        <span class="lx-n" aria-hidden="true">{{ sprintf('%02d', $j + 1) }}</span>
                                        <span class="lx-v" aria-hidden="true">{{ $c->title }}</span>
                                        <span class="lx-cap">
                                            @if ($chip)
                                                <span class="h3-chip">{{ $chip }}</span>
                                            @endif
                                            <b>{{ $c->title }}</b>
                                            @if ($place)
                                                <small><i class="fas fa-map-marker-alt" aria-hidden="true"></i>
                                                    {{ $place }}</small>
                                            @endif
                                            @if (!empty($c->text))
                                                <span class="lx-txt">{{ $c->text }}</span>
                                            @endif
                                            <span class="lx-go" aria-hidden="true">Xem chi tiết <i
                                                    class="fal fa-arrow-right"></i></span>
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                        <a class="v3-more h3-xmore" href="{{ $t[3] }}">{{ $t[4] }}</a>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- ===== 6. KỂ MỘT CÂU, NHẬN TRỌN LỊCH TRÌNH (giới thiệu Trip Planner bằng một ví dụ) ===== --}}
    @php
        $exImg = fn($slug) => $hDest[$slug]['image'] ?? null;
        $exStops = [
            ['Sáng', 'da-nang', 'fa-umbrella-beach', 'Biển Mỹ Khê', 'Tắm biển, cà phê ven biển'],
            ['Trưa', null, 'fa-utensils', 'Mì Quảng', 'Món nên thử của xứ Quảng'],
            ['Chiều', 'hoi-an', 'fa-landmark', 'Phố cổ Hội An', 'Đi bộ qua chùa Cầu, nhà cổ'],
            ['Tối', null, 'fa-star', 'Thả đèn hoa đăng', 'Trên sông Hoài lúc lên đèn'],
        ];
    @endphp
    <section class="v3-sec hx-ai6" aria-labelledby="hx-ai-title">
        <div class="v3-wrap hx-ai6__in">
            <div class="hx-ai6__text v3-rv">
                <p class="v3-eyebrow">Trợ lý lên kế hoạch</p>
                <h2 id="hx-ai-title">Kể một câu, nhận trọn lịch trình</h2>
                <p class="hx-ai6__lead">Không cần mở chục tab. Mô tả chuyến đi bằng lời của bạn,
                    {{ config('site.name') }} sắp xếp từng ngày theo mùa và thời tiết, rồi gắn luôn tour thật để bạn
                    đặt.</p>
                <ul class="hx-ai6__pts">
                    <li><i class="fas fa-cloud-sun" aria-hidden="true"></i><span><b>Hợp mùa, hợp thời tiết</b>Tránh
                            mùa mưa, đúng mùa đẹp, đúng ngày lễ hội.</span></li>
                    <li><i class="fas fa-ticket-alt" aria-hidden="true"></i><span><b>Gắn tour đặt được ngay</b>Giá,
                            ngày đi và số chỗ thật, thanh toán qua VNPay, MoMo hoặc QR ngân hàng.</span></li>
                    <li><i class="fas fa-share-alt" aria-hidden="true"></i><span><b>Chỉnh sửa và chia sẻ</b>Thêm, bớt
                            điểm dừng, gửi link cho bạn đồng hành.</span></li>
                </ul>
                <a class="v3-btn v3-btn--teal" href="{{ route('planner') }}">Tạo lịch trình của bạn <i
                        class="fal fa-arrow-right" aria-hidden="true"></i></a>
            </div>
            <figure class="hx-app v3-rv" style="--d: 1">
                <div class="hx-app__bar" aria-hidden="true"><span></span><span></span><span></span>
                    <figcaption>Ví dụ một lịch trình</figcaption>
                </div>
                <p class="hx-bubble">3 ngày Đà Nẵng – Hội An cho 2 người, thích ăn ngon</p>
                <div class="hx-plan">
                    <div class="hx-plan__hd">
                        <div><b>Đà Nẵng · Hội An</b><small>3 ngày 2 đêm · 2 người</small></div>
                        <span class="hx-plan__tag"><i class="fas fa-magic" aria-hidden="true"></i> Gợi ý</span>
                    </div>
                    <div class="hx-days" aria-hidden="true"><span class="is-on">Ngày 1</span><span>Ngày
                            2</span><span>Ngày 3</span></div>
                    <ol class="hx-tl">
                        @foreach ($exStops as [$when, $slug, $ic, $name, $note])
                            <li><time>{{ $when }}</time>
                                @if ($slug && $exImg($slug))
                                    <img src="{{ $exImg($slug) }}" alt="" loading="lazy">
                                @else
                                    <span class="hx-tl__ic"><i class="fas {{ $ic }}"
                                            aria-hidden="true"></i></span>
                                @endif
                                <span><b>{{ $name }}</b><small>{{ $note }}</small></span>
                            </li>
                        @endforeach
                    </ol>
                    <a class="hx-match" href="{{ route('planner') }}"><i class="fas fa-ticket-alt"
                            aria-hidden="true"></i><span><b>Kèm tour phù hợp lịch trình</b><small>Giá và số chỗ
                                lấy trực tiếp từ hệ thống</small></span><i class="fal fa-arrow-right"
                            aria-hidden="true"></i></a>
                </div>
            </figure>
        </div>
    </section>

    {{-- ===== 7. NIỀM TIN + CẨM NANG ===== --}}
    <section class="v3-sec hx-trust" aria-labelledby="hx-trust-title">
        <div class="v3-wrap">
            <h2 id="hx-trust-title" class="sr-only">Vì sao chọn {{ config('site.name') }}</h2>
            <div class="hx-trust__grid{{ $review ? '' : ' is-two' }}">
                <div class="hx-stats v3-rv">
                    @if ($trust['tours'] > 0)
                        <div><b>{{ number_format($trust['tours']) }}</b><span>tour đang mở bán</span></div>
                    @endif
                    @if ($trust['completed'] > 0)
                        <div><b>{{ number_format($trust['completed']) }}</b><span>chuyến đi đã hoàn thành</span>
                        </div>
                    @endif
                    @if ($trust['reviews'] > 0)
                        <div><b>{{ number_format($trust['rating'], 1) }}<i class="fas fa-star"
                                    aria-hidden="true"></i></b><span>{{ number_format($trust['reviews']) }} đánh giá
                                của khách</span></div>
                    @endif
                    @foreach (array_slice($siteStats, 0, 4 - min(3, ($trust['tours'] > 0) + ($trust['completed'] > 0) + ($trust['reviews'] > 0)), true) as $label => $n)
                        <div><b>{{ number_format($n) }}</b><span>{{ mb_strtolower($label) }} trong cẩm nang</span>
                        </div>
                    @endforeach
                </div>
                @if ($review)
                    <figure class="hx-quote v3-rv" style="--d: 1">
                        <i class="fas fa-quote-left" aria-hidden="true"></i>
                        <blockquote>{{ \Illuminate\Support\Str::limit(trim($review->comment), 220) }}</blockquote>
                        <figcaption><span
                                aria-hidden="true">{{ mb_substr(trim($review->fullName ?: 'K'), 0, 1) }}</span><b>{{ $review->fullName ?: 'Khách hàng' }}</b>
                            · đã đi tour cùng {{ config('site.name') }}</figcaption>
                    </figure>
                @endif
                <ul class="hx-promise v3-rv" style="--d: 2">
                    <li><i class="fas fa-shield-check" aria-hidden="true"></i><span><b>Thanh toán an toàn</b>Qua
                            VNPay, MoMo, QR ngân hàng, xác nhận đặt chỗ ngay</span></li>
                    <li><i class="fas fa-sync" aria-hidden="true"></i><span><b>Giá và chỗ thật</b>Số chỗ còn lại
                            cập nhật trực tiếp</span></li>
                    <li><i class="fas fa-headset" aria-hidden="true"></i><span><b>Hỗ trợ suốt chuyến đi</b>Nhắn tư
                            vấn viên bất cứ lúc nào</span></li>
                </ul>
            </div>

            @if (count($reads))
                <div class="v3-head v3-rv hx-reads-hd"
                    id="{{ $readsAreGuides ? 'cam-nang-moi' : 'lich-trinh-goi-y' }}">
                    <div class="v3-head__t">
                        <h2 id="h3-read">{{ $readsAreGuides ? 'Cẩm nang trước khi đi' : 'Lịch trình gợi ý' }}</h2>
                        <p>{{ $readsAreGuides ? 'Kinh nghiệm thật, đọc trong vài phút.' : 'Đi mấy ngày, đi những đâu.' }}
                        </p>
                    </div>
                    <a class="v3-more"
                        href="{{ $readsAreGuides ? route('guides.index') : route('itineraries.index') }}">Xem tất
                        cả</a>
                </div>
                <div class="h3-reads">
                    @foreach ($reads as $i => $c)
                        <a class="h3-read v3-rv" style="--d: {{ $i }}" href="{{ $c->url }}">
                            @if ($c->image)
                                <img src="{{ $c->image }}" alt="" loading="lazy">
                            @else
                                <span class="h3-read__ph" aria-hidden="true"><i class="fal fa-route"></i></span>
                            @endif
                            <span>
                                <span class="h3-chip h3-chip--soft">{{ $c->badge }}</span>
                                <b>{{ $c->title }}</b>
                                <small>{{ $c->meta }}</small>
                            </span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    <div class="h3-gap" aria-hidden="true"></div>
</main>

@include('clients.blocks.footer_home')
