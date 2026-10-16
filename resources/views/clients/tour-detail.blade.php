@include('clients.blocks.header')

@php
    $images = $tourDetail->images->isEmpty() ? collect(['default.jpg']) : $tourDetail->images;
    $imgCount = $images->count();
    $stripImages = $images->take(5);
    $moreImages = $images->slice(5)->values();

    $start = \Carbon\Carbon::parse($tourDetail->startDate);
    $end = \Carbon\Carbon::parse($tourDetail->endDate);
    $daysToGo = (int) now()
        ->startOfDay()
        ->diffInDays($start->copy()->startOfDay(), false);
    $weekdays = ['Chủ nhật', 'Thứ hai', 'Thứ ba', 'Thứ tư', 'Thứ năm', 'Thứ sáu', 'Thứ bảy'];

    $soldOut = $tourDetail->quantity <= 0 || $daysToGo < 0;
    $avg = (float) ($reviewStats->averageRating ?? 0);
    $reviewCount = (int) ($reviewStats->reviewCount ?? 0);
    $timelineCount = $tourDetail->timeline->count();
    $holdHours = (int) config('travela.hold_hours', 48);
    $cancelDays = \App\Support\CancelPolicy::MIN_DAYS;

    // Video giới thiệu (cột video_url; để trống thì ẩn mọi thứ liên quan)
    $video = \App\Support\TourVideo::parse($tourDetail->video_url ?? null);
    $videoPoster = \App\Support\TourImage::url($images->get(1) ?? $images->first(), 1200);
    $heroImg = \App\Support\TourImage::url($images->first(), 1600);

    // Điểm nhấn: lấy từ tiêu đề lịch trình thật của tour (tối đa 4 ngày đầu)
    $highlights = $tourDetail->timeline->take(4);
    $hlIcons = ['fa-umbrella-beach', 'fa-utensils', 'fa-camera-retro', 'fa-ship'];
@endphp

