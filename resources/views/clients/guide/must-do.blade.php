@php
    $metaDescription = 'Những nơi, món ăn, trải nghiệm và lễ hội nhất định phải thử ở Việt Nam, do biên tập chọn và ghi nguồn.';
@endphp
@include('clients.blocks.header')
{{-- /phai-thu — biến: $groups, $filters, $total, $labels, $regions, $destinations, $perGroup --}}
<main class="gx-page" id="main">
    <div class="gx-wrap">
        @include('clients.partials.gx.crumb', ['crumbs' => [['Trang chủ', route('home')], ['Phải thử', null]]])
        <header class="gx-head">
            <p class="gx-eyebrow">Biên tập chọn</p>
            <h1>Nhất định phải thử</h1>
            <p class="gx-lead">Nơi nên đến, món nên ăn, việc nên làm và lễ hội nên dự. Nhãn "Phải thử" do biên tập viên đặt thủ công cho từng mục, không tự suy ra. Lễ hội chỉ hiện khi còn ngày sắp tới.</p>
        </header>

        <nav class="gx-chips" aria-label="Lọc theo miền">
            <a class="gx-chip {{ empty($filters['mien']) ? 'is-on' : '' }}" href="{{ request()->fullUrlWithQuery(['mien' => null]) }}">Tất cả miền</a>
            @foreach ($regions as $code => $label)
                <a class="gx-chip {{ ($filters['mien'] ?? '') === $code ? 'is-on' : '' }}" href="{{ request()->fullUrlWithQuery(['mien' => $code]) }}">{{ $label }}</a>
            @endforeach
        </nav>
        <nav class="gx-chips" aria-label="Lọc theo loại">
            <a class="gx-chip {{ empty($filters['loai']) ? 'is-on' : '' }}" href="{{ request()->fullUrlWithQuery(['loai' => null]) }}">Tất cả loại</a>
            @foreach ($labels as $code => $label)
                <a class="gx-chip {{ ($filters['loai'] ?? '') === $code ? 'is-on' : '' }}" href="{{ request()->fullUrlWithQuery(['loai' => $code]) }}">{{ $label }}</a>
            @endforeach
        </nav>
        @include('clients.partials.gx.filters', [
            'action' => route('mustdo.index'),
            'filters' => $filters,
            'search' => null,
            'fields' => [['diemden', 'Điểm đến', $destinations]],
            'hidden' => ['mien' => $filters['mien'] ?? null, 'loai' => $filters['loai'] ?? null],
        ])

        @if ($total === 0)
            <div class="gx-empty">Chưa có mục nào được đánh dấu "Phải thử" khớp bộ lọc này. Nội dung chỉ hiện sau khi được duyệt.</div>
        @else
            @foreach ($groups as $code => $items)
                @if (count($items))
                    <section class="gx-box" id="{{ $code }}">
                        <h2>{{ $labels[$code] }}</h2>
                        <div class="gx-grid">
                            @foreach ($items as $c)
                                @include('clients.partials.gx.card', ['c' => $c])
                            @endforeach
                        </div>
                        @if (count($items) >= ($filters['loai'] ? $perGroup * 4 : $perGroup))
                            <p class="gx-more">Còn nhiều mục khác: dùng bộ lọc miền hoặc điểm đến để xem thêm.</p>
                        @endif
                    </section>
                @endif
            @endforeach
        @endif
    </div>
</main>
@include('clients.blocks.footer')
