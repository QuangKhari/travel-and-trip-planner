@include('clients.blocks.header')

@php
    $wc = asset('clients/assets/images/watercolor');
    $minDate = now()->addDay()->toDateString();
    $guests = (int) ($state['guests'] ?? 0);
    $pricePresets = [
        ['Dưới 3 triệu', 0, 3000000],
        ['3 – 5 triệu', 3000000, 5000000],
        ['5 – 10 triệu', 5000000, 10000000],
        ['Trên 10 triệu', 10000000, 0],
    ];

    // Nhóm sắp xếp: [nhãn, mô tả ngắn] — không dùng icon, đọc là hiểu
    $sortGroups = [
        'Gợi ý' => [
            'new' => ['Mới nhất', 'Tour được thêm gần đây nhất'],
            'popular' => ['Phổ biến nhất', 'Có nhiều người đặt nhất'],
        ],
        'Giá' => [
            'price_asc' => ['Giá thấp đến cao', 'Giá người lớn thấp nhất đứng trước'],
            'price_desc' => ['Giá cao đến thấp', 'Giá người lớn cao nhất đứng trước'],
        ],
        'Lịch trình' => [
            'soon' => ['Khởi hành sớm', 'Ngày khởi hành gần nhất đứng trước'],
        ],
        'Đánh giá' => [
            'rating' => ['Đánh giá cao', 'Điểm trung bình cao nhất đứng trước'],
            'reviews' => ['Nhiều đánh giá', 'Có nhiều lượt nhận xét nhất'],
        ],
    ];
    $sortCur = $state['sort'] ?? 'new';
    $sortCurLabel = collect($sortGroups)->collapse()->get($sortCur)[0] ?? 'Mới nhất';
    // Gợi ý nhanh: điểm đến đang có nhiều tour nhất (dữ liệu thật)
    $popular = collect($destOptions)->sortByDesc('total')->take(4);
@endphp

