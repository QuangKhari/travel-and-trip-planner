@include('clients.blocks.header')
{{--
  Trang /tours (giao diện mới, CSS: tv3.css + tv3-pages.css, tiền tố tr-).
  Logic lọc giữ nguyên trong travel-tours.js: các id (tv*) và thuộc tính data-filter / data-value / data-price-*
  / data-action / data-guest phải giữ đúng tên.
--}}
@php
    $minDate = now()->addDay()->toDateString();
    $pricePresets = [
        ['Dưới 3 triệu', 0, 3000000],
        ['3 – 5 triệu', 3000000, 5000000],
        ['5 – 10 triệu', 5000000, 10000000],
        ['Trên 10 triệu', 10000000, 0],
    ];
    $sorts = [
        'new' => 'Mới nhất',
        'popular' => 'Phổ biến nhất',
        'soon' => 'Khởi hành sớm',
        'price_asc' => 'Giá thấp đến cao',
        'price_desc' => 'Giá cao đến thấp',
        'rating' => 'Đánh giá cao',
        'reviews' => 'Nhiều đánh giá',
    ];
    $sortCur = $state['sort'] ?? 'new';
    // Gợi ý nhanh: điểm đến đang có nhiều tour nhất (dữ liệu thật)
    $popular = collect($destOptions)->sortByDesc('total')->take(4);
    $insColor = [
        'bien' => ['#0b9488', '#dff4f1'],
        'thien-nhien' => ['#2f9e5b', '#e3f4e8'],
        'chua-lanh' => ['#5b7fc7', '#e6edfa'],
        'van-hoa' => ['#c68a10', '#fbf0d6'],
        'am-thuc' => ['#e2681c', '#fde8db'],
        'mien-que' => ['#6f9a2e', '#edf5df'],
        'san-anh' => ['#2f6fdb', '#e2ecfc'],
        'hai-nguoi' => ['#d93a6c', '#fde3ec'],
    ];
@endphp

