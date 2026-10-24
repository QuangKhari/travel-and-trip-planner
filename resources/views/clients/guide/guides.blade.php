@php
    $metaDescription = $category
        ? 'Cẩm nang ' . mb_strtolower($category->name) . ' khi du lịch Việt Nam: bài viết có ghi nguồn và ngày cập nhật.'
        : 'Cẩm nang du lịch Việt Nam: chuẩn bị trước chuyến đi, đi lại, tiền bạc và an toàn. Mỗi bài có ghi nguồn và ngày cập nhật lần cuối.';
@endphp
@include('clients.blocks.header')
{{-- /cam-nang và /cam-nang/{danh-muc} — biến: $groups, $category, $items. Kiểu GoVietnam: nhóm theo giai đoạn của người đi, thẻ 2 cột có biểu tượng. --}}
<main class="gx-page" id="main">
    <div class="gx-wrap">
        @include('clients.partials.gx.crumb', ['crumbs' => array_values(array_filter([
            ['Trang chủ', route('home')],
            $category ? ['Cẩm nang', route('guides.index')] : ['Cẩm nang'],
            $category ? [$category->name] : null,
        ]))])

        <header class="gx-head">
            <p class="gx-eyebrow">Cẩm nang</p>
            <h1>{{ $category ? $category->name : 'Cẩm nang du lịch Việt Nam' }}</h1>
            <p class="gx-lead">Mọi phần chuẩn bị cho chuyến đi gom về một chỗ: giấy tờ, thời điểm nên đi, cách di chuyển, tiền bạc và những tình huống nên biết trước. Mỗi bài đều ghi rõ nguồn và ngày cập nhật.</p>
        </header>

        @if ($category)
            <p class="hb-back"><a href="{{ route('guides.index') }}">← Tất cả cẩm nang</a></p>
            <div class="hb-grid">
                @foreach ($items as $card)
                    @include('clients.partials.gx.guide-card', ['c' => $card])
                @endforeach
            </div>
            @include('clients.partials.gx.pager', ['items' => $items])
        @else
            @forelse ($groups as $g)
                <section class="hb-group" aria-labelledby="g-{{ $g['category']->slug }}">
                    <p class="hb-eyebrow">Cẩm nang</p>
                    <h2 id="g-{{ $g['category']->slug }}">{{ $g['category']->name }}</h2>
                    <div class="hb-grid">
                        @foreach ($g['items'] as $card)
                            @include('clients.partials.gx.guide-card', ['c' => $card])
                        @endforeach
                    </div>
                    @if ($g['total'] > count($g['items']))
                        <p class="hb-more"><a href="{{ route('guides.category', ['category' => $g['category']->slug]) }}">Xem cả {{ $g['total'] }} bài →</a></p>
                    @endif
                </section>
            @empty
                <p class="gx-empty">Chưa có bài cẩm nang nào được duyệt. Bạn có thể bắt đầu bằng <a href="{{ route('destination') }}">khám phá điểm đến</a>.</p>
            @endforelse
        @endif
    </div>
</main>

@include('clients.blocks.footer')
