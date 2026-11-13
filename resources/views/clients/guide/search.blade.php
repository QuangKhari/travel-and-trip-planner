@php
    $metaDescription = 'Tìm điểm đến, món ăn, lễ hội, lịch trình và bài cẩm nang trên Travela.';
    $more = [
        'destination' => fn() => route('destinations.list', ['q' => $q]),
        'food' => fn() => route('foods.index', ['q' => $q]),
        'event' => fn() => route('events.index', ['q' => $q]),
    ];
@endphp
@include('clients.blocks.header')
{{-- /tim-kiem?q= — biến: $q, $groups (theo loại), $total. Trang này noindex. --}}
<main class="gx-page" id="main">
    <div class="gx-wrap">
        @include('clients.partials.gx.crumb', ['crumbs' => [['Trang chủ', route('home')], ['Tìm kiếm', null]]])
        <header class="gx-head">
            <p class="gx-eyebrow">Tìm kiếm</p>
            <h1>{{ $q === '' ? 'Bạn muốn tìm gì?' : 'Kết quả cho “' . $q . '”' }}</h1>
            <p class="gx-lead">Tìm trong điểm đến, ẩm thực, lễ hội, lịch trình và cẩm nang. Gõ không dấu cũng được.</p>
        </header>

        <form class="gx-search" role="search" method="get" action="{{ route('site.search') }}">
            <label class="gx-sr" for="gx-q">Từ khóa</label>
            <input id="gx-q" type="search" name="q" value="{{ $q }}" maxlength="60" autocomplete="off" placeholder="Ví dụ: bun cha, hoi an, trung thu" data-gx-suggest>
            <button class="gx-btn" type="submit">Tìm</button>
        </form>

        @if ($q === '')
            <section class="gx-box">
                <h2>Gợi ý để bắt đầu</h2>
                <ul class="gx-links">
                    <li><a href="{{ route('seasons.now') }}">Tháng này đi đâu đẹp nhất</a></li>
                    <li><a href="{{ route('foods.index') }}">Ẩm thực theo vùng</a></li>
                    <li><a href="{{ route('events.index') }}">Lễ hội sắp diễn ra</a></li>
                    <li><a href="{{ route('guides.index') }}">Cẩm nang du lịch</a></li>
                </ul>
            </section>
        @elseif ($total === 0)
            @include('clients.partials.gx.search_intent', ['intent' => $intent, 'quick' => $quick, 'plannerUrl' => $plannerUrl])
            <p class="gx-empty" role="status">Không có kết quả cho “{{ $q }}”. Thử từ ngắn hơn hoặc xem <a href="{{ route('destination') }}">tất cả điểm đến</a>.</p>
        @else
            @include('clients.partials.gx.search_intent', ['intent' => $intent, 'quick' => $quick, 'plannerUrl' => $plannerUrl])
            <p class="gx-count" role="status">{{ $total }} kết quả cho “{{ $q }}”</p>
            @foreach ($groups as $type => $g)
                <section class="gx-box" id="kq-{{ $type }}" aria-labelledby="h-{{ $type }}">
                    <h2 id="h-{{ $type }}">{{ $g['label'] }} ({{ $g['total'] }})</h2>
                    <div class="gx-grid">
                        @foreach ($g['items'] as $c)
                            @include('clients.partials.gx.card', ['c' => $c])
                        @endforeach
                    </div>
                    @if ($g['total'] > count($g['items']))
                        <p class="gx-more">
                            @if (isset($more[$type]))
                                <a href="{{ $more[$type]() }}">Xem cả {{ $g['total'] }} kết quả {{ mb_strtolower($g['label']) }}</a>
                            @else
                                Còn {{ $g['total'] - count($g['items']) }} kết quả nữa, hãy gõ cụ thể hơn.
                            @endif
                        </p>
                    @endif
                </section>
            @endforeach
        @endif

        @if (!empty($semantic))
            {{-- C-13: tìm theo ý nghĩa (embedding) khi tìm theo chữ ra ít kết quả --}}
            <section class="gx-box gx-semantic" aria-labelledby="h-semantic">
                <h2 id="h-semantic">Có thể bạn đang tìm <small>· gần nghĩa với “{{ $q }}”</small></h2>
                <ul class="gx-semantic__list">
                    @foreach ($semantic as $sm)
                        <li><a href="{{ $sm['url'] }}"><b>{{ $sm['title'] }}</b></a> <span class="gx-semantic__type">{{ $sm['type'] }}</span>
                            <p>{{ $sm['text'] }}</p></li>
                    @endforeach
                </ul>
            </section>
            <style>.gx-semantic__list{list-style:none;margin:0;padding:0;display:grid;gap:12px}.gx-semantic__list p{margin:2px 0 0;color:#5b6b66;font-size:14px}
                .gx-semantic__type{font-size:12px;color:#0f5f57;background:#e6f4f1;border-radius:999px;padding:1px 8px;margin-left:4px}.gx-semantic h2 small{font-weight:400;color:#6b7280;font-size:14px}</style>
        @endif

        @if ($q !== '')
            <p class="gx-more">Đang tìm tour? <a href="{{ route('tours', ['keyword' => $q]) }}">Tìm tour với từ khóa “{{ $q }}”</a>.</p>
        @endif
    </div>
</main>
@include('clients.blocks.footer')