<main class="px-detail">

    {{-- ===== HERO ===== --}}
    <section class="px-hero">
        <img class="px-hero__img" src="{{ $heroImg }}" alt="{{ $tourDetail->title }}" fetchpriority="high">
        <div class="px-hero__shade" aria-hidden="true"></div>

        <div class="container px-hero__in">
            <nav class="px-crumb" aria-label="breadcrumb">
                <a href="{{ route('home') }}">Trang chủ</a>
                <i class="fas fa-chevron-right" aria-hidden="true"></i>
                <a href="{{ route('tours') }}">Tours</a>
                <i class="fas fa-chevron-right" aria-hidden="true"></i>
                <span aria-current="page">{{ $tourDetail->destination }}</span>
            </nav>

            <h1 class="px-hero__title">{{ $tourDetail->title }}</h1>

            <ul class="px-hero__meta">
                @if ($reviewCount > 0)
                    <li>
                        <a href="#danh-gia" class="px-rate">
                            <i class="fas fa-star" aria-hidden="true"></i>
                            <b>{{ number_format($avg, 1) }}</b>
                            <span>({{ $reviewCount }} đánh giá)</span>
                        </a>
                    </li>
                @else
                    <li class="px-rate px-rate--none"><i class="far fa-star" aria-hidden="true"></i> Chưa có đánh giá
                    </li>
                @endif
                <li><i class="fal fa-clock" aria-hidden="true"></i>{{ $tourDetail->time }}</li>
                <li><i class="fal fa-map-marker-alt" aria-hidden="true"></i>{{ $tourDetail->destination }}</li>
                <li><i class="fal fa-plane-departure" aria-hidden="true"></i>Khởi hành {{ $start->format('d/m/Y') }}
                </li>
            </ul>

            <div class="px-hero__bottom">
                <div class="px-hero__price">
                    <small>Chỉ từ</small>
                    <b>{{ number_format($tourDetail->priceAdult, 0, ',', '.') }}đ</b>
                    <span>/ khách</span>
                </div>

                <div class="px-hero__cta">
                    @if ($video)
                        <button type="button" class="px-btn px-btn--sand" data-video data-vtype="{{ $video['type'] }}"
                            data-vsrc="{{ $video['src'] }}" data-vtitle="{{ $tourDetail->title }}">
                            <i class="fas fa-play" aria-hidden="true"></i> Xem video
                        </button>
                    @endif
                    <a href="#tdBook" class="px-btn px-btn--glass">Đặt tour ngay</a>
                    <button type="button" class="px-iconbtn" data-share data-title="{{ $tourDetail->title }}"
                        aria-label="Chia sẻ tour này">
                        <i class="fal fa-share-alt" aria-hidden="true"></i><span>Chia sẻ</span>
                    </button>
                </div>
            </div>
        </div>

        <svg class="px-wave" viewBox="0 0 1440 80" preserveAspectRatio="none" aria-hidden="true">
            <path d="M0 40 Q180 80 380 48 T760 44 T1130 28 T1440 48 V80 H0Z" />
        </svg>
    </section>

    <div class="container">

        {{-- ===== THANH THÔNG TIN NỔI (kính mờ, chồng lên hero) ===== --}}
        <div class="px-infobar">
            <div class="px-infobar__cell">
                <i class="fal fa-calendar-alt" aria-hidden="true"></i>
                <div><small>Khởi
                        hành</small><b>{{ $start->format('d/m/Y') }}</b><em>{{ $weekdays[$start->dayOfWeek] }}</em>
                </div>
            </div>
            <div class="px-infobar__cell">
                <i class="fal fa-flag-checkered" aria-hidden="true"></i>
                <div><small>Kết
                        thúc</small><b>{{ $end->format('d/m/Y') }}</b><em>{{ $weekdays[$end->dayOfWeek] }}</em></div>
            </div>
            <div class="px-infobar__cell">
                <i class="fal fa-clock" aria-hidden="true"></i>
                <div><small>Thời gian</small><b>{{ $tourDetail->time }}</b></div>
            </div>
            <div class="px-infobar__cell">
                <i class="fal fa-users" aria-hidden="true"></i>
                <div><small>Số chỗ</small>
                    <b class="{{ $tourDetail->quantity <= 10 ? 'is-low' : '' }}">
                        @if ($tourDetail->quantity <= 0)
                            Đã hết
                        @elseif ($tourDetail->quantity <= 10)
                            Chỉ còn {{ $tourDetail->quantity }}
                        @else
                            Còn chỗ
                        @endif
                    </b>
                </div>
            </div>
        </div>

        {{-- ===== DẢI ẢNH NHỎ (bấm để xem ảnh lớn) ===== --}}
        @if ($imgCount > 1)
            <div class="px-strip" data-gallery style="--n: {{ $stripImages->count() }}">
                @foreach ($stripImages as $i => $img)
                    <button type="button" class="px-thumb td-gallery__item {{ $i === 0 ? 'is-first' : '' }}"
                        data-index="{{ $i }}" data-full="{{ \App\Support\TourImage::url($img, 1600) }}"
                        aria-label="Xem ảnh {{ $i + 1 }} / {{ $imgCount }}">
                        <img src="{{ \App\Support\TourImage::url($img, 600) }}"
                            alt="{{ $tourDetail->title }} – ảnh {{ $i + 1 }}" loading="lazy" decoding="async">
                    </button>
                @endforeach
            </div>
        @endif

        <div class="px-layout">
            <div class="px-main">

                <nav class="px-tabs" id="tdTabs" aria-label="Các phần của trang">
                    <a href="#tong-quan" class="is-on">Tổng quan</a>
                    @if (!empty(trim((string) ($tourDetail->includes ?? ''))) || !empty(trim((string) ($tourDetail->excludes ?? ''))))
                        <a href="#bao-gom">Bao gồm</a>
                    @endif
                    <a href="#lich-trinh">Lịch trình</a>
                    <a href="#ban-do">Bản đồ</a>
                    <a href="#danh-gia">Đánh giá</a>
                </nav>

                {{-- Tổng quan --}}
                <section class="px-sec" id="tong-quan">
                    <h2 class="px-h2">Về hành trình này</h2>
                    @if ($highlights->count())
                        <div class="px-hl">
                            @foreach ($highlights as $i => $h)
                                <a class="px-hl__item" href="#ngay-{{ $i + 1 }}"
                                    data-open-day="{{ $i + 1 }}">
                                    <span class="px-hl__ico"><i class="fal {{ $hlIcons[$i % 4] }}"
                                            aria-hidden="true"></i></span>
                                    <span class="px-hl__txt"><small>Ngày
                                            {{ $i + 1 }}</small><b>{{ $h->title }}</b></span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                    <div class="td-prose px-prose">{!! $tourDetail->description !!}</div>
                </section>

                {{-- Giá tour bao gồm / không bao gồm (cột includes, excludes; mỗi dòng một mục; trống thì tự ẩn) --}}
                @php
                    $incList = array_values(
                        array_filter(array_map('trim', preg_split('/\R/u', (string) ($tourDetail->includes ?? '')))),
                    );
                    $excList = array_values(
                        array_filter(array_map('trim', preg_split('/\R/u', (string) ($tourDetail->excludes ?? '')))),
                    );
                @endphp
                @if ($incList || $excList)
                    <section class="px-sec" id="bao-gom">
                        <h2 class="px-h2">Giá tour đã gồm những gì?</h2>
                        <div class="px-inc">
                            @if ($incList)
                                <div class="px-inc__col is-yes">
                                    <h3><i class="fas fa-check-circle" aria-hidden="true"></i> Bao gồm</h3>
                                    <ul>
                                        @foreach ($incList as $item)
                                            <li>{{ $item }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                            @if ($excList)
                                <div class="px-inc__col is-no">
                                    <h3><i class="fas fa-times-circle" aria-hidden="true"></i> Không bao gồm</h3>
                                    <ul>
                                        @foreach ($excList as $item)
                                            <li>{{ $item }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </div>
                    </section>
                @endif

                {{-- Lịch trình + video --}}
                <section class="px-sec" id="lich-trinh">
                    <div class="px-sec__head">
                        <h2 class="px-h2">Lịch trình chi tiết</h2>
                        @if ($timelineCount > 1)
                            <button type="button" class="px-link" id="tdToggleAll" data-open="false">Mở tất
                                cả</button>
                        @endif
                    </div>

                    <div class="px-plan {{ $video ? 'has-video' : '' }}">
                        <div class="px-plan__list">
                            @if ($timelineCount > 0)
                                <ol class="px-days">
                                    @foreach ($tourDetail->timeline as $i => $timeline)
                                        <li>
                                            <span class="px-days__dot" aria-hidden="true"></span>
                                            <details class="px-day td-day" id="ngay-{{ $i + 1 }}"
                                                @if ($i === 0) open @endif>
                                                <summary>
                                                    <span class="px-day__no">Ngày {{ $i + 1 }}</span>
                                                    <span class="px-day__title">{{ $timeline->title }}</span>
                                                    <i class="fal fa-chevron-down" aria-hidden="true"></i>
                                                </summary>
                                                <div class="td-prose px-prose px-day__body">{!! $timeline->description !!}
                                                </div>
                                            </details>
                                        </li>
                                    @endforeach
                                </ol>
                            @else
                                <p class="px-muted">Lịch trình chi tiết đang được cập nhật. Bạn có thể <a
                                        href="{{ route('contact') }}">nhắn cho chúng tôi</a> để được tư vấn trước khi
                                    đặt.</p>
                            @endif
                        </div>

                        @if ($video)
                            <aside class="px-plan__video">
                                <button type="button" class="px-video" data-video data-vtype="{{ $video['type'] }}"
                                    data-vsrc="{{ $video['src'] }}" data-vtitle="{{ $tourDetail->title }}"
                                    aria-label="Phát video giới thiệu {{ $tourDetail->title }}">
                                    <img src="{{ $videoPoster }}" alt="" loading="lazy" decoding="async">
                                    <span class="px-video__play" aria-hidden="true"><i
                                            class="fas fa-play"></i></span>
                                    <span class="px-video__cap">
                                        <b>Xem video hành trình</b>
                                        <small>Cảm nhận không khí chuyến đi trước khi đặt</small>
                                    </span>
                                </button>
                            </aside>
                        @endif
                    </div>
                </section>

                {{-- Bản đồ --}}
                <section class="px-sec" id="ban-do">
                    <h2 class="px-h2">Điểm đến trên bản đồ</h2>
                    <div class="px-map">
                        <iframe title="Bản đồ {{ $tourDetail->destination }}" loading="lazy"
                            referrerpolicy="no-referrer-when-downgrade"
                            src="https://www.google.com/maps?q={{ urlencode($tourDetail->destination . ', Việt Nam') }}&output=embed&hl=vi"></iframe>
                    </div>
                    <a class="px-link" target="_blank" rel="noopener"
                        href="https://www.google.com/maps/search/?api=1&query={{ urlencode($tourDetail->destination . ', Việt Nam') }}">Mở
                        trong Google Maps <i class="fal fa-external-link" aria-hidden="true"></i></a>
                </section>

                {{-- Thư viện ảnh (chỉ hiện khi tour có hơn 5 ảnh) --}}
                @if ($moreImages->count())
                    <section class="px-sec" id="thu-vien">
                        <h2 class="px-h2">Thư viện ảnh</h2>
                        <div class="px-gal" data-gallery>
                            @foreach ($moreImages as $j => $img)
                                <button type="button" class="px-gal__item td-gallery__item"
                                    data-full="{{ \App\Support\TourImage::url($img, 1600) }}"
                                    aria-label="Xem ảnh {{ $j + 6 }} / {{ $imgCount }}">
                                    <img src="{{ \App\Support\TourImage::url($img, 700) }}"
                                        alt="{{ $tourDetail->title }} – ảnh {{ $j + 6 }}" loading="lazy"
                                        decoding="async">
                                </button>
                            @endforeach
                        </div>
                    </section>
                @endif

                {{-- Đánh giá --}}
                <section class="px-sec" id="danh-gia">
                    <div id="partials_reviews">
                        @include('clients.partials.reviews')
                    </div>

                    {{-- GIỮ NGUYÊN id/data-attr bên dưới: custom-js.js đang dùng --}}
                    <div class="td-review-wrap {{ $checkDisplay }}">
                        <h3 class="td-h3">Chia sẻ trải nghiệm của bạn</h3>
                        <p class="td-muted td-note">Chỉ khách đã hoàn tất tour mới có thể gửi đánh giá.</p>
                        <form id="comment-form" class="td-review-form" name="review-form"
                            action="{{ route('reviews') }}" method="post">
                            @csrf
                            <div class="td-rate">
                                <span>Bạn chấm mấy sao?</span>
                                <div class="td-stars" id="rating-stars" role="radiogroup" aria-label="Chọn số sao">
                                    <i class="far fa-star" data-value="1"></i>
                                    <i class="far fa-star" data-value="2"></i>
                                    <i class="far fa-star" data-value="3"></i>
                                    <i class="far fa-star" data-value="4"></i>
                                    <i class="far fa-star" data-value="5"></i>
                                </div>
                            </div>
                            <label for="message">Nhận xét</label>
                            <textarea name="message" id="message" rows="4" maxlength="255" required
                                placeholder="Điều bạn thích nhất, điều bạn muốn người sau biết..."></textarea>
                            <button type="submit" class="td-btn td-btn--primary" id="submit-reviews"
                                data-url-checkBooking="{{ route('checkBooking') }}"
                                data-tourId-reviews="{{ $tourDetail->tourId }}">
                                Gửi đánh giá
                            </button>
                        </form>
                    </div>
                </section>
            </div>

            {{-- ===== THẺ ĐẶT TOUR (dính khi cuộn) ===== --}}
            <aside class="px-side">
                <div class="px-book" id="tdBook">
                    <div class="px-book__head">
                        <h2>Đặt tour ngay</h2>
                        <p>Giữ chỗ trước, thanh toán sau</p>
                    </div>

                    <div class="px-book__body">
                        <div class="px-book__price">
                            <b>{{ number_format($tourDetail->priceAdult, 0, ',', '.') }}đ</b>
                            <span>/ người lớn</span>
                        </div>
                        <p class="px-book__child">Trẻ em: {{ number_format($tourDetail->priceChild, 0, ',', '.') }}đ /
                            người</p>

                        <dl class="px-book__list">
                            <div>
                                <dt>Khởi hành</dt>
                                <dd>{{ $start->format('d/m/Y') }}</dd>
                            </div>
                            <div>
                                <dt>Kết thúc</dt>
                                <dd>{{ $end->format('d/m/Y') }}</dd>
                            </div>
                            <div>
                                <dt>Thời gian</dt>
                                <dd>{{ $tourDetail->time }}</dd>
                            </div>
                            <div>
                                <dt>Số chỗ</dt>
                                <dd class="{{ $tourDetail->quantity <= 10 ? 'is-low' : '' }}">
                                    @if ($tourDetail->quantity <= 0)
                                        Đã hết
                                    @elseif ($tourDetail->quantity <= 10)
                                        Chỉ còn {{ $tourDetail->quantity }} chỗ
                                    @else
                                        Còn chỗ
                                    @endif
                                </dd>
                            </div>
                        </dl>

                        @if ($daysToGo >= 0 && $daysToGo <= 30)
                            <p class="px-book__soon">
                                <i class="fal fa-hourglass-half" aria-hidden="true"></i>
                                {{ $daysToGo === 0 ? 'Khởi hành hôm nay' : 'Khởi hành sau ' . $daysToGo . ' ngày' }}
                            </p>
                        @endif

                        <form id="tdBookForm" action="{{ route('booking', ['id' => $tourDetail->tourId]) }}"
                            method="POST" data-adult="{{ (int) $tourDetail->priceAdult }}"
                            data-child="{{ (int) $tourDetail->priceChild }}"
                            data-max="{{ max(1, min(50, (int) $tourDetail->quantity)) }}">
                            @csrf

                            @unless ($soldOut)
                                {{-- Chọn số khách ngay tại đây: tạm tính hiện tức thì, số khách mang sang trang đặt tour --}}
                                <div class="px-guests">
                                    <div class="px-guest" role="group" aria-labelledby="tdLblAdults">
                                        <div class="px-guest__txt"><b id="tdLblAdults">Người lớn</b><small>Từ 12
                                                tuổi</small></div>
                                        <div class="px-stepper">
                                            <button type="button" data-step="-1" data-for="adults"
                                                aria-label="Giảm số người lớn">−</button>
                                            <output id="tdAdultsOut" aria-live="polite">1</output>
                                            <button type="button" data-step="1" data-for="adults"
                                                aria-label="Tăng số người lớn">+</button>
                                            <input type="hidden" name="adults" id="tdAdults" value="1">
                                        </div>
                                    </div>
                                    <div class="px-guest" role="group" aria-labelledby="tdLblChildren">
                                        <div class="px-guest__txt"><b id="tdLblChildren">Trẻ em</b><small>Dưới 12
                                                tuổi</small></div>
                                        <div class="px-stepper">
                                            <button type="button" data-step="-1" data-for="children"
                                                aria-label="Giảm số trẻ em">−</button>
                                            <output id="tdChildrenOut" aria-live="polite">0</output>
                                            <button type="button" data-step="1" data-for="children"
                                                aria-label="Tăng số trẻ em">+</button>
                                            <input type="hidden" name="children" id="tdChildren" value="0">
                                        </div>
                                    </div>
                                    <div class="px-estimate">
                                        <span>Tạm tính</span>
                                        <b id="tdTotal">{{ number_format($tourDetail->priceAdult, 0, ',', '.') }}đ</b>
                                    </div>
                                    <p class="px-hint" id="tdNote" aria-live="polite">1 khách</p>
                                </div>
                            @endunless

                            <button type="submit" class="px-btn px-btn--cta"
                                @if ($soldOut) disabled @endif>
                                {{ $soldOut ? 'Tour đã đóng đặt chỗ' : 'Đặt ngay' }}
                            </button>

                            @if ($soldOut)
                                <p class="px-hint">Tour này đã đóng đặt chỗ. <a href="{{ route('tours') }}">Xem tour
                                        khác</a>
                                    hoặc <a href="{{ route('contact') }}">nhắn cho chúng tôi</a> để được tư vấn.</p>
                            @elseif (!session()->has('userId'))
                                <p class="px-hint"><i class="fal fa-lock" aria-hidden="true"></i> Bạn sẽ được nhắc
                                    đăng
                                    nhập khi đặt tour, và quay lại đúng chỗ này sau khi đăng nhập.</p>
                            @endif
                        </form>

                        <a class="px-book__help" href="{{ route('contact') }}"><i class="fal fa-comment-dots"
                                aria-hidden="true"></i> Cần tư vấn? Nhắn cho chúng tôi</a>
                    </div>
                </div>

                <div class="px-promise">
                    <h3>Cam kết của chúng tôi</h3>
                    <ul>
                        <li><i class="fal fa-calendar-check" aria-hidden="true"></i>Giữ chỗ {{ $holdHours }} giờ
                            để bạn
                            hoàn tất thanh toán</li>
                        <li><i class="fal fa-undo" aria-hidden="true"></i>Tự hủy được khi còn hơn {{ $cancelDays }}
                            ngày
                            trước ngày khởi hành</li>
                        <li><i class="fal fa-headset" aria-hidden="true"></i>Hỗ trợ ngay trên website, không cần chờ
                            email</li>
                    </ul>
                </div>
            </aside>
        </div>

        {{-- Tour gợi ý --}}
        @if (isset($related) && $related->count())
            <section class="px-related" aria-labelledby="tdRelated">
                <div class="px-sec__head">
                    <h2 class="px-h2" id="tdRelated">Có thể bạn cũng quan tâm</h2>
                    <a class="px-link" href="{{ route('tours') }}">Xem tất cả tour</a>
                </div>
                <div class="tv-grid">
                    @foreach ($related as $tour)
                        @include('clients.partials.tour-card', ['tour' => $tour])
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</main>

{{-- Thanh đặt tour cố định trên điện thoại (JS cũ travel-detail.js tự ẩn khi thẻ đặt tour đã hiện) --}}
<div class="td-mbar" id="tdMbar">
    <div>
        <small>Giá từ</small>
        <b>{{ number_format($tourDetail->priceAdult, 0, ',', '.') }}đ</b>
    </div>
    <button type="submit" form="tdBookForm" class="td-btn td-btn--cta"
        @if ($soldOut) disabled @endif>
        {{ $soldOut ? 'Đã đóng' : 'Đặt tour' }}
    </button>
</div>

<script src="{{ asset('clients/assets/js/travel-detail.js') }}?v=1"></script>
<script src="{{ asset('clients/assets/js/travel-pages.js') }}?v=1"></script>

@include('clients.blocks.new_letter')
@include('clients.blocks.footer')
