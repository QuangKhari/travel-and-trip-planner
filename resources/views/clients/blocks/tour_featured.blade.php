{{-- Tour nổi bật — dùng biến $tours do HomeController truyền vào --}}
<section class="tv-featured">
    <div class="container">
        <span class="tv-tag"><i class="fas fa-suitcase-rolling"></i> Tour nổi bật</span>
        <div class="tv-head" data-aos="fade-up" data-aos-duration="900" data-aos-offset="30">
            <div>
                <h2>Những hành trình được yêu thích nhất</h2>
                <p>Hàng nghìn du khách đã lựa chọn và trải nghiệm. Bạn thì sao?</p>
            </div>
            <a href="{{ route('tours') }}" class="tv-all">Xem tất cả tour <i class="fal fa-arrow-right"></i></a>
        </div>

        <div class="tv-grid">
            @foreach ($tours as $i => $tour)
                @php
                    // Nhãn góc ảnh — tự suy ra từ dữ liệu có sẵn
                    $daysLeft = \Carbon\Carbon::parse($tour->startDate)->diffInDays(now(), true);
                    if ($tour->quantity <= 5) {
                        $badge = ['Sắp hết chỗ', 'is-red'];
                    } elseif ($daysLeft <= 14) {
                        $badge = ['Sắp khởi hành', ''];
                    } else {
                        $badge = ['Nổi bật', 'is-green'];
                    }
                @endphp

                <article class="tv-card" data-aos="fade-up" data-aos-duration="900"
                    data-aos-delay="{{ ($i % 3) * 100 }}" data-aos-offset="40">
                    <div class="tv-card__img">
                        <span class="tv-badge {{ $badge[1] }}">{{ $badge[0] }}</span>
                        <button type="button" class="tv-heart" aria-label="Yêu thích"><i
                                class="fas fa-heart"></i></button>
                        <a href="{{ route('tour-detail', ['id' => $tour->tourId]) }}">
                            <img loading="lazy" decoding="async"
                                src="{{ \App\Support\TourImage::url($tour->images[0] ?? null, 800) }}"
                                alt="{{ $tour->title }}">
                        </a>
                        @if (!empty($tour->rating))
                            <span class="tv-rating"><i class="fas fa-star"></i>{{ number_format($tour->rating, 1) }}
                                ({{ $tour->ratingCount }})</span>
                        @endif
                    </div>

                    <div class="tv-card__body">
                        <h3><a href="{{ route('tour-detail', ['id' => $tour->tourId]) }}">{{ $tour->title }}</a></h3>
                        <div class="tv-meta">
                            <span><i class="fal fa-map-marker-alt"></i>{{ $tour->destination }}</span>
                            <span><i class="fal fa-clock"></i>{{ $tour->time }}</span>
                            <span><i
                                    class="fal fa-calendar-alt"></i>{{ \Carbon\Carbon::parse($tour->startDate)->format('d/m/Y') }}</span>
                            <span><i class="fal fa-users"></i>Còn {{ $tour->quantity }} chỗ</span>
                        </div>
                        <div class="tv-price">
                            <div>
                                <small>Từ</small>
                                <b>{{ number_format($tour->priceAdult, 0, ',', '.') }}đ <span>/người</span></b>
                            </div>
                            <a href="{{ route('tour-detail', ['id' => $tour->tourId]) }}" class="tv-go"
                                aria-label="Xem chi tiết">
                                <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    </div>
</section>
