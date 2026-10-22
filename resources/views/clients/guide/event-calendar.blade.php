@include('clients.blocks.header')
{{-- /le-hoi/lich — biến: $days, $year, $month, $daysInMonth, $offset, $prevUrl, $nextUrl --}}
<main class="gx-page" id="main">
    <div class="gx-wrap">
        @include('clients.partials.gx.crumb', ['crumbs' => [['Trang chủ', route('home')], ['Lễ hội', route('events.index')], ['Lịch tháng', null]]])
        <header class="gx-head">
            <h1>Lịch lễ hội tháng {{ $month }}/{{ $year }}</h1>
        </header>
        <div class="gx-chips" role="group" aria-label="Chế độ xem">
            <a class="gx-chip" href="{{ route('events.index') }}">Thẻ</a>
            <a class="gx-chip is-on" href="{{ route('events.calendar') }}" aria-current="true">Lịch tháng</a>
            <a class="gx-chip" href="{{ $prevUrl }}" rel="prev">‹ Tháng trước</a>
            <a class="gx-chip" href="{{ $nextUrl }}" rel="next">Tháng sau ›</a>
        </div>
        <div class="gx-cal">
            @foreach (['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'] as $h)
                <div class="gx-cal__h">{{ $h }}</div>
            @endforeach
            @for ($i = 0; $i < $offset; $i++)
                <div class="gx-cal__blank" aria-hidden="true"></div>
            @endfor
            @for ($d = 1; $d <= $daysInMonth; $d++)
                <div>
                    <b>{{ $d }}</b>
                    @foreach ($days[$d] ?? [] as $e)
                        <span class="gx-cal__e {{ $e['approx'] ? 'gx-cal__e--approx' : '' }}"><a href="{{ $e['url'] }}">{{ $e['name'] }}</a></span>
                    @endforeach
                </div>
            @endfor
        </div>
        <p class="gx-credit">Ô màu vàng: lễ hội chỉ biết tháng, chưa rõ ngày, hiển thị ở ngày 1.</p>
    </div>
</main>
@include('clients.blocks.footer')