<main class="tl wt-paper">

    {{-- ===== HERO: chữ rõ ràng + ảnh thật; màu nước chỉ ở mép, có chuyển động nhẹ ===== --}}
    <section class="tl-hero" aria-labelledby="tlTitle">
        <span class="tl-hero__wash tl-hero__wash--a" aria-hidden="true"></span>
        <span class="tl-hero__wash tl-hero__wash--b" aria-hidden="true"></span>
        <img class="tl-cloud tl-cloud--1" src="{{ $wc }}/wc-cloud.svg" alt="" aria-hidden="true">
        <img class="tl-cloud tl-cloud--2" src="{{ $wc }}/wc-cloud.svg" alt="" aria-hidden="true">
        <img class="wt-deco l wt-drift" src="{{ $wc }}/wc-leaf-a.svg" alt="" aria-hidden="true">
        <img class="wt-deco r wt-drift wt-drift--slow" src="{{ $wc }}/wc-leaf-b.svg" alt=""
            aria-hidden="true">
        <img class="tl-birds" src="{{ $wc }}/wc-birds.svg" alt="" aria-hidden="true">

        <div class="container tl-hero__in">
            <div class="tl-hero__text">
                <nav class="tl-crumb" aria-label="breadcrumb">
                    <a href="{{ route('home') }}">Trang chủ</a><span aria-hidden="true">›</span><span
                        aria-current="page">Tour</span>
                </nav>
                <span class="tl-eyebrow">Khám phá Việt Nam</span>
                <h1 id="tlTitle">Những hành trình <em>đáng nhớ</em></h1>
                <p>Từ bãi biển xanh ngắt, dãy núi hùng vĩ đến những nét văn hóa đặc sắc – mỗi tour là một câu chuyện
                    đang chờ bạn.</p>

                @if ($popular->isNotEmpty())
                    <div class="tl-quick" role="group" aria-label="Điểm đến được chọn nhiều">
                        <span class="tl-quick__lbl">Đang có nhiều tour:</span>
                        @foreach ($popular as $d)
                            <button type="button" class="tl-quick__b" data-filter="dest"
                                data-value="{{ $d->name }}">{{ $d->name }}</button>
                        @endforeach
                    </div>
                @endif
            </div>
            <div class="tl-hero__photo">
                <span class="tl-hero__frame" aria-hidden="true"></span>
                <img src="{{ asset('clients/assets/images/banner/banner-tours.jpg') }}"
                    alt="Vịnh biển xanh ngọc với những dãy núi đá vôi" fetchpriority="high" width="1200"
                    height="600">
                <span class="tl-hero__note" aria-hidden="true">Đi để cảm nhận<br>Việt Nam thật đẹp!</span>
            </div>
        </div>
    </section>

    {{-- ===== TÌM KIẾM: nhân vật chính ===== --}}
    <section class="tl-searchwrap" aria-label="Tìm tour">
        <div class="container">
            <form class="tl-search" id="tvSearchForm" role="search" autocomplete="off">
                <div class="tl-field">
                    <i class="fas fa-map-marker-alt" aria-hidden="true"></i>
                    <div class="tl-field__body">
                        <label for="tvKeyword">Điểm đến</label>
                        <input type="text" id="tvKeyword" name="keyword" maxlength="100" list="tlDestList"
                            value="{{ $state['keyword'] }}" placeholder="Bạn muốn đi đâu?">
                        <datalist id="tlDestList">
                            @foreach ($destOptions as $d)
                                <option value="{{ $d->name }}"></option>
                            @endforeach
                        </datalist>
                    </div>
                    <button type="button" class="tl-link" id="tvKeywordClear" hidden
                        aria-label="Xóa từ khóa">✕</button>
                </div>

                <div class="tl-field">
                    <i class="far fa-calendar-alt" aria-hidden="true"></i>
                    <div class="tl-field__body">
                        <span class="tl-field__lbl">Thời gian</span>
                        <div class="tl-dates">
                            <input type="date" id="tvFrom" min="{{ $minDate }}"
                                value="{{ $state['from'] }}" aria-label="Đi từ ngày">
                            <span aria-hidden="true">→</span>
                            <input type="date" id="tvTo" min="{{ $minDate }}" value="{{ $state['to'] }}"
                                aria-label="Về trước ngày">
                        </div>
                    </div>
                </div>

                <div class="tl-field">
                    <i class="fas fa-compass" aria-hidden="true"></i>
                    <div class="tl-field__body">
                        <label for="tvDomain">Miền</label>
                        <select id="tvDomain">
                            <option value="">Tất cả</option>
                            <option value="b">Miền Bắc</option>
                            <option value="t">Miền Trung</option>
                            <option value="n">Miền Nam</option>
                        </select>
                    </div>
                </div>

                <div class="tl-field">
                    <i class="fas fa-user-friends" aria-hidden="true"></i>
                    <div class="tl-field__body">
                        <span class="tl-field__lbl">Số khách</span>
                        <div class="tl-step">
                            <button type="button" data-guest="-1" aria-label="Giảm số khách">−</button>
                            <output id="tvGuests" aria-live="polite">Bất kỳ</output>
                            <button type="button" data-guest="1" aria-label="Tăng số khách">+</button>
                        </div>
                    </div>
                </div>

                <button type="submit" class="tl-search__btn"><i class="fas fa-search" aria-hidden="true"></i> Tìm
                    kiếm</button>
            </form>

            {{-- Chip danh mục = "cảm hứng" trong CSDL (tbl_inspirations) --}}
            <p class="tl-cats__lbl" id="tlCatsLbl">Chọn nhanh theo cảm hứng</p>
            <div class="tl-cats" role="group" aria-labelledby="tlCatsLbl">
                <button type="button" class="tl-cat" data-action="clear-cat">
                    <span class="tl-cat__ico"><i class="fas fa-th-large" aria-hidden="true"></i></span>Tất cả
                </button>
                @foreach ($inspirations as $ins)
                    <button type="button" class="tl-cat" data-filter="cat" data-value="{{ $ins->slug }}">
                        <span class="tl-cat__ico"><i class="fas {{ $ins->icon }}"
                                aria-hidden="true"></i></span>{{ $ins->name }}
                    </button>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ===== BỘ LỌC + KẾT QUẢ ===== --}}
    <section class="tl-body">
        <img class="wt-deco tl-body__leaf wt-drift wt-drift--slow" src="{{ $wc }}/wc-leaf-c.svg"
            alt="" aria-hidden="true" loading="lazy">
        <div class="container">
            <div class="tl-layout">

                <aside class="tl-filter" id="tvFilter" aria-label="Bộ lọc tour">
                    <div class="tl-filter__head">
                        <h2>Bộ lọc</h2>
                        <button type="button" class="tl-link" data-action="reset">Đặt lại</button>
                        <button type="button" class="tl-filter__close" data-action="close-filter"
                            aria-label="Đóng bộ lọc"><i class="fal fa-times" aria-hidden="true"></i></button>
                    </div>

                    <details class="tl-fg" open>
                        <summary>Mức giá <i class="fas fa-chevron-down" aria-hidden="true"></i></summary>
                        <div class="tl-fg__in">
                            @foreach ($pricePresets as [$lbl, $a, $b])
                                <button type="button" class="tl-opt" data-price-min="{{ $a }}"
                                    data-price-max="{{ $b }}">{{ $lbl }}</button>
                            @endforeach
                            <div class="tl-range" id="tvRange">
                                <div class="tl-range__track">
                                    <div class="tl-range__fill" id="tvRangeFill"></div>
                                </div>
                                <input type="range" id="tvMin" min="{{ $minBound }}"
                                    max="{{ $maxBound }}" step="100000" value="{{ $state['min'] }}"
                                    aria-label="Giá thấp nhất">
                                <input type="range" id="tvMax" min="{{ $minBound }}"
                                    max="{{ $maxBound }}" step="100000" value="{{ $state['max'] }}"
                                    aria-label="Giá cao nhất">
                            </div>
                            <div class="tl-range__lbl"><span id="tvMinLabel"></span><span id="tvMaxLabel"></span>
                            </div>
                        </div>
                    </details>

                    <details class="tl-fg" open>
                        <summary>Thời gian <i class="fas fa-chevron-down" aria-hidden="true"></i></summary>
                        <div class="tl-fg__in">
                            <button type="button" class="tl-opt" data-filter="days" data-value="day">Trong
                                ngày</button>
                            <button type="button" class="tl-opt" data-filter="days" data-value="short">2 – 3
                                ngày</button>
                            <button type="button" class="tl-opt" data-filter="days" data-value="mid">4 – 5
                                ngày</button>
                            <button type="button" class="tl-opt" data-filter="days" data-value="long">6 ngày trở
                                lên</button>
                        </div>
                    </details>

                    <details class="tl-fg" open>
                        <summary>Vùng miền <i class="fas fa-chevron-down" aria-hidden="true"></i></summary>
                        <div class="tl-fg__in">
                            @foreach (['b' => 'Miền Bắc', 't' => 'Miền Trung', 'n' => 'Miền Nam'] as $k => $lbl)
                                <button type="button" class="tl-opt" data-filter="domain"
                                    data-value="{{ $k }}">{{ $lbl }}<em>{{ $domainsCount[$k] }}</em></button>
                            @endforeach
                        </div>
                    </details>

                    <details class="tl-fg" open>
                        <summary>Điểm đến <i class="fas fa-chevron-down" aria-hidden="true"></i></summary>
                        <div class="tl-fg__in">
                            <div class="tl-dsearch">
                                <i class="fas fa-search" aria-hidden="true"></i>
                                <input type="search" id="tvDestSearch" placeholder="Tìm điểm đến..."
                                    aria-label="Tìm trong danh sách điểm đến">
                            </div>
                            <div class="tl-dest" id="tvDestList">
                                @foreach ($destOptions as $d)
                                    <button type="button" class="tl-opt" data-filter="dest"
                                        data-value="{{ $d->name }}"
                                        data-name="{{ mb_strtolower($d->name) }}">{{ $d->name }}<em>{{ $d->total }}</em></button>
                                @endforeach
                            </div>
                        </div>
                    </details>

                    <details class="tl-fg">
                        <summary>Đánh giá <i class="fas fa-chevron-down" aria-hidden="true"></i></summary>
                        <div class="tl-fg__in">
                            <button type="button" class="tl-opt" data-filter="rating" data-value="5">5 sao</button>
                            <button type="button" class="tl-opt" data-filter="rating" data-value="4">Từ 4
                                sao</button>
                            <button type="button" class="tl-opt" data-filter="rating" data-value="3">Từ 3
                                sao</button>
                        </div>
                    </details>

                    <div class="tl-filter__foot">
                        <button type="button" class="tl-link" data-action="reset">Xóa bộ lọc</button>
                        <button type="button" class="tv-btn-solid" data-action="close-filter">Xem kết quả</button>
                    </div>
                </aside>

                <div class="tl-main">
                    <div class="tl-toolbar">
                        <div class="tl-count" id="tvCount" aria-live="polite"><b>{{ $tours->total() }}</b> tour
                            phù hợp</div>
                        <button type="button" class="tl-filter-open" data-action="open-filter"><i
                                class="fal fa-sliders-h" aria-hidden="true"></i> Bộ lọc</button>

                        {{-- Điện thoại: ô chọn gốc --}}
                        <label class="tl-sort">Sắp xếp
                            <select id="tvSort">
                                <option value="new">Mới nhất</option>
                                <option value="popular">Phổ biến nhất</option>
                                <option value="rating">Đánh giá cao</option>
                                <option value="reviews">Nhiều đánh giá</option>
                                <option value="price_asc">Giá thấp đến cao</option>
                                <option value="price_desc">Giá cao đến thấp</option>
                                <option value="soon">Khởi hành sớm</option>
                            </select>
                        </label>

                        {{-- Máy tính: nút + menu thả xuống --}}
                        <div class="tl-sortmenu" id="tvSortMenu">
                            <button type="button" class="tl-sortmenu__btn" aria-haspopup="true"
                                aria-expanded="false" aria-controls="tvSortBar">
                                <span class="tl-sortmenu__k">Sắp xếp</span>
                                <b id="tvSortCur">{{ $sortCurLabel }}</b>
                                <span class="tl-sortmenu__caret" aria-hidden="true"></span>
                            </button>
                            <div class="tl-sortbar" id="tvSortBar" role="radiogroup"
                                aria-label="Sắp xếp danh sách tour" hidden>
                                @foreach ($sortGroups as $group => $items)
                                    <span class="tl-sortgrp" aria-hidden="true">{{ $group }}</span>
                                    @foreach ($items as $key => [$label, $hint])
                                        <button type="button" role="radio" data-sort="{{ $key }}"
                                            data-label="{{ $label }}"
                                            aria-checked="{{ $sortCur === $key ? 'true' : 'false' }}"
                                            class="tl-sortchip {{ $sortCur === $key ? 'is-on' : '' }}">
                                            <span
                                                class="tl-sortchip__tx">{{ $label }}<small>{{ $hint }}</small></span>
                                        </button>
                                    @endforeach
                                @endforeach
                            </div>
                        </div>
                    </div>

                    {{-- Kệ cá nhân (đọc từ localStorage của trình duyệt này; tự ẩn khi trống) --}}
                    <section class="ux-shelf" id="uxSaved" hidden aria-labelledby="uxSavedH">
                        <div class="ux-shelf__head">
                            <h2 id="uxSavedH"><i class="fas fa-heart" aria-hidden="true"></i> Tour đã lưu <em
                                    id="uxSavedN"></em></h2>
                            <button type="button" class="bk-link" data-ux-clear="saved">Xóa tất cả</button>
                        </div>
                        <div class="ux-shelf__row" id="uxSavedRow"></div>
                    </section>
                    <section class="ux-shelf" id="uxRecent" hidden aria-labelledby="uxRecentH">
                        <div class="ux-shelf__head">
                            <h2 id="uxRecentH"><i class="fal fa-history" aria-hidden="true"></i> Bạn đã xem gần đây
                            </h2>
                            <button type="button" class="bk-link" data-ux-clear="recent">Xóa lịch sử</button>
                        </div>
                        <div class="ux-shelf__row" id="uxRecentRow"></div>
                    </section>

                    <div class="tl-active" id="tvActive" aria-label="Bộ lọc đang áp dụng"></div>

                    <div id="tvResults" aria-live="polite">
                        @include('clients.partials.filter-tours')
                    </div>
                </div>
            </div>
        </div>
    </section>

    @include('clients.partials.tour-cta-scene')
</main>
<div class="tl-overlay" id="tvOverlay" data-action="close-filter"></div>

<script>
    window.TV_TOURS = {
        url: @json(route('filter-tours')),
        min: {{ $minBound }},
        max: {{ $maxBound }},
        state: @json($state)
    };
</script>
<script src="{{ asset('clients/assets/js/travel-tours.js') }}?v=4"></script>
<script src="{{ asset('clients/assets/js/tv-sort-menu.js') }}?v=2"></script>
<script src="{{ asset('clients/assets/js/tv-tours-v2.js') }}?v=1" defer></script>

@include('clients.blocks.footer')
