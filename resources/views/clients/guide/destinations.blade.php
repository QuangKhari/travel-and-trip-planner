@php
    $metaDescription = 'Tất cả điểm đến ở Việt Nam: lọc theo miền, tỉnh, cảm hứng và tháng đẹp nhất để đi.';
@endphp
@include('clients.blocks.header')
{{-- /diem-den/tat-ca — biến: $items (phân trang), $filters, $total, $regions, $provinces, $inspirations, $months --}}
<main class="gx-page" id="main">
    <div class="gx-wrap">
        @include('clients.partials.gx.crumb', ['crumbs' => [['Trang chủ', route('home')], ['Điểm đến', route('destination')], ['Tất cả', null]]])
        <header class="gx-head">
            <p class="gx-eyebrow">Khám phá Việt Nam</p>
            <h1>Tất cả điểm đến</h1>
            <p class="gx-lead">Lọc theo miền, tỉnh, cảm hứng hoặc tháng đẹp nhất để đi.</p>
        </header>
        @include('clients.partials.gx.filters', [
            'action' => route('destinations.list'),
            'filters' => $filters,
            'search' => 'Tìm điểm đến',
            'fields' => [['mien', 'Miền', $regions], ['tinh', 'Tỉnh/thành', $provinces], ['camhung', 'Cảm hứng', $inspirations], ['thang', 'Đẹp nhất vào', $months]],
        ])
        <p class="gx-count" aria-live="polite">{{ $total }} điểm đến</p>
        @if ($items->isEmpty())
            <div class="gx-empty">Không có điểm đến nào khớp bộ lọc. <a href="{{ route('destinations.list') }}">Xóa bộ lọc</a></div>
        @else
            <div class="gx-grid">
                @foreach ($items as $c)
                    @include('clients.partials.gx.card', ['c' => $c, 'loading' => $loop->index < 3 ? 'eager' : 'lazy'])
                @endforeach
            </div>
            @include('clients.partials.gx.pager', ['items' => $items])
        @endif
    </div>
</main>
@include('clients.blocks.footer')
