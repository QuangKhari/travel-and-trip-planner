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

    <div class="td-rlist">
        @foreach ($getReviews as $review)
            <article class="td-review">
                <img src="{{ \App\Support\Avatar::url($review->avatar) }}" alt="" loading="lazy">
                <div>
                    <header>
                        <h4>{{ $review->fullName }}</h4>
                        <time>{{ \Carbon\Carbon::parse($review->timestamp)->format('d/m/Y') }}</time>
                    </header>
                    <div class="td-stars-static td-stars-static--sm">
                        @for ($i = 1; $i <= 5; $i++)
                            <i class="{{ $review->rating && $i <= $review->rating ? 'fas' : 'far' }} fa-star"></i>
                        @endfor
                    </div>
                    @if (!empty($review->comment))
                        <p>{{ $review->comment }}</p>
                    @endif
                </div>
            </article>
        @endforeach
    </div>
@else
    <div class="td-noreview">
        <i class="fal fa-comment-alt-lines"></i>
        <p>Chưa có đánh giá nào cho tour này. Sau khi hoàn tất chuyến đi, bạn có thể là người đầu tiên chia sẻ trải
            nghiệm.</p>
    </div>
@endif
