@include('clients.blocks.header')
@include('clients.blocks.banner')

@php
    $domainNames = ['b' => 'Miền Bắc', 't' => 'Miền Trung', 'n' => 'Miền Nam'];
    // Spotlight: chỉ hiện các miền đang có tour
    $spotDomains = collect($tours)->pluck('domain')->unique()->filter(fn($d) => isset($domainNames[$d]))->values();
@endphp

<section class="tv-dest">
    <div class="container">

        {{-- ===== SPOTLIGHT ĐIỂM ĐẾN (mới) — dữ liệu lấy từ $tours, JS nằm trong sea-theme.js ===== --}}
        @if ($spotDomains->count())
            <div class="tv-spot" id="tvSpot">
                <div class="tv-spot__bg"></div>
                <div class="tv-spot__in">
                    <div class="tv-spot__head">
                        <h2>Điểm đến được săn đón</h2>
                        <p>Chọn một vùng đất để xem hành trình đang mở bán.</p>
                    </div>

                    <div class="tv-spot__tabs" role="tablist" aria-label="Chọn miền">
                        @foreach ($spotDomains as $d)
                            <button type="button" class="tv-spot__tab"
                                data-spot-domain="{{ $d }}">{{ $domainNames[$d] }}</button>
                        @endforeach
                    </div>

                    <div class="tv-spot__stage">
                        <div class="tv-spot__text">
                            <p class="tv-spot__kicker" id="tvSpotKicker"></p>
                            <h3 class="tv-spot__title" id="tvSpotTitle"></h3>
                            <p class="tv-spot__meta" id="tvSpotMeta"></p>
                            <a href="#" class="tv-spot__cta" id="tvSpotCta">Xem tour <i
                                    class="fal fa-arrow-right"></i></a>
                        </div>

                        <div class="tv-spot__media">
                            <div class="tv-spot__img"><img id="tvSpotImg" src="" alt=""></div>
                            <div class="tv-spot__thumbs">
                                @foreach ($tours as $tour)
                                    @php
                                        $thumb = \App\Support\TourImage::url($tour->images[0] ?? 'default.jpg', 800);
                                    @endphp
                                    <button type="button" class="tv-spot__thumb" data-spot-thumb hidden
                                        data-domain="{{ $tour->domain }}"
                                        data-domain-name="{{ $domainNames[$tour->domain] ?? '' }}"
                                        data-title="{{ $tour->title }}"
                                        data-meta="{{ $tour->destination }} · {{ $tour->time }} · từ {{ number_format($tour->priceAdult, 0, ',', '.') }}đ/người"
                                        data-img="{{ $thumb }}"
                                        data-href="{{ route('tour-detail', ['id' => $tour->tourId]) }}"
                                        aria-label="{{ $tour->title }}">
                                        <img loading="lazy" decoding="async" src="{{ $thumb }}" alt="">
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif
        {{-- ===== HẾT SPOTLIGHT ===== --}}

        <div class="td-section__head">
            <div>
                <h2 class="td-h2" style="margin-bottom:6px">Điểm đến đang có tour</h2>
                <p class="tv-muted" style="margin:0">{{ count($tours) }} hành trình đang mở bán. Chọn miền để thu hẹp
                    danh
                    sách.</p>
            </div>
        </div>

        <div class="tv-chiprow" role="group" aria-label="Lọc theo miền" id="tvDomainFilter">
            <button type="button" class="tv-chipbtn is-on" data-domain="all">Tất cả</button>
            @foreach ($domainNames as $key => $label)
                <button type="button" class="tv-chipbtn"
                    data-domain="{{ $key }}">{{ $label }}</button>
            @endforeach
        </div>

        <div class="tv-dest__grid" id="tvDestGrid">
            @foreach ($tours as $tour)
                <a class="tv-dest__item" data-domain="{{ $tour->domain }}"
                    href="{{ route('tour-detail', ['id' => $tour->tourId]) }}">
                    <img loading="lazy" decoding="async"
                        src="{{ \App\Support\TourImage::url($tour->images[0] ?? 'default.jpg', 800) }}"
                        alt="{{ $tour->title }}">
                    <span class="tv-dest__label">
                        <small>{{ $domainNames[$tour->domain] ?? '' }} · {{ $tour->time }}</small>
                        <b>{{ $tour->title }}</b>
                    </span>
                </a>
            @endforeach
        </div>
        <p class="tv-muted tv-dest__none" id="tvDestNone" hidden>Chưa có điểm đến nào ở khu vực này.</p>
        <div class="tv-more"><button type="button" class="tv-btn-ghost" id="tvDestMore" hidden>Xem thêm điểm
                đến</button></div>
    </div>
</section>

@include('clients.blocks.new_letter')
@include('clients.blocks.footer')
