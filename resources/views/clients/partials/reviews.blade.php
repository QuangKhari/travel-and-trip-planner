{{-- Đánh giá của khách. Biến: $reviewStats, $getReviews, $breakdown (cũng được trả lại qua AJAX khi gửi đánh giá) --}}
@php
    $avg = (float) ($reviewStats->averageRating ?? 0);
    $count = (int) ($reviewStats->reviewCount ?? 0);
    $breakdown = $breakdown ?? [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
    $maxStar = max(1, max($breakdown));
@endphp

<h2 class="td-h2">Đánh giá từ khách đã đi</h2>

@if ($count > 0)
    <div class="td-rsum">
        <div class="td-rsum__score">
            <b>{{ number_format($avg, 1) }}</b>
            <div class="td-stars-static" aria-label="{{ number_format($avg, 1) }} trên 5 sao">
                @for ($i = 1; $i <= 5; $i++)
                    <i class="{{ $i <= round($avg) ? 'fas' : 'far' }} fa-star"></i>
                @endfor
            </div>
            <span>{{ $count }} đánh giá</span>
        </div>
        <div class="td-rsum__bars">
            @foreach ($breakdown as $star => $n)
                <div class="td-bar">
                    <span>{{ $star }} sao</span>
                    <div class="td-bar__track">
                        <div class="td-bar__fill" style="width: {{ round(($n / $maxStar) * 100) }}%"></div>
                    </div>
                    <em>{{ $n }}</em>
                </div>
            @endforeach
        </div>
    </div>

    <div class="td-rlist" id="td-rlist">
        @include('clients.partials.review-items')
    </div>
    @if ($count > $getReviews->count() && isset($tourDetail))
        {{-- L-C-05: phân trang đánh giá --}}
        <button type="button" class="td-btn td-btn--ghost td-more-reviews" id="td-more-reviews"
            data-url="{{ route('tour-reviews', ['id' => $tourDetail->tourId]) }}" data-page="2">
            Xem thêm đánh giá ({{ $count - $getReviews->count() }})
        </button>
    @endif
@else
    <div class="td-noreview">
        <i class="fal fa-comment-alt-lines"></i>
        <p>Chưa có đánh giá nào cho tour này. Sau khi hoàn tất chuyến đi, bạn có thể là người đầu tiên chia sẻ trải
            nghiệm.</p>
    </div>
@endif
