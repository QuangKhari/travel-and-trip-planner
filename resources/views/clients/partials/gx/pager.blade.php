@if ($items->hasPages())
    <nav class="gx-pager" aria-label="Phân trang">
        @if ($items->onFirstPage())
            <span aria-disabled="true">‹</span>
        @else
            <a href="{{ $items->previousPageUrl() }}" rel="prev" aria-label="Trang trước">‹</a>
        @endif
        @foreach ($items->getUrlRange(max(1, $items->currentPage() - 2), min($items->lastPage(), $items->currentPage() + 2)) as $page => $url)
            @if ($page == $items->currentPage())
                <span aria-current="page">{{ $page }}</span>
            @else
                <a href="{{ $url }}">{{ $page }}</a>
            @endif
        @endforeach
        @if ($items->hasMorePages())
            <a href="{{ $items->nextPageUrl() }}" rel="next" aria-label="Trang sau">›</a>
        @else
            <span aria-disabled="true">›</span>
        @endif
    </nav>
@endif
