{{-- Thẻ tour dùng chung (trang /tours, /search, "tour gợi ý" ở trang chi tiết). Biến: $tour --}}
@php
    $daysToGo = (int) now()
        ->startOfDay()
        ->diffInDays(\Carbon\Carbon::parse($tour->startDate)->startOfDay(), false);
    $qty = (int) $tour->quantity;
    $hasRate = !empty($tour->rating);
    $cnt = (int) ($tour->ratingCount ?? 0);

    // Huy hiệu: chỉ hiện khi có căn cứ thật trong dữ liệu
    if ($qty <= 5) {
        $badge = ['Sắp hết chỗ', 'is-hot'];
    } elseif ($daysToGo >= 0 && $daysToGo <= 14) {
        $badge = ['Sắp khởi hành', ''];
    } elseif ($hasRate && $tour->rating >= 4.8 && $cnt >= 3) {
        $badge = ['Được yêu thích', 'is-green'];
    } else {
        $badge = null;
    }
@endphp
<article class="wt-card">
    <div class="wt-card__img">
        @if ($badge)
            <span class="wt-badge {{ $badge[1] }}">{{ $badge[0] }}</span>
        @endif
        <button type="button" class="wt-heart" data-fav="{{ $tour->tourId }}" aria-pressed="false"
            aria-label="Lưu tour vào danh sách yêu thích"><i class="fas fa-heart" aria-hidden="true"></i></button>
        <img loading="lazy" decoding="async" width="800" height="600"
            src="{{ \App\Support\TourImage::url($tour->images->first() ?? 'default.jpg', 800) }}"
            alt="{{ $tour->title }}">
    </div>
    <div class="wt-card__body">
        <p class="wt-card__place"><i class="fas fa-map-marker-alt" aria-hidden="true"></i>{{ $tour->destination }}</p>
        <h3 class="wt-card__title"><a href="{{ route('tour-detail', ['id' => $tour->tourId]) }}">{{ $tour->title }}</a>
        </h3>
        <div class="wt-card__rate">
            @if ($hasRate)
                <i class="fas fa-star" aria-hidden="true"></i><b>{{ number_format($tour->rating, 1) }}</b>
                <span>({{ $cnt }} đánh giá)</span>
            @else
                <span class="is-new">Tour mới · chưa có đánh giá</span>
            @endif
        </div>
        <ul class="wt-card__meta">
            <li><i class="fal fa-clock" aria-hidden="true"></i>{{ $tour->time }}</li>
            <li><i class="fal fa-calendar-alt"
                    aria-hidden="true"></i>{{ \Carbon\Carbon::parse($tour->startDate)->format('d/m/Y') }}</li>
            <li class="{{ $qty <= 10 ? 'is-low' : '' }}"><i class="fal fa-users" aria-hidden="true"></i>Còn
                {{ $qty }} chỗ</li>
        </ul>
        <div class="wt-card__price">
            <small>Từ</small>
            <b>{{ number_format($tour->priceAdult, 0, ',', '.') }}đ</b>
            <span>/ người</span>
        </div>
        <span class="wt-card__cta" aria-hidden="true">Xem chi tiết <i class="fas fa-arrow-right"></i></span>
    </div>
</article>
