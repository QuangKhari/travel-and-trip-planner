{{-- Phân trang gọn cho admin: {{ $rows->links('admin.partials.pager') }} --}}
@if ($paginator->hasPages())
    <div class="tv-admin-pager" style="margin:0">
        <div>
            @unless ($paginator->onFirstPage())<a href="{{ $paginator->previousPageUrl() }}" aria-label="Trang trước">‹</a>@endunless
            @for ($p = max(1, $paginator->currentPage() - 2); $p <= min($paginator->lastPage(), $paginator->currentPage() + 2); $p++)
                <a href="{{ $paginator->url($p) }}" class="{{ $p === $paginator->currentPage() ? 'is-active' : '' }}">{{ $p }}</a>
            @endfor
            @if ($paginator->hasMorePages())<a href="{{ $paginator->nextPageUrl() }}" aria-label="Trang sau">›</a>@endif
        </div>
    </div>
@endif
