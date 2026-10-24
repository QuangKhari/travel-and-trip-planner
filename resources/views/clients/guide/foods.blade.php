@php
    $metaDescription = 'Ẩm thực Việt Nam theo vùng: đặc sản từng nơi, đã biên tập và ghi nguồn.';
@endphp
@include('clients.blocks.header')
{{-- /am-thuc — biến: $items, $filters, $total, $regions, $groups, $hero, $groupCounts, $provinces --}}
<main class="gx-page" id="main">
    <div class="gx-wrap">
        @include('clients.partials.gx.crumb', ['crumbs' => [['Trang chủ', route('home')], ['Ẩm thực', null]]])
        <header class="gx-head">
            <p class="gx-eyebrow">Nhớ một vùng đất bằng hương vị</p>
            <h1>Ẩm thực</h1>
            <p class="gx-lead">Đặc sản từng vùng, đã được biên tập và có ghi nguồn.</p>
        </header>
        @include('clients.partials.gx.hero-list', ['items' => $hero, 'label' => 'Món nổi bật'])

        {{-- Chip miền + chip nhóm món có số lượng (giữ q/tỉnh đang chọn, về trang 1 khi đổi) --}}
        <nav class="gx-chips" aria-label="Lọc theo miền">
            <a class="gx-chip {{ empty($filters['mien']) ? 'is-on' : '' }}" href="{{ request()->fullUrlWithQuery(['mien' => null, 'nhom' => null, 'page' => null]) }}">Tất cả</a>
            @foreach ($regions as $code => $label)
                <a class="gx-chip {{ ($filters['mien'] ?? '') === $code ? 'is-on' : '' }}" href="{{ request()->fullUrlWithQuery(['mien' => $code, 'nhom' => null, 'page' => null]) }}">{{ $label }}</a>
            @endforeach
        </nav>
        @if (count($groupCounts))
            <nav class="gx-chips" aria-label="Lọc theo nhóm món">
                <a class="gx-chip {{ empty($filters['nhom']) ? 'is-on' : '' }}" href="{{ request()->fullUrlWithQuery(['nhom' => null, 'page' => null]) }}">Tất cả<span class="gx-cnt">{{ array_sum($groupCounts) }}</span></a>
                @foreach ($groupCounts as $name => $n)
                    <a class="gx-chip {{ ($filters['nhom'] ?? '') === $name ? 'is-on' : '' }}" href="{{ request()->fullUrlWithQuery(['nhom' => $name, 'page' => null]) }}">{{ $name }}<span class="gx-cnt">{{ $n }}</span></a>
                @endforeach
            </nav>
        @endif
        @include('clients.partials.gx.filters', [
            'action' => route('foods.index'),
            'filters' => $filters,
            'search' => 'Tìm món',
            'fields' => [['tinh', 'Tỉnh thành', $provinces]],
            'hidden' => ['mien' => $filters['mien'] ?? null, 'nhom' => $filters['nhom'] ?? null],
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
