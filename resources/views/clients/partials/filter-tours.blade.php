{{-- Kết quả danh sách tour: dùng cho lần tải đầu và cho AJAX (/filter-tours). Biến: $tours (paginator) --}}
@if ($tours->count() === 0)
    <div class="tv-empty">
        <div class="tv-empty__icon"><i class="fal fa-compass"></i></div>
        <h3>Chưa tìm thấy tour phù hợp</h3>
        <p>Thử bỏ bớt bộ lọc, mở rộng khoảng giá hoặc tìm bằng từ khóa khác (ví dụ: Đà Nẵng, Phú Quốc, biển).</p>
        <button type="button" class="tv-btn-solid" data-action="reset">Xóa tất cả bộ lọc</button>
    </div>
@else
    <div class="tv-grid">
        @foreach ($tours as $tour)
            @include('clients.partials.tour-card', ['tour' => $tour])
        @endforeach
    </div>

    @if ($tours->lastPage() > 1)
        @php
            $cur = $tours->currentPage();
            $last = $tours->lastPage();
            $from = max(1, $cur - 2);
            $to = min($last, $cur + 2);
        @endphp
        <nav class="tv-pager" aria-label="Phân trang">
            @if ($cur > 1)
                <a href="{{ $tours->url($cur - 1) }}" data-page="{{ $cur - 1 }}" aria-label="Trang trước"><i
                        class="far fa-chevron-left"></i></a>
            @endif
            @if ($from > 1)
                <a href="{{ $tours->url(1) }}" data-page="1">1</a>
                @if ($from > 2)
                    <span class="tv-pager__dots">…</span>
                @endif
            @endif
            @for ($p = $from; $p <= $to; $p++)
                <a href="{{ $tours->url($p) }}" data-page="{{ $p }}"
                    class="{{ $p === $cur ? 'is-active' : '' }}"
                    @if ($p === $cur) aria-current="page" @endif>{{ $p }}</a>
            @endfor
            @if ($to < $last)
                @if ($to < $last - 1)
                    <span class="tv-pager__dots">…</span>
                @endif
                <a href="{{ $tours->url($last) }}" data-page="{{ $last }}">{{ $last }}</a>
            @endif
            @if ($cur < $last)
                <a href="{{ $tours->url($cur + 1) }}" data-page="{{ $cur + 1 }}" aria-label="Trang sau"><i
                        class="far fa-chevron-right"></i></a>
            @endif
        </nav>
    @endif
@endif
