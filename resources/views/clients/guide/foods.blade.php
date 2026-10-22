@include('clients.blocks.header')
{{-- /am-thuc — biến: $items, $filters, $total, $regions, $groups --}}
<main class="gx-page" id="main">
    <div class="gx-wrap">
        @include('clients.partials.gx.crumb', ['crumbs' => [['Trang chủ', route('home')], ['Ẩm thực', null]]])
        <header class="gx-head">
            <p class="gx-eyebrow">Nhớ một vùng đất bằng hương vị</p>
            <h1>Ẩm thực</h1>
            <p class="gx-lead">Đặc sản từng vùng, đã được biên tập và có ghi nguồn.</p>
        </header>
        @include('clients.partials.gx.filters', [
            'action' => route('foods.index'),
            'filters' => $filters,
            'search' => 'Tìm món',
            'fields' => [['mien', 'Miền', $regions], ['nhom', 'Nhóm món', $groups]],
        ])
        <p class="gx-count" aria-live="polite">{{ $total }} món</p>
        @if ($items->isEmpty())
            <div class="gx-empty">Chưa có món nào khớp. Nội dung chỉ hiện sau khi được duyệt.</div>
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
