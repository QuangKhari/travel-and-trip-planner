@include('clients.blocks.header')

@php
    $images = $tourDetail->images->isEmpty() ? collect(['default.jpg']) : $tourDetail->images;
    $imgCount = $images->count();

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
@endphp

<main class="td-page">
    <div class="container">

        {{-- Đầu trang: đường dẫn, tiêu đề, thông tin nhanh --}}
        <nav class="td-crumb" aria-label="breadcrumb">
            <a href="{{ route('home') }}">Trang chủ</a>
            <span>/</span>
            <a href="{{ route('tours') }}">Tours</a>
            <span>/</span>
            <span aria-current="page">{{ $tourDetail->destination }}</span>
        </nav>

        <header class="td-head">
            <div>
                <h1>{{ $tourDetail->title }}</h1>
                <ul class="td-meta">
                    <li><i class="fal fa-map-marker-alt"></i>{{ $tourDetail->destination }}</li>
                    <li><i class="fal fa-clock"></i>{{ $tourDetail->time }}</li>
                    @if ($reviewCount > 0)
                        <li><a href="#danh-gia"><i class="fas fa-star td-star"></i><b>{{ number_format($avg, 1) }}</b>
                                ({{ $reviewCount }} đánh giá)</a></li>
                    @else
                        <li class="td-muted">Chưa có đánh giá</li>
                    @endif
                </ul>
            </div>
            <button type="button" class="td-share" data-share data-title="{{ $tourDetail->title }}">
                <i class="fal fa-share-alt"></i><span>Chia sẻ</span>
            </button>
        </header>

        {{-- Thư viện ảnh --}}
        <div class="td-gallery td-gallery--{{ min($imgCount, 5) }}" data-gallery>
            @foreach ($images->take(5) as $i => $img)
                <button type="button" class="td-gallery__item" data-index="{{ $i }}"
                    data-full="{{ \App\Support\TourImage::url($img, 1600) }}"
                    aria-label="Xem ảnh {{ $i + 1 }} / {{ $imgCount }}">
                    <img src="{{ \App\Support\TourImage::url($img, $i === 0 ? 1200 : 800) }}"
                        alt="{{ $tourDetail->title }} – ảnh {{ $i + 1 }}"
                        @if ($i > 0) loading="lazy" decoding="async" @endif>
                </button>
            @endforeach
            @if ($imgCount > 1)
                <span class="td-gallery__count"><i class="fal fa-images"></i> Xem {{ $imgCount }} ảnh</span>
            @endif
        </div>

        <div class="td-layout">
            <div class="td-main">

                <nav class="td-tabs" id="tdTabs" aria-label="Các phần của trang">
                    <a href="#tong-quan" class="is-on">Tổng quan</a>
                    <a href="#lich-trinh">Lịch trình</a>
                    <a href="#ban-do">Bản đồ</a>
                    <a href="#danh-gia">Đánh giá</a>
                </nav>

                {{-- Tổng quan --}}
                <section class="td-section" id="tong-quan">
                    <h2 class="td-h2">Về hành trình này</h2>
                    <dl class="td-facts">
                        <div>
                            <dt>Khởi hành</dt>
                            <dd>{{ $start->format('d/m/Y') }} <small>{{ $weekdays[$start->dayOfWeek] }}</small></dd>
                        </div>
                        <div>
                            <dt>Kết thúc</dt>
                            <dd>{{ $end->format('d/m/Y') }} <small>{{ $weekdays[$end->dayOfWeek] }}</small></dd>
                        </div>
                        <div>
                            <dt>Thời gian</dt>
                            <dd>{{ $tourDetail->time }}</dd>
                        </div>
                        <div>
                            <dt>Điểm đến</dt>
                            <dd>{{ $tourDetail->destination }}</dd>
                        </div>
                    </dl>
                    <div class="td-prose">{!! $tourDetail->description !!}</div>
                </section>

                {{-- Lịch trình --}}
                <section class="td-section" id="lich-trinh">
                    <div class="td-section__head">
                        <h2 class="td-h2">Lịch trình từng ngày</h2>
                        @if ($timelineCount > 1)
                            <button type="button" class="td-link" id="tdToggleAll" data-open="false">Mở tất cả</button>
                        @endif
                    </div>

                    @if ($timelineCount > 0)
                        <ol class="td-days">
                            @foreach ($tourDetail->timeline as $i => $timeline)
                                <li>
                                    <details class="td-day" id="ngay-{{ $i + 1 }}"
                                        @if ($i === 0) open @endif>
                                        <summary>
                                            <span class="td-day__no">Ngày {{ $i + 1 }}</span>
                                            <span class="td-day__title">{{ $timeline->title }}</span>
                                            <i class="fal fa-chevron-down" aria-hidden="true"></i>
                                        </summary>
                                        <div class="td-prose td-day__body">{!! $timeline->description !!}</div>
                                    </details>
                                </li>
                            @endforeach
                        </ol>
                    @else
                        <p class="td-muted">Lịch trình chi tiết đang được cập nhật. Bạn có thể <a
                                href="{{ route('contact') }}">liên hệ</a> để được tư vấn trước khi đặt.</p>
                    @endif
                </section>

                {{-- Bản đồ --}}
                <section class="td-section" id="ban-do">
                    <h2 class="td-h2">Điểm đến trên bản đồ</h2>
                    <div class="td-map">
                        <iframe title="Bản đồ {{ $tourDetail->destination }}" loading="lazy"
                            referrerpolicy="no-referrer-when-downgrade"
                            src="https://www.google.com/maps?q={{ urlencode($tourDetail->destination . ', Việt Nam') }}&output=embed&hl=vi"></iframe>
                    </div>
                    <a class="td-link" target="_blank" rel="noopener"
                        href="https://www.google.com/maps/search/?api=1&query={{ urlencode($tourDetail->destination . ', Việt Nam') }}">Mở
                        trong Google Maps <i class="fal fa-external-link"></i></a>
                </section>

                {{-- Đánh giá --}}
                <section class="td-section" id="danh-gia">
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

            {{-- Thẻ đặt tour (dính khi cuộn) --}}
            <aside class="td-side">
                <div class="td-book" id="tdBook">
                    <div class="td-book__price">
                        <small>Giá từ</small>
                        <b>{{ number_format($tourDetail->priceAdult, 0, ',', '.') }}đ</b>
                        <span>/ người lớn</span>
                    </div>
                    <p class="td-book__child">Trẻ em: {{ number_format($tourDetail->priceChild, 0, ',', '.') }}đ /
                        người</p>

                    <dl class="td-book__list">
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
                        <p class="td-book__soon">
                            {{ $daysToGo === 0 ? 'Khởi hành hôm nay' : 'Khởi hành sau ' . $daysToGo . ' ngày' }}</p>
                    @endif

                    <form id="tdBookForm" action="{{ route('booking', ['id' => $tourDetail->tourId]) }}"
                        method="POST">
                        @csrf
                        <button type="submit" class="td-btn td-btn--cta"
                            @if ($soldOut) disabled @endif>
                            {{ $soldOut ? 'Tour đã đóng đặt chỗ' : 'Đặt tour' }}
                        </button>
                    </form>

                    @unless ($soldOut)
                        <p class="td-book__hint">Đơn đặt được giữ chỗ {{ $holdHours }} giờ để bạn hoàn tất thanh toán.
                        </p>
                    @endunless
                    <a class="td-book__help" href="{{ route('contact') }}">Cần tư vấn trước khi đặt? Liên hệ chúng
                        tôi</a>
                </div>
            </aside>
        </div>

        {{-- Tour gợi ý --}}
        @if (isset($related) && $related->count())
            <section class="td-related" aria-labelledby="tdRelated">
                <div class="td-section__head">
                    <h2 class="td-h2" id="tdRelated">Có thể bạn cũng quan tâm</h2>
                    <a class="td-link" href="{{ route('tours') }}">Xem tất cả tour</a>
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

{{-- Thanh đặt tour cố định trên điện thoại --}}
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

@include('clients.blocks.new_letter')
@include('clients.blocks.footer')