<main class="v3 tr">

    {{-- ===== ĐẦU TRANG + TÌM KIẾM ===== --}}
    <section class="tr-hero" aria-labelledby="trTitle">
        <div class="v3-wrap">
            <nav class="tr-crumb" aria-label="breadcrumb">
                <a href="{{ route('home') }}">Trang chủ</a><span aria-hidden="true">›</span><span
                    aria-current="page">Tour</span>
            </nav>
            <div class="tr-hero__row">
                <div>
                    <p class="v3-eyebrow">Tour trong nước</p>
                    <h1 id="trTitle">Tìm tour <span>phù hợp</span> với bạn</h1>
                    <p class="tr-hero__sub">Lọc theo điểm đến, ngày đi, ngân sách và số ngày. Giá và số chỗ còn lại cập
                        nhật trực tiếp.</p>
                </div>
                @if ($popular->isNotEmpty())
                    <div class="tr-quick" role="group" aria-label="Điểm đến đang có nhiều tour">
                        <span>Nhiều tour nhất:</span>
                        @foreach ($popular as $d)
                            <button type="button" class="tr-quick__b" data-filter="dest"
                                data-value="{{ $d->name }}">{{ $d->name }}<em>{{ $d->total }}</em></button>
                        @endforeach
                    </div>
                @endif
            </div>

            <form class="tr-search" id="tvSearchForm" role="search" autocomplete="off">
                <div class="tr-field tr-field--grow">
                    <i class="fal fa-map-marker-alt" aria-hidden="true"></i>
                    <div class="tr-field__body">
                        <label for="tvKeyword">Điểm đến</label>
                        <input type="text" id="tvKeyword" name="keyword" maxlength="100" list="tlDestList"
                            value="{{ $state['keyword'] }}" placeholder="Bạn muốn đi đâu?">
                        <datalist id="tlDestList">
                            @foreach ($destOptions as $d)
                                <option value="{{ $d->name }}"></option>
                            @endforeach
                        </datalist>
                    </div>
                    <button type="button" class="tr-x" id="tvKeywordClear" hidden aria-label="Xóa từ khóa">✕</button>
                </div>
                <div class="tr-field">
                    <i class="fal fa-calendar-alt" aria-hidden="true"></i>
                    <div class="tr-field__body">
                        <span class="tr-field__lbl">Thời gian</span>
                        <div class="tr-dates">
                            <input type="date" id="tvFrom" min="{{ $minDate }}" value="{{ $state['from'] }}"
                                aria-label="Đi từ ngày">
                            <span aria-hidden="true">→</span>
                            <input type="date" id="tvTo" min="{{ $minDate }}" value="{{ $state['to'] }}"
                                aria-label="Về trước ngày">
                        </div>
                    </div>
                </div>
                <div class="tr-field">
                    <i class="fal fa-compass" aria-hidden="true"></i>
                    <div class="tr-field__body">
                        <label for="tvDomain">Miền</label>
                        <select id="tvDomain">
                            <option value="">Tất cả</option>
                            <option value="b">Miền Bắc</option>
                            <option value="t">Miền Trung</option>
                            <option value="n">Miền Nam</option>
                        </select>
                    </div>
                </div>
                <div class="tr-field">
                    <i class="fal fa-user-friends" aria-hidden="true"></i>
                    <div class="tr-field__body">
                        <span class="tr-field__lbl">Số khách</span>
                        <div class="tr-step">
                            <button type="button" data-guest="-1" aria-label="Giảm số khách">−</button>
                            <output id="tvGuests" aria-live="polite">Bất kỳ</output>
                            <button type="button" data-guest="1" aria-label="Tăng số khách">+</button>
                        </div>
                    </div>
                </div>
                <button type="submit" class="tr-search__btn"><i class="far fa-search" aria-hidden="true"></i> Tìm
                    kiếm</button>
            </form>

            {{-- Chip cảm hứng (tbl_inspirations) --}}
            <div class="tr-cats" role="group" aria-label="Lọc theo cảm hứng">
                <button type="button" class="tr-cat" data-action="clear-cat">
                    <span class="tr-cat__i" style="--c: #12333d; --bg: #e9eef0"><i class="fas fa-th-large"
                            aria-hidden="true"></i></span>Tất cả
                </button>
                @foreach ($inspirations as $ins)
                    @php $col = $insColor[$ins->slug] ?? ['#0b9488', '#dff4f1']; @endphp
                    <button type="button" class="tr-cat" data-filter="cat" data-value="{{ $ins->slug }}">
                        <span class="tr-cat__i" style="--c: {{ $col[0] }}; --bg: {{ $col[1] }}"><i
                                class="fas {{ $ins->icon }}" aria-hidden="true"></i></span>{{ $ins->name }}
                    </button>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ===== BỘ LỌC + KẾT QUẢ ===== --}}
    <section class="tr-body">
        <div class="v3-wrap tr-layout">
            <aside class="tr-filter" id="tvFilter" aria-label="Bộ lọc tour">
                <div class="tr-filter__head">
                    <h2>Bộ lọc</h2>
                    <button type="button" class="tr-link" data-action="reset">Đặt lại</button>
                    <button type="button" class="tr-filter__close" data-action="close-filter"
                        aria-label="Đóng bộ lọc"><i class="fal fa-times" aria-hidden="true"></i></button>
                </div>

                <details class="tr-fg" open>
                    <summary>Mức giá</summary>
                    <div class="tr-fg__in">
                        <div class="tr-opts">
                            @foreach ($pricePresets as [$lbl, $a, $b])
                                <button type="button" class="tr-opt" data-price-min="{{ $a }}"
                                    data-price-max="{{ $b }}">{{ $lbl }}</button>
                            @endforeach
                        </div>
                        <div class="tr-range">
                            <div class="tr-range__track">
                                <div class="tr-range__fill" id="tvRangeFill"></div>
                            </div>
                            <input type="range" id="tvMin" min="{{ $minBound }}" max="{{ $maxBound }}"
                                step="100000" value="{{ $state['min'] }}" aria-label="Giá thấp nhất">
                            <input type="range" id="tvMax" min="{{ $minBound }}" max="{{ $maxBound }}"
                                step="100000" value="{{ $state['max'] }}" aria-label="Giá cao nhất">
                        </div>
                        <div class="tr-range__lbl"><span id="tvMinLabel"></span><span id="tvMaxLabel"></span></div>
                    </div>
                </details>

                <details class="tr-fg" open>
                    <summary>Số ngày</summary>
                    <div class="tr-fg__in tr-opts">
                        <button type="button" class="tr-opt" data-filter="days" data-value="day">Trong ngày</button>
                        <button type="button" class="tr-opt" data-filter="days" data-value="short">2 – 3
                            ngày</button>
                        <button type="button" class="tr-opt" data-filter="days" data-value="mid">4 – 5 ngày</button>
                        <button type="button" class="tr-opt" data-filter="days" data-value="long">6 ngày trở
                            lên</button>
                    </div>
                </details>

                <details class="tr-fg" open>
                    <summary>Vùng miền</summary>
                    <div class="tr-fg__in tr-list">
                        @foreach (['b' => 'Miền Bắc', 't' => 'Miền Trung', 'n' => 'Miền Nam'] as $k => $lbl)
                            <button type="button" class="tr-row" data-filter="domain"
                                data-value="{{ $k }}">{{ $lbl }}<em>{{ $domainsCount[$k] }}</em></button>
                        @endforeach
                    </div>
                </details>

                <details class="tr-fg" open>
                    <summary>Điểm đến</summary>
                    <div class="tr-fg__in">
                        <div class="tr-dsearch">
                            <i class="far fa-search" aria-hidden="true"></i>
                            <input type="search" id="tvDestSearch" placeholder="Tìm điểm đến..."
                                aria-label="Tìm trong danh sách điểm đến">
                        </div>
                        <div class="tr-list tr-list--scroll" id="tvDestList">
                            @foreach ($destOptions as $d)
                                <button type="button" class="tr-row" data-filter="dest"
                                    data-value="{{ $d->name }}"
                                    data-name="{{ mb_strtolower($d->name) }}">{{ $d->name }}<em>{{ $d->total }}</em></button>
                            @endforeach
                        </div>
                    </div>
                </details>

                <details class="tr-fg">
                    <summary>Đánh giá</summary>
                    <div class="tr-fg__in tr-opts">
                        <button type="button" class="tr-opt" data-filter="rating" data-value="5">5 sao</button>
                        <button type="button" class="tr-opt" data-filter="rating" data-value="4">Từ 4 sao</button>
                        <button type="button" class="tr-opt" data-filter="rating" data-value="3">Từ 3 sao</button>
                    </div>
                </details>

                <div class="tr-filter__foot">
                    <button type="button" class="tr-link" data-action="reset">Xóa bộ lọc</button>
                    <button type="button" class="v3-btn v3-btn--teal" data-action="close-filter">Xem kết quả</button>
                </div>
            </aside>

            <div class="tr-main">
                <div class="tr-toolbar" id="tvToolbar">
                    <div class="tr-count" id="tvCount" aria-live="polite"><b>{{ $tours->total() }}</b> tour phù hợp
                    </div>
                    <span class="tr-sortnote" id="tvSortNote"></span>
                    <button type="button" class="tr-filter-open" data-action="open-filter"><i
                            class="fal fa-sliders-h" aria-hidden="true"></i> Bộ lọc</button>
                    <label class="tr-sort">Sắp xếp
                        <select id="tvSort">
                            @foreach ($sorts as $k => $lbl)
                                <option value="{{ $k }}" @selected($sortCur === $k)>{{ $lbl }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                {{-- Kệ cá nhân (localStorage của trình duyệt này; tự ẩn khi trống) --}}
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
                        <h2 id="uxRecentH"><i class="fal fa-history" aria-hidden="true"></i> Bạn đã xem gần đây</h2>
                        <button type="button" class="bk-link" data-ux-clear="recent">Xóa lịch sử</button>
                    </div>
                    <div class="ux-shelf__row" id="uxRecentRow"></div>
                </section>

                <div class="tr-active" id="tvActive" aria-label="Bộ lọc đang áp dụng"></div>

                <div id="tvResults" aria-live="polite">
                    @include('clients.partials.filter-tours')
                </div>
            </div>
        </div>
    </section>
    <div class="tr-gap" aria-hidden="true"></div>
</main>
<div class="tr-overlay" id="tvOverlay" data-action="close-filter"></div>

<script>
    window.TV_TOURS = {
        url: @json(route('filter-tours')),
        min: {{ $minBound }},
        max: {{ $maxBound }},
        state: @json($state)
    };
</script>
<script src="{{ asset('clients/assets/js/travel-tours.js') }}?v=5"></script>

@include('clients.blocks.footer')
