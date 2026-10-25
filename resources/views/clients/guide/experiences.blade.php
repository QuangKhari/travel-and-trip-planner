@php
    $metaDescription = 'Trải nghiệm đáng thử ở Việt Nam theo vùng: đi thuyền, lớp học, biểu diễn, ngắm cảnh. Đã biên tập và ghi nguồn.';
@endphp
@include('clients.blocks.header')
{{-- /trai-nghiem — biến: $items, $filters, $total, $regions, $hero, $groupCounts, $provinces --}}
<main class="gx-page" id="main">
    <div class="gx-wrap">
        @include('clients.partials.gx.crumb', ['crumbs' => [['Trang chủ', route('home')], ['Trải nghiệm', null]]])
        <header class="gx-head">
            <p class="gx-eyebrow">Làm gì ở Việt Nam</p>
            <h1>Trải nghiệm</h1>
            <p class="gx-lead">Những việc đáng làm khi đến từng nơi: đi thuyền, học nấu ăn, xem biểu diễn, ngắm cảnh. Mỗi mục đều được biên tập và có ghi nguồn.</p>
        </header>
        @include('clients.partials.gx.hero-list', ['items' => $hero, 'label' => 'Trải nghiệm nổi bật'])

        <nav class="gx-chips" aria-label="Lọc theo miền">
            <a class="gx-chip {{ empty($filters['mien']) ? 'is-on' : '' }}" href="{{ request()->fullUrlWithQuery(['mien' => null, 'nhom' => null, 'page' => null]) }}">Tất cả</a>
            @foreach ($regions as $code => $label)
                <a class="gx-chip {{ ($filters['mien'] ?? '') === $code ? 'is-on' : '' }}" href="{{ request()->fullUrlWithQuery(['mien' => $code, 'nhom' => null, 'page' => null]) }}">{{ $label }}</a>
            @endforeach
        </nav>
        @if (count($groupCounts))
            <nav class="gx-chips" aria-label="Lọc theo loại trải nghiệm">
                <a class="gx-chip {{ empty($filters['nhom']) ? 'is-on' : '' }}" href="{{ request()->fullUrlWithQuery(['nhom' => null, 'page' => null]) }}">Tất cả<span class="gx-cnt">{{ array_sum($groupCounts) }}</span></a>
                @foreach ($groupCounts as $name => $n)
                    <a class="gx-chip {{ ($filters['nhom'] ?? '') === $name ? 'is-on' : '' }}" href="{{ request()->fullUrlWithQuery(['nhom' => $name, 'page' => null]) }}">{{ $name }}<span class="gx-cnt">{{ $n }}</span></a>
                @endforeach
            </nav>
        @endif
        @include('clients.partials.gx.filters', [
            'action' => route('experiences.index'),
            'filters' => $filters,
            'search' => 'Tìm trải nghiệm',
            'fields' => [['tinh', 'Tỉnh thành', $provinces]],
            'hidden' => ['mien' => $filters['mien'] ?? null, 'nhom' => $filters['nhom'] ?? null],
        ])
        <p class="gx-count" aria-live="polite">{{ $total }} trải nghiệm</p>
        @if ($items->isEmpty())
            <div class="gx-empty">Chưa có trải nghiệm nào khớp. Nội dung chỉ hiện sau khi được duyệt.</div>
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
