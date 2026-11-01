{{-- Một trang đánh giá (dùng ở trang chi tiết tour và "Xem thêm đánh giá"). Biến: $getReviews --}}
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
