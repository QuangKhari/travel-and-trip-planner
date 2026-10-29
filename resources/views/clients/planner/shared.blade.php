@include('clients.blocks.header')
<link rel="stylesheet" href="{{ asset('clients/assets/css/planner.css') }}?v=7">

{{-- /chuyen-di/{token}: bản chỉ đọc của một chuyến đi được chia sẻ. Không có thông tin chủ chuyến. --}}
@php
    $t = $payload['trip'];
    $money = fn($n) => number_format((float) $n, 0, ',', '.') . 'đ';
    $start = $t['startDate'] ? \Carbon\Carbon::parse($t['startDate']) : null;
    $typeLabel = ['destination' => 'Điểm đến', 'place' => 'Địa điểm', 'food' => 'Món ăn', 'event' => 'Lễ hội', 'experience' => 'Trải nghiệm', 'tour' => 'Tour', 'note' => 'Ghi chú', 'transport' => 'Di chuyển'];
@endphp

<main class="tp tp--shared">
    <div class="container">
        <header class="tp-head">
            <h1>{{ $t['title'] }}</h1>
            <p>{{ $t['days'] }} ngày · {{ $t['groupSize'] }} người
                @if ($start) · từ {{ $start->format('d/m/Y') }} @endif
            </p>
        </header>

        @foreach ($payload['days'] as $d)
            @if ($d['items'])
                <section class="tp-card tp-day">
                    <h2>Ngày {{ $d['day'] }}@if ($start) <small>{{ $start->copy()->addDays($d['day'] - 1)->format('d/m') }}</small>@endif</h2>
                    <ol class="tp-items">
                        @foreach ($d['items'] as $it)
                            <li>
                                <span class="tp-type">{{ $typeLabel[$it['type']] ?? '' }}</span>
                                @if (!empty($it['url']))<a href="{{ $it['url'] }}">{{ $it['name'] }}</a>@else<span>{{ $it['name'] }}</span>@endif
                                @if ($it['sub'])<small>{{ $it['sub'] }}</small>@endif
                                @if ($it['when'])<small>Diễn ra {{ $it['when'] }}</small>@endif
                                @if (!empty($it['slot']))<small>{{ ['morning' => 'Buổi sáng', 'noon' => 'Buổi trưa', 'afternoon' => 'Buổi chiều', 'evening' => 'Buổi tối'][$it['slot']] ?? '' }}</small>@endif
                                @if ($it['note'])<p class="tp-note">{{ $it['note'] }}</p>@endif
                                @if (!empty($it['reasons']))<p class="tp-why">Vì sao chọn: {{ implode('; ', $it['reasons']) }}</p>@endif
                            </li>
                        @endforeach
                    </ol>
                    @if ($d['legs'])
                        <p class="tp-muted tp-small">Di chuyển khoảng {{ $d['km'] }} km (ước tính).</p>
                    @endif
                </section>
            @endif
        @endforeach

        @if (!empty($payload['rain']['plan']))
            <section class="tp-card tp-rain tp-rain--shared">
                <h2>Nếu trời mưa</h2>
                @foreach ($payload['rain']['plan'] as $dayNo => $list)
                    <h3>Ngày {{ $dayNo }}</h3>
                    <ul class="tp-rain__list">
                        @foreach ($list as $r)
                            <li class="tp-rain__item">@if (!empty($r['url']))<a href="{{ $r['url'] }}">{{ $r['name'] }}</a>@else{{ $r['name'] }}@endif @if ($r['sub'])<small class="tp-muted">· {{ $r['sub'] }}</small>@endif</li>
                        @endforeach
                    </ul>
                @endforeach
            </section>
        @endif

        @if ($payload['unassigned'])
            <section class="tp-card">
                <h2>Chưa xếp ngày</h2>
                <ul class="tp-items">@foreach ($payload['unassigned'] as $it)<li>@if (!empty($it['url']))<a href="{{ $it['url'] }}">{{ $it['name'] }}</a>@else{{ $it['name'] }}@endif</li>@endforeach</ul>
            </section>
        @endif

        @if ($payload['totals']['cost'] > 0)
            <p class="tp-total-line">Chi phí dự kiến: <b>{{ $money($payload['totals']['cost']) }}</b></p>
        @endif

        <p class="tp-muted tp-small">Chuyến đi được chia sẻ từ Travela. Thông tin lễ hội, giá và lịch tour có thể thay đổi, hãy kiểm tra lại trước khi đi.</p>
    </div>
</main>

@include('clients.blocks.footer')
