@php
    $metaDescription = 'Điểm đến đẹp nhất và lễ hội diễn ra trong tháng ' . $month . ' ở Việt Nam, kèm gợi ý nên đi đâu.';
@endphp
@include('clients.blocks.header')
{{-- /dang-vao-mua — biến: $month, $isCurrent, $destinations (phân trang), $events (phân trang) --}}
<main class="gx-page" id="main">
    <div class="gx-wrap">
        @include('clients.partials.gx.crumb', ['crumbs' => [['Trang chủ', route('home')], ['Đang vào mùa', null]]])
        <header class="gx-head">
            <p class="gx-eyebrow">{{ $isCurrent ? 'Tháng này' : 'Đang vào mùa' }}</p>
            <h1>Tháng {{ $month }}: đi đâu đẹp nhất?</h1>
            <p class="gx-lead">Những điểm đến đang ở mùa đẹp và các lễ hội diễn ra trong tháng {{ $month }}.</p>
        </header>

        <nav aria-label="Chọn tháng">
            <div class="gx-chips">
                @foreach (range(1, 12) as $m)
                    <a class="gx-chip {{ $m === $month ? 'is-on' : '' }}" href="{{ route('seasons.now', ['thang' => $m]) }}" @if ($m === $month) aria-current="true" @endif>Tháng {{ $m }}</a>
                @endforeach
            </div>
        </nav>

        <section class="gx-box" id="diem-den" aria-labelledby="h-dd">
            <h2 id="h-dd">Điểm đến đẹp nhất tháng {{ $month }}</h2>
            @if ($destinations->isEmpty())
                <p class="gx-empty">Chưa có điểm đến nào được đánh dấu là đẹp nhất trong tháng này. Xem <a href="{{ route('seasons.matrix', ['thang' => $month]) }}">bảng mùa đẹp cả năm</a>.</p>
            @else
                <div class="gx-grid">
                    @foreach ($destinations as $c)
                        @include('clients.partials.gx.card', ['c' => $c, 'loading' => $loop->index < 3 ? 'eager' : 'lazy'])
                    @endforeach
                </div>
            @endif
        </section>

        <section class="gx-box" id="le-hoi" aria-labelledby="h-lh">
            <h2 id="h-lh">Lễ hội trong tháng {{ $month }}</h2>
            @if ($events->isEmpty())
                <p class="gx-empty">Chưa có lễ hội nào được duyệt cho tháng này. Xem <a href="{{ route('events.index') }}">tất cả lễ hội</a>.</p>
            @else
                <div class="gx-grid">
                    @foreach ($events as $c)
                        @include('clients.partials.gx.card', ['c' => $c])
                    @endforeach
                </div>
            @endif
        </section>

        <p class="gx-more"><a href="{{ route('seasons.matrix', ['thang' => $month]) }}">Xem bảng "Nên đi tháng nào" cho cả năm</a></p>
    </div>
</main>
@include('clients.blocks.footer')
