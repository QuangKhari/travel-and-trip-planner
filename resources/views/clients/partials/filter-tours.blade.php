@foreach ($tours as $tour)
    @php
        $isLow = $tour->quantity <= 5;
    @endphp
    <div class="col-xl-4 col-md-6" style="margin-bottom: 30px">
        <article class="tv-card h-100">
            <div class="tv-card__img">
                @if ($isLow)
                    <span class="tv-badge is-red">Sắp hết chỗ</span>
                @endif
                <button type="button" class="tv-heart" aria-label="Yêu thích"><i class="fas fa-heart"></i></button>
                <a href="{{ route('tour-detail', ['id' => $tour->tourId]) }}">
                    <img loading="lazy" decoding="async"
                        src="{{ \App\Support\TourImage::url($tour->images->first() ?? 'default.jpg', 800) }}"
                        alt="{{ $tour->title }}">
                </a>
                @if (!empty($tour->rating))
                    <span class="tv-rating"><i class="fas fa-star"></i>{{ number_format($tour->rating, 1) }}</span>
                @endif
            </div>
            <div class="tv-card__body">
                <h3><a href="{{ route('tour-detail', ['id' => $tour->tourId]) }}">{{ $tour->title }}</a></h3>
                <div class="tv-meta">
                    <span><i class="fal fa-map-marker-alt"></i>{{ $tour->destination }}</span>
                    <span><i class="fal fa-clock"></i>{{ $tour->time }}</span>
                    <span><i class="fal fa-users"></i>Còn {{ $tour->quantity }} chỗ</span>
                </div>
                <div class="tv-price">
                    <div>
                        <small>Từ</small>
                        <b>{{ number_format($tour->priceAdult, 0, ',', '.') }}đ <span>/người</span></b>
                    </div>
                    <a href="{{ route('tour-detail', ['id' => $tour->tourId]) }}" class="tv-go"
                        aria-label="Xem chi tiết"><i class="fas fa-arrow-right"></i></a>
                </div>
            </div>
        </article>
    </div>
@endforeach

{{-- Pagination - chỉ hiện khi là LengthAwarePaginator --}}
@if ($tours instanceof \Illuminate\Pagination\LengthAwarePaginator)
    <div class="col-lg-12">
        <ul class="pagination justify-content-center pt-15 flex-wrap pagination-tours" data-aos="fade-up"
            data-aos-duration="1500" data-aos-offset="50">
            @if ($tours->onFirstPage())
                <li class="page-item disabled">
                    <span class="page-link"><i class="far fa-chevron-left"></i></span>
                </li>
            @else
                <li class="page-item">
                    <a class="page-link" href="{{ $tours->previousPageUrl() }}"><i class="far fa-chevron-left"></i></a>
                </li>
            @endif

            @for ($i = 1; $i <= $tours->lastPage(); $i++)
                <li class="page-item @if ($i == $tours->currentPage()) active @endif">
                    <a class="page-link" href="{{ $tours->url($i) }}">{{ $i }}</a>
                </li>
            @endfor

            @if ($tours->hasMorePages())
                <li class="page-item">
                    <a class="page-link" href="{{ $tours->nextPageUrl() }}"><i class="far fa-chevron-right"></i></a>
                </li>
            @else
                <li class="page-item disabled">
                    <span class="page-link"><i class="far fa-chevron-right"></i></span>
                </li>
            @endif
        </ul>
    </div>
@endif
<style>
    .pagination-tours {
        display: flex !important;
        visibility: visible !important;
        opacity: 1 !important;
    }
</style>
