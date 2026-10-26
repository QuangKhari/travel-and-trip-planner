{{-- Phân trang cho danh sách. Biến: $items (LengthAwarePaginator đã giữ query lọc qua 'query'). Hiện trang đầu, cuối và 2 trang quanh trang hiện tại. --}}
@if (method_exists($items, 'hasPages') && $items->hasPages())
    @php
        $cur = $items->currentPage();
        $last = $items->lastPage();
        $pages = collect([1, $last])->merge(range(max(1, $cur - 2), min($last, $cur + 2)))->unique()->sort()->values();
        $prev = null;
    @endphp
    <nav class="gx-pager" aria-label="Phân trang">
        @if ($items->onFirstPage())
            <span aria-disabled="true">‹</span>
        @else
            <a href="{{ $items->url($cur - 1) }}" rel="prev" aria-label="Trang trước">‹</a>
        @endif
        @foreach ($pages as $p)
            @if ($prev !== null && $p - $prev > 1)
                <span aria-hidden="true">…</span>
            @endif
            @if ($p === $cur)
                <span aria-current="page">{{ $p }}</span>
            @else
                <a href="{{ $items->url($p) }}">{{ $p }}</a>
            @endif
            @php $prev = $p; @endphp
        @endforeach
        @if ($items->hasMorePages())
            <a href="{{ $items->url($cur + 1) }}" rel="next" aria-label="Trang sau">›</a>
        @else
            <span aria-disabled="true">›</span>
        @endif
    </nav>
@endif
