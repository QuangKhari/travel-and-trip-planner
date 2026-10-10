{{-- Thẻ tour dùng chung (trang /tours, trang tìm kiếm). Biến: $tour --}}
@php
    $daysToGo = \Carbon\Carbon::parse($tour->startDate)
        ->startOfDay()
        ->diffInDays(now()->startOfDay(), true);
    if ($tour->quantity <= 5) {
        $badge = ['Sắp hết chỗ', 'is-red'];
    } elseif ($daysToGo <= 14) {
        $badge = ['Sắp khởi hành', ''];
    } else {
        $badge = null;
    }
@endphp
<article class="tv-card">
    <div class="tv-card__img">
        @if ($badge)
            <span class="tv-badge {{ $badge[1] }}">{{ $badge[0] }}</span>
        @endif
        <button type="button" class="tv-heart" aria-label="Yêu thích"><i class="fas fa-heart"></i></button>
        <img loading="lazy" decoding="async"
            src="{{ \App\Support\TourImage::url($tour->images->first() ?? 'default.jpg', 800) }}"
            alt="{{ $tour->title }}">
        @if (!empty($tour->rating))
            <span class="tv-rating"><i class="fas fa-star"></i>{{ number_format($tour->rating, 1) }}@if (!empty($tour->ratingCount))
                    ({{ $tour->ratingCount }})
                @endif
            </span>
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
            <span class="tv-go" aria-hidden="true"><i class="fas fa-arrow-right"></i></span>
        </div>
    </div>
</article>
