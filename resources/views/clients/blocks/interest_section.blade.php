{{-- "Khám phá theo cảm hứng" — mục lục cảm hứng + ảnh hero + bản đồ màu nước + điểm đến gợi ý.
     Dữ liệu: $inspirationMap (App\Services\InspirationService::mapData()).
     CSS: public/clients/assets/css/inspiration-hub.css   JS: public/clients/assets/js/inspiration-hub.js
     Không bật JS: danh sách vẫn là link tới /explore?inspiration=..., hero hiện cảm hứng đầu tiên. --}}
@php
    $tvhIns = $inspirationMap['inspirations'];
    $tvhDest = collect($inspirationMap['destinations']);
    $tvhExplore = $inspirationMap['exploreUrl'];

    // Điểm đến xếp theo điểm của một cảm hứng (dùng khi render phía máy chủ)
    $tvhRank = function (string $slug) use ($tvhDest) {
        return $tvhDest
            ->filter(fn($d) => ($d['scores'][$slug] ?? 0) > 0)
            ->sortByDesc(fn($d) => $d['scores'][$slug])
            ->values();
    };
@endphp

@if (count($tvhIns))
    @php
        $tvhFirst = $tvhIns[0];
        $tvhFirstRank = $tvhRank($tvhFirst['slug']);
    @endphp
    <section class="tvh" id="kham-pha" aria-labelledby="tvhTitle">
        @include('clients.partials.inspiration-motifs')

        <div class="container">
            <p class="tvh-sr" id="tvhLive" aria-live="polite"></p>

            {{-- Tiêu đề + tranh "Việt Nam đẹp lắm!" --}}
            <header class="tvh-head" id="tvhChon">
                <div class="tvh-head__text" data-aos="fade-up" data-aos-duration="800">
                    <p class="tvh-eyebrow">
                        <<i class="fas fa-water" aria-hidden="true"></i> Khám phá theo cảm hứng
                    </p>
                    <h2 id="tvhTitle">Bạn đang muốn một chuyến đi như thế nào?</h2>
                    <p class="tvh-lead">Mỗi người đều có một lý do để đi. Chọn cảm hứng của bạn, chúng tôi sẽ gợi ý
                        những
                        điểm đến phù hợp nhất. <span class="tvh-nowrap">Rê chuột để xem nhanh, bấm để chọn.</span></p>
                </div>
                <div class="tvh-head__art" aria-hidden="true">
                    @include('clients.partials.vn-watercolor-banner')
                </div>
            </header>

            <div class="tvh-hub" id="tvhHub" data-ins="{{ $tvhFirst['slug'] }}">
                <div class="tvh-stage">

                    {{-- Mục lục 8 cảm hứng --}}
                    <nav class="tvh-toc" id="tvhToc" aria-label="Chọn cảm hứng (dùng phím mũi tên lên xuống)">
                        @foreach ($tvhIns as $i => $it)
                            <a href="{{ route('explore', ['inspiration' => $it['slug']]) }}"
                                class="tvh-row{{ $loop->first ? ' is-selected is-open' : '' }}"
                                data-slug="{{ $it['slug'] }}" data-n="{{ $i }}"
                                @if ($loop->first) aria-current="true" @endif
                                aria-label="{{ $it['name'] }}, {{ $it['tagline'] }}, {{ $it['count'] }} điểm đến">
                                <svg class="tvh-row__scene" viewBox="0 0 320 170" preserveAspectRatio="xMidYMin slice"
                                    aria-hidden="true" focusable="false">
                                    <use href="#tvh-m-{{ $it['slug'] }}" />
                                </svg>
                                <span class="tvh-row__bar">
                                    <span class="tvh-row__line">
                                        <span class="tvh-row__num">{{ sprintf('%02d', $i + 1) }}</span>
                                        <span class="tvh-row__chip" aria-hidden="true"><i
                                                class="fas {{ $it['icon'] }}"></i></span>
                                        <strong class="tvh-row__name">{{ $it['name'] }}</strong>
                                        <span class="tvh-row__slot" aria-hidden="true">
                                            <span class="tvh-row__count">{{ $it['count'] }} nơi</span>
                                            <span class="tvh-row__pick">Chọn</span>
                                            <span class="tvh-row__check"><i class="fas fa-check"></i></span>
                                        </span>
                                    </span>
                                    <span class="tvh-row__more" aria-hidden="true"
                                        data-count="{{ $it['count'] }} nơi">
                                        <span class="tvh-row__tag">{{ $it['tagline'] }}</span>
                                        <span class="tvh-row__top">Hợp nhất:
                                            {{ $tvhRank($it['slug'])->take(3)->pluck('name')->implode(' · ') }}</span>
                                    </span>
                                </span>
                            </a>
                        @endforeach
                    </nav>

                    {{-- Ảnh hero của cảm hứng đang chọn --}}
                    <article class="tvh-hero" id="tvhHero" aria-labelledby="tvhHeroTitle">
                        <img class="tvh-hero__img" id="tvhHeroImg" alt="" loading="lazy" decoding="async">
                        <svg class="tvh-hero__scene" viewBox="0 0 800 480" preserveAspectRatio="xMidYMax slice"
                            aria-hidden="true" focusable="false">
                            <circle cx="610" cy="92" r="42" fill="#FBE7A8" />
                            <path class="tvh-fa" id="tvhHeroP1"
                                d="M0 176 Q100 150 200 176 T400 176 T600 176 T800 176 V480 H0Z" opacity="0.7" />
                            <path class="tvh-fb" id="tvhHeroP2"
                                d="M0 206 Q100 184 200 206 T400 206 T600 206 T800 206 V480 H0Z" />
                        </svg>
                        <p class="tvh-hero__quote" id="tvhQuote">{{ $tvhFirst['quote'] }}</p>

                        {{-- Bản đồ mini: sáng chấm theo cảm hứng đang rê/chọn, bấm để xuống bản đồ lớn --}}
                        <a href="#tvhBanDo" class="tvh-mini" id="tvhMini">
                            <span class="tvh-mini__map" aria-hidden="true">
                                <svg viewBox="0 0 800 952.8" focusable="false">
                                    <use href="#vnwc-vn" class="tvh-mini__vn" />
                                </svg>
                                <span class="tvh-mini__dots" id="tvhMiniDots"></span>
                            </span>
                            <span class="tvh-mini__name" id="tvhMiniName">{{ $tvhFirst['name'] }}</span>
                            <span class="tvh-mini__count"><span id="tvhMiniCount">{{ $tvhFirstRank->count() }}</span>
                                nơi
                                sáng</span>
                            <span class="tvh-mini__go">Xem bản đồ <i class="fas fa-arrow-down"
                                    aria-hidden="true"></i></span>
                        </a>

                        <div class="tvh-hero__body">
                            <div class="tvh-hero__copy">
                                <span class="tvh-badge"><i class="fas {{ $tvhFirst['icon'] }}" id="tvhBadgeIcon"
                                        aria-hidden="true"></i> Cảm hứng nổi bật</span>
                                <h3 id="tvhHeroTitle">{{ $tvhFirst['name'] }}</h3>
                                <p class="tvh-hero__tag" id="tvhHeroTag">{{ $tvhFirst['tagline'] }}</p>
                                <p class="tvh-hero__desc" id="tvhHeroDesc">{{ $tvhFirst['headline'] }}</p>
                                <a href="#tvhBanDo" class="tvh-cta" id="tvhCta"><span id="tvhCtaText">Xem
                                        {{ $tvhFirstRank->count() }} nơi trên bản đồ</span> <i
                                        class="fas fa-arrow-down" aria-hidden="true"></i></a>
                            </div>
                            <div class="tvh-pager">
                                <span id="tvhPager">01 / {{ sprintf('%02d', count($tvhIns)) }}</span>
                                <button type="button" id="tvhPrev" aria-label="Cảm hứng trước"><i
                                        class="fas fa-arrow-left" aria-hidden="true"></i></button>
                                <button type="button" id="tvhNext" class="is-solid"
                                    aria-label="Cảm hứng tiếp theo"><i class="fas fa-arrow-right"
                                        aria-hidden="true"></i></button>
                            </div>
                        </div>
                    </article>

                    {{-- 4 điểm đến hợp nhất (JS vẽ; chưa chạy JS thì hiện cảm hứng đầu tiên) --}}
                    <div class="tvh-thumbs" id="tvhThumbs">
                        @foreach ($tvhFirstRank->take(4) as $d)
                            <a class="tvh-thumb{{ $d['image'] ? ' has-photo' : '' }}" href="#tvhBanDo">
                                @if ($d['image'])
                                    <img src="{{ $d['image'] }}" alt="" loading="lazy" decoding="async">
                                @endif
                                <span class="tvh-thumb__pct">{{ $d['scores'][$tvhFirst['slug']] }}%</span>
                                <span class="tvh-thumb__name">{{ $d['name'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>

                {{-- Tầng bản đồ --}}
                <div class="tvh-map" id="tvhBanDo">
                    <svg class="tvh-map__hills" viewBox="0 0 1200 200" preserveAspectRatio="xMidYMax slice"
                        aria-hidden="true" focusable="false">
                        <path class="tvh-fa" opacity="0.14"
                            d="M0 150 L120 70 L210 130 L330 40 L450 140 L560 90 L680 150 L800 60 L930 140 L1050 80 L1200 150 V200 H0Z" />
                        <path class="tvh-fa" opacity="0.18"
                            d="M0 180 L160 120 L280 170 L420 110 L560 175 L720 120 L880 175 L1040 125 L1200 175 V200 H0Z" />
                    </svg>

                    <div class="tvh-map__inner">
                        <div class="tvh-map__intro">
                            <h3>Những nơi hợp với cảm hứng này</h3>
                            <p>Dựa trên cảm hứng “<span id="tvhMapIns">{{ $tvhFirst['name'] }}</span>”, chúng tôi đã
                                chọn ra
                                những điểm đến hợp nhất cho bạn.</p>
                            <div class="tvh-now">
                                <div class="tvh-now__row">
                                    <span class="tvh-now__icon"><i class="fas {{ $tvhFirst['icon'] }}"
                                            id="tvhNowIcon" aria-hidden="true"></i></span>
                                    <div><strong id="tvhNowName">{{ $tvhFirst['name'] }}</strong><span
                                            id="tvhNowTag">{{ $tvhFirst['tagline'] }}</span></div>
                                </div>
                                <a href="#tvhChon" class="tvh-now__change">Thay đổi cảm hứng</a>
                            </div>
                            <div class="tvh-doms" role="group" aria-label="Lọc theo miền" id="tvhDoms">
                                <button type="button" data-dom="all" aria-pressed="true">Tất cả</button>
                                <button type="button" data-dom="b" aria-pressed="false">Bắc</button>
                                <button type="button" data-dom="t" aria-pressed="false">Trung</button>
                                <button type="button" data-dom="n" aria-pressed="false">Nam</button>
                            </div>
                        </div>

                        <div class="tvh-map__col">
                            <div class="tvh-board" id="tvhBoard">
                                @include('clients.partials.vn-watercolor-map')
                                <div class="tvh-pins" id="tvhPins" aria-hidden="true"></div>
                                <div class="tvh-labels" id="tvhLabels" aria-hidden="true"></div>
                            </div>
                            <p class="tvh-legend"><span class="tvh-legend__dot"></span><span>Chấm càng lớn, càng hợp ·
                                    <strong id="tvhLit">0</strong> điểm sáng</span></p>
                            <p class="tvh-legend__note">% phù hợp là điểm do đội ngũ Travela đánh giá cho từng cảm
                                hứng.</p>
                        </div>

                        <div class="tvh-map__side">
                            <h3>Gợi ý cho bạn</h3>
                            <p class="tvh-sub" id="tvhBandSub"></p>
                            <div class="tvh-band" id="tvhBand"></div>

                            <div class="tvh-detail" id="tvhDetail">
                                <p class="tvh-detail__where"><i class="fas fa-map-marker-alt" aria-hidden="true"></i>
                                    <span id="tvhDetailWhere"></span>
                                </p>
                                <h4 id="tvhDetailName"></h4>
                                <p class="tvh-detail__desc" id="tvhDetailDesc"></p>
                                <div id="tvhDetailWx" hidden></div>
                                <a href="#" class="tvh-btn is-ghost" id="tvhDetailMore">Xem chi tiết điểm
                                    đến</a>
                                <a href="{{ route('tours') }}" class="tvh-btn" id="tvhDetailCta">Xem tour</a>
                                <p class="tvh-detail__soon" id="tvhDetailSoon" hidden></p>
                            </div>

                            <div class="tvh-more">
                                <p>Bạn muốn khám phá nhiều hơn?</p>
                                <a href="{{ route('explore', ['inspiration' => $tvhFirst['slug']]) }}"
                                    id="tvhAll">
                                    <span id="tvhAllText">Xem tất cả {{ $tvhFirstRank->count() }} điểm đến</span> <i
                                        class="fas fa-arrow-right" aria-hidden="true"></i></a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script type="application/json" id="tvhData">@json($inspirationMap)</script>
        <script src="{{ asset('clients/assets/js/inspiration-hub.js') }}?v=1" defer></script>
    </section>
@endif
