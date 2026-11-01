{{-- Thẻ tour dùng chung (Trang chủ, /tours, /search, "tour gợi ý" ở trang chi tiết). Biến: $tour. CSS: tv3.css (.trc)
     Các class trc, trc-heart, trc__title, trc__img, trc__place, trc__price được ux-pass.js đọc để lưu "Tour đã lưu". --}}
@php
    $daysToGo = (int) now()
        ->startOfDay()
        ->diffInDays(\Carbon\Carbon::parse($tour->startDate)->startOfDay(), false);
    $qty = (int) $tour->quantity;
    $hasRate = !empty($tour->rating);
    $cnt = (int) ($tour->ratingCount ?? 0);
    $imgs = collect($tour->images ?? []);
    $cover = $imgs->first() ?? 'default.jpg';

    // Huy hiệu: chỉ hiện khi có căn cứ thật trong dữ liệu
    if ($qty > 0 && $qty <= 5) {
        $badge = ['Sắp hết chỗ', 'is-hot'];
    } elseif ($daysToGo >= 0 && $daysToGo <= 14) {
        $badge = ['Sắp khởi hành', ''];
    } else {
        $badge = null;
    }
@endphp
<article class="trc">
    <div class="trc__img">
        @if ($hasRate)
            <span class="trc__rate"><i class="fas fa-star" aria-hidden="true"></i>{{ number_format($tour->rating, 1) }}
                <span class="sr-only">điểm, {{ $cnt }} đánh giá</span></span>
        @else
            <span class="trc__rate is-new">Tour mới</span>
        @endif
        <button type="button" class="trc-heart" data-fav="{{ $tour->tourId }}" aria-pressed="false"
            aria-label="Lưu tour vào danh sách yêu thích"><i class="fas fa-heart" aria-hidden="true"></i></button>
        <img loading="lazy" decoding="async" width="800" height="500"
            src="{{ \App\Support\TourImage::url($cover, 800) }}" alt="{{ $tour->title }}">
        @if ($badge)
            <span class="trc__badge {{ $badge[1] }}">{{ $badge[0] }}</span>
        @endif
    </div>
    <div class="trc__body">
        <h3 class="trc__title"><a href="{{ route('tour-detail', ['id' => $tour->tourId]) }}">{{ $tour->title }}</a></h3>
        <p class="trc__place"><i class="fal fa-map-marker-alt" aria-hidden="true"></i>{{ $tour->destination }}</p>
        <ul class="trc__meta">
            <li><i class="fal fa-clock" aria-hidden="true"></i>{{ $tour->time }}</li>
            <li class="{{ $qty <= 10 ? 'is-low' : '' }}"><i class="fal fa-users" aria-hidden="true"></i>Còn
                {{ $qty }} chỗ</li>
        </ul>
        <div class="trc__foot">
            <div class="trc__price">
                <small>Giá từ</small>
                <b>{{ number_format($tour->priceAdult, 0, ',', '.') }}đ</b>
            </div>
            <span class="trc__date">Khởi hành<br>{{ \Carbon\Carbon::parse($tour->startDate)->format('d/m/Y') }}</span>
        </div>
    </div>
</article>
