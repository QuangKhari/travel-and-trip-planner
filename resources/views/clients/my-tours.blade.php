@include('clients.blocks.header')

@php
    $statusMap = [
        'n' => ['Chờ xác nhận', 'is-wait'],
        'y' => ['Sắp khởi hành', 'is-go'],
        'f' => ['Hoàn thành', 'is-done'],
        'c' => ['Đã hủy', 'is-cancel'],
    ];
@endphp

<main class="tv-mytours">
    <div class="container">
        <header class="tv-pagehead">
            <h1>Tour đã đặt</h1>
            <p>Theo dõi trạng thái, xem chi tiết hoặc hủy đơn khi còn trong thời hạn cho phép.</p>
        </header>

        @if ($myTours->isEmpty())
            <div class="tv-empty">
                <div class="tv-empty__icon"><i class="fal fa-suitcase-rolling"></i></div>
                <h3>Bạn chưa đặt tour nào</h3>
                <p>Khi đặt tour, đơn của bạn sẽ hiện ở đây để dễ theo dõi.</p>
                <a href="{{ route('tours') }}" class="tv-btn-solid">Khám phá tour</a>
            </div>
        @else
            <div class="tv-chiprow" role="group" aria-label="Lọc theo trạng thái" id="tvStatusFilter">
                <button type="button" class="tv-chipbtn is-on" data-status="all">Tất cả
                    <span>{{ $myTours->count() }}</span></button>
                @foreach ($statusMap as $key => $info)
                    @php $n = $myTours->where('bookingStatus', $key)->count(); @endphp
                    @if ($n)
                        <button type="button" class="tv-chipbtn" data-status="{{ $key }}">{{ $info[0] }}
                            <span>{{ $n }}</span></button>
                    @endif
                @endforeach
            </div>

            <div class="tv-orders" id="tvOrders">
                @foreach ($myTours as $tour)
                    @php $info = $statusMap[$tour->bookingStatus] ?? ['Không xác định', '']; @endphp
                    <article class="tv-order" data-status="{{ $tour->bookingStatus }}">
                        <a class="tv-order__img"
                            href="{{ route('tour-booked', ['bookingId' => $tour->bookingId, 'checkoutId' => $tour->checkoutId]) }}">
                            <img loading="lazy" decoding="async"
                                src="{{ \App\Support\TourImage::url(isset($tour->images) && $tour->images->isNotEmpty() ? $tour->images->first() : 'default.jpg', 600) }}"
                                alt="{{ $tour->title }}">
                        </a>
                        <div class="tv-order__body">
                            <div class="tv-order__top">
                                <span class="tv-status-pill {{ $info[1] }}">{{ $info[0] }}</span>
                                <span class="tv-muted-sm"><i class="fal fa-map-marker-alt"></i>
                                    {{ $tour->destination }}</span>
                            </div>
                            <h2><a
                                    href="{{ route('tour-booked', ['bookingId' => $tour->bookingId, 'checkoutId' => $tour->checkoutId]) }}">{{ $tour->title }}</a>
                            </h2>
                            <ul class="tv-order__meta">
                                <li><i class="fal fa-clock"></i>{{ $tour->time }}</li>
                                <li><i class="fal fa-user"></i>{{ $tour->numAdults + $tour->numChildren }} người</li>
                            </ul>
                        </div>
                        <div class="tv-order__side">
                            <div class="tv-order__price"><small>Tổng
                                    cộng</small><b>{{ number_format($tour->totalPrice, 0, ',', '.') }}đ</b></div>
                            <div class="tv-order__actions">
                                <a href="{{ route('tour-booked', ['bookingId' => $tour->bookingId, 'checkoutId' => $tour->checkoutId]) }}"
                                    class="tv-btn-ghost tv-btn-sm">Chi tiết đơn</a>
                                @if ($tour->bookingStatus == 'f')
                                    <a href="{{ route('tour-detail', ['id' => $tour->tourId]) }}#danh-gia"
                                        class="tv-btn-solid tv-btn-sm">{{ $tour->rating ? 'Đã đánh giá' : 'Viết đánh giá' }}</a>
                                @endif
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif

        @if (isset($toursPopular) && !$toursPopular->isEmpty())
            <section class="tv-suggest">
                <h2 class="td-h2">Gợi ý cho chuyến đi tiếp theo</h2>
                <div class="tv-suggest__row">
                    @foreach ($toursPopular as $tour)
                        <a class="tv-mini tv-mini--card" href="{{ route('tour-detail', ['id' => $tour->tourId]) }}">
                            <img loading="lazy"
                                src="{{ \App\Support\TourImage::url($tour->images->first() ?? 'default.jpg', 400) }}"
                                alt="{{ $tour->title }}">
                            <span>
                                <b>{{ \Illuminate\Support\Str::limit($tour->title, 46) }}</b>
                                <small>{{ $tour->destination }}@if (!empty($tour->rating))
                                        · <i class="fas fa-star"></i> {{ number_format($tour->rating, 1) }}
                                    @endif
                                </small>
                            </span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</main>

@include('clients.blocks.footer')
