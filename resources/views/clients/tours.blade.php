@include('clients.blocks.header')

@php
    $quickKeywords = ['Biển', 'Núi', 'Văn hóa', 'Ẩm thực', 'Nghỉ dưỡng', 'Khám phá'];
@endphp

<!-- Banner + ô tìm kiếm -->
<section class="tv-banner tv-banner--tours">
    <div class="tv-banner__bg" style="background-image:url('{{ asset('clients/assets/images/banner/banner.jpg') }}');">
    </div>
    <div class="container">
        <nav class="tv-crumb" aria-label="breadcrumb">
            <a href="{{ route('home') }}">Trang chủ</a><i class="fas fa-chevron-right"></i><span>Tours</span>
        </nav>
        <h1 class="tv-banner__title">Tìm hành trình dành cho bạn</h1>
        <p class="tv-banner__sub">Lọc theo miền, ngân sách, thời gian và đánh giá – kết quả cập nhật ngay, không cần tải
            lại trang.</p>

        <form class="tv-tsearch" id="tvSearchForm" role="search" autocomplete="off">
            <i class="fal fa-search"></i>
            <input type="text" id="tvKeyword" name="keyword" maxlength="100" value="{{ $state['keyword'] }}"
                placeholder="Bạn muốn đi đâu? Ví dụ: Đà Nẵng, Phú Quốc, biển..." aria-label="Tìm tour">
            <button type="button" class="tv-tsearch__clear" id="tvKeywordClear" aria-label="Xóa từ khóa" hidden><i
                    class="fal fa-times"></i></button>
            <button type="submit" class="tv-btn-orange">Tìm kiếm</button>
        </form>
        <div class="tv-qchips" aria-label="Gợi ý nhanh">
            @foreach ($quickKeywords as $kw)
                <button type="button" class="tv-qchip" data-kw="{{ mb_strtolower($kw) }}">{{ $kw }}</button>
            @endforeach
        </div>
    </div>
    <svg class="tv-wave" viewBox="0 0 1440 100" preserveAspectRatio="none" aria-hidden="true">
        <path d="M0 45 Q180 100 380 58 T760 62 T1130 40 T1440 68 V100 H0Z" />
    </svg>
</section>

