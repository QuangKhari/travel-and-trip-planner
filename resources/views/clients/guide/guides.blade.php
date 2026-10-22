@php
    $metaDescription = $category
        ? 'Cẩm nang ' . mb_strtolower($category->name) . ' khi du lịch Việt Nam: bài viết có ghi nguồn và ngày cập nhật.'
        : 'Cẩm nang du lịch Việt Nam: chuẩn bị trước chuyến đi, đi lại, tiền bạc và an toàn. Mỗi bài có ghi nguồn và ngày cập nhật lần cuối.';
@endphp
@include('clients.blocks.header')

<main class="gx-page" id="main">
    <div class="gx-wrap">
        @include('clients.partials.gx.crumb', ['crumbs' => array_values(array_filter([
            ['Trang chủ', route('home')],
            $category ? ['Cẩm nang', route('guides.index')] : ['Cẩm nang'],
            $category ? [$category->name] : null,
        ]))])

        <header class="gx-head">
            <p class="gx-eyebrow">Cẩm nang</p>
            <h1>{{ $category ? $category->name : 'Cẩm nang du lịch' }}</h1>
            <p class="gx-lead">Những điều nên biết trước và trong chuyến đi. Thông tin dễ thay đổi đều ghi nguồn chính thức và ngày cập nhật lần cuối.</p>
        </header>

        @if (count($groups) > 0)
            <nav aria-label="Danh mục cẩm nang">
                <div class="gx-chips">
                    <a class="gx-chip {{ $category ? '' : 'is-on' }}" href="{{ route('guides.index') }}" @if (!$category) aria-current="true" @endif>Tất cả</a>
                    @foreach ($groups as $g)
                        @php $on = $category && $category->slug === $g['category']->slug; @endphp
                        <a class="gx-chip {{ $on ? 'is-on' : '' }}" href="{{ route('guides.category', ['category' => $g['category']->slug]) }}" @if ($on) aria-current="true" @endif>{{ $g['category']->name }} ({{ $g['total'] }})</a>
                    @endforeach
                </div>
            </nav>
        @endif

        @if ($category)
            <div class="gx-grid">
                @foreach ($items as $card)
                    @include('clients.partials.gx.card', ['c' => $card])
                @endforeach
            </div>
            @include('clients.partials.gx.pager', ['items' => $items])
        @else
            @forelse ($groups as $g)
                <section class="gx-box" aria-labelledby="g-{{ $g['category']->slug }}">
                    <h2 id="g-{{ $g['category']->slug }}">{{ $g['category']->name }}</h2>
                    @if ($g['total'] > count($g['items']))
                        <p class="gx-more"><a href="{{ route('guides.category', ['category' => $g['category']->slug]) }}">Xem cả {{ $g['total'] }} bài</a></p>
                    @endif
                    <div class="gx-grid">
                        @foreach ($g['items'] as $card)
                            @include('clients.partials.gx.card', ['c' => $card])
                        @endforeach
                    </div>
                </section>
            @empty
                <p class="gx-empty">Chưa có bài cẩm nang nào được duyệt. Bạn có thể bắt đầu bằng <a href="{{ route('destination') }}">khám phá điểm đến</a>.</p>
            @endforelse
        @endif
    </div>
</main>

@include('clients.blocks.footer')
