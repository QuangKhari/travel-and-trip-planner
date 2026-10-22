@include('clients.blocks.header')
{{-- /lich-trinh — biến: $items, $filters, $total, $regions, $dayOptions --}}
<main class="gx-page" id="main">
    <div class="gx-wrap">
        @include('clients.partials.gx.crumb', ['crumbs' => [['Trang chủ', route('home')], ['Lịch trình', null]]])
        <header class="gx-head">
            <p class="gx-eyebrow">Gợi ý theo ngày</p>
            <h1>Lịch trình gợi ý</h1>
            <p class="gx-lead">Chỉ gồm địa điểm đã có trong cẩm nang. Dùng làm điểm xuất phát để chỉnh theo ý bạn.</p>
        </header>
        @include('clients.partials.gx.filters', [
            'action' => route('itineraries.index'),
            'filters' => $filters,
            'search' => null,
            'fields' => [['mien', 'Miền', $regions], ['songay', 'Số ngày', $dayOptions]],
        ])
        <p class="gx-count" aria-live="polite">{{ $total }} lịch trình</p>
        @if ($items->isEmpty())
            <div class="gx-empty">Chưa có lịch trình nào khớp. Nội dung chỉ hiện sau khi được duyệt.</div>
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