<!-- Danh sách tour -->
<section class="tv-tours">
    <div class="container">
        <div class="tv-tours__layout">

            <aside class="tv-filter" id="tvFilter" aria-label="Bộ lọc tour">
                <div class="tv-filter__head">
                    <h2>Bộ lọc</h2>
                    <button type="button" class="tv-filter__close" data-action="close-filter"
                        aria-label="Đóng bộ lọc"><i class="fal fa-times"></i></button>
                </div>

                <div class="tv-fgroup">
                    <h4>Điểm đến</h4>
                    <div class="tv-fchips">
                        <button type="button" class="tv-chipbtn" data-filter="domain" data-value="b">Miền Bắc
                            <span>{{ $domainsCount['b'] }}</span></button>
                        <button type="button" class="tv-chipbtn" data-filter="domain" data-value="t">Miền Trung
                            <span>{{ $domainsCount['t'] }}</span></button>
                        <button type="button" class="tv-chipbtn" data-filter="domain" data-value="n">Miền Nam
                            <span>{{ $domainsCount['n'] }}</span></button>
                    </div>
                </div>

                <div class="tv-fgroup">
                    <h4>Khoảng giá <small>/ người</small></h4>
                    <div class="tv-range" id="tvRange">
                        <div class="tv-range__track">
                            <div class="tv-range__fill" id="tvRangeFill"></div>
                        </div>
                        <input type="range" id="tvMin" min="{{ $minBound }}" max="{{ $maxBound }}"
                            step="100000" value="{{ $state['min'] }}" aria-label="Giá thấp nhất">
                        <input type="range" id="tvMax" min="{{ $minBound }}" max="{{ $maxBound }}"
                            step="100000" value="{{ $state['max'] }}" aria-label="Giá cao nhất">
                    </div>
                    <div class="tv-range__label"><span id="tvMinLabel"></span><span id="tvMaxLabel"></span></div>
                </div>

                <div class="tv-fgroup">
                    <h4>Thời gian</h4>
                    <div class="tv-fchips">
                        <button type="button" class="tv-chipbtn" data-filter="days" data-value="short">1–3
                            ngày</button>
                        <button type="button" class="tv-chipbtn" data-filter="days" data-value="mid">4–5 ngày</button>
                        <button type="button" class="tv-chipbtn" data-filter="days" data-value="long">6 ngày trở
                            lên</button>
                    </div>
                </div>

                <div class="tv-fgroup">
                    <h4>Đánh giá</h4>
                    <div class="tv-fchips">
                        <button type="button" class="tv-chipbtn" data-filter="rating" data-value="5"><i
                                class="fas fa-star"></i> 5 sao</button>
                        <button type="button" class="tv-chipbtn" data-filter="rating" data-value="4"><i
                                class="fas fa-star"></i> Từ 4 sao</button>
                        <button type="button" class="tv-chipbtn" data-filter="rating" data-value="3"><i
                                class="fas fa-star"></i> Từ 3 sao</button>
                    </div>
                </div>

                @if (isset($popularTours) && count($popularTours))
                    <div class="tv-fgroup tv-popular">
                        <h4>Được yêu thích</h4>
                        @foreach ($popularTours as $popular)
                            <a class="tv-mini" href="{{ route('tour-detail', ['id' => $popular->tourId]) }}">
                                <img loading="lazy"
                                    src="{{ \App\Support\TourImage::url($popular->thumbnail ?? 'default.jpg', 320) }}"
                                    alt="{{ $popular->title }}">
                                <span>
                                    <b>{{ \Illuminate\Support\Str::limit($popular->title, 38) }}</b>
                                    <small><i class="fas fa-star"></i> {{ number_format($popular->averageRating, 1) }}
                                        · {{ $popular->destination }}</small>
                                </span>
                            </a>
                        @endforeach
                    </div>
                @endif

                <div class="tv-filter__foot">
                    <button type="button" class="tv-btn-ghost" data-action="reset">Xóa bộ lọc</button>
                    <button type="button" class="tv-btn-solid" data-action="close-filter">Xem kết quả</button>
                </div>
            </aside>

            <div class="tv-tours__main">
                <div class="tv-toolbar">
                    <div class="tv-count" id="tvCount" aria-live="polite">Tìm thấy <b>{{ $tours->total() }}</b>
                        tour</div>
                    <button type="button" class="tv-filter-open" data-action="open-filter"><i
                            class="fal fa-sliders-h"></i> Bộ lọc</button>
                    <div class="tv-sort" role="group" aria-label="Sắp xếp">
                        <button type="button" data-sort="new">Mới nhất</button>
                        <button type="button" data-sort="price_asc">Giá thấp → cao</button>
                        <button type="button" data-sort="price_desc">Giá cao → thấp</button>
                        <button type="button" data-sort="rating">Đánh giá cao</button>
                        <button type="button" data-sort="soon">Khởi hành sớm</button>
                    </div>
                </div>

                <div class="tv-active" id="tvActive" aria-label="Bộ lọc đang áp dụng"></div>

                <div id="tvResults" aria-live="polite">
                    @include('clients.partials.filter-tours')
                </div>
            </div>
        </div>
    </div>
</section>
<div class="tv-overlay" id="tvOverlay" data-action="close-filter"></div>

<script>
    window.TV_TOURS = {
        url: @json(route('filter-tours')),
        min: {{ $minBound }},
        max: {{ $maxBound }},
        state: @json($state)
    };
</script>
<script src="{{ asset('clients/assets/js/travel-tours.js') }}?v=1"></script>

@include('clients.blocks.new_letter')
@include('clients.blocks.footer')
