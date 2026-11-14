@include('clients.blocks.header')
<link rel="stylesheet" href="{{ asset('clients/assets/css/planner.css') }}?v=7">
<link rel="stylesheet" href="{{ asset('clients/assets/css/nature-luxury-planner.css') }}?v=bl20261104">
<link rel="stylesheet" href="{{ asset('clients/assets/css/travela-nature-all-pages.css') }}?v=bl20261104">
<link rel="stylesheet" href="{{ asset('clients/assets/css/planner-group.css') }}?v=1">

{{-- /chuyen-di/{token}: bản chỉ đọc của một chuyến đi được chia sẻ. Không có thông tin chủ chuyến. --}}
@php
    $t = $payload['trip'];
    $money = fn($n) => number_format((float) $n, 0, ',', '.') . 'đ';
    $start = $t['startDate'] ? \Carbon\Carbon::parse($t['startDate']) : null;
    $typeLabel = ['destination' => 'Điểm đến', 'place' => 'Địa điểm', 'food' => 'Món ăn', 'event' => 'Lễ hội', 'experience' => 'Trải nghiệm', 'tour' => 'Tour', 'note' => 'Ghi chú', 'transport' => 'Di chuyển'];
@endphp

<main class="tp tp--shared tg-scope">
    <header class="tgs-hero{{ $cover ? ' has-cover' : '' }}" @if ($cover) style="--tgs-cover: url('{{ asset($cover) }}')" @endif>
        <div class="container">
            <p class="tgs-eyebrow">Lịch trình được chia sẻ</p>
            <h1>{{ $t['title'] }}</h1>
            <ul class="tgs-chips">
                <li><i class="fas fa-calendar-alt" aria-hidden="true"></i> {{ $t['days'] }} ngày @if ($start)· {{ $start->format('d/m') }}–{{ $start->copy()->addDays($t['days'] - 1)->format('d/m/Y') }}@endif</li>
                <li><i class="fas fa-user-friends" aria-hidden="true"></i> {{ $t['groupSize'] }} người</li>
                <li><i class="fas fa-map-marker-alt" aria-hidden="true"></i> {{ array_sum(array_map(fn($d) => count($d['items']), $payload['days'])) }} điểm</li>
                @if ($show['costs'] && $payload['totals']['cost'] > 0)<li><i class="fas fa-wallet" aria-hidden="true"></i> ≈ {{ $money($payload['totals']['cost']) }}</li>@endif
            </ul>
        </div>
    </header>

    <div class="container tgs-body">
        <div class="tgs-main">
            @foreach ($payload['days'] as $d)
                @if ($d['items'])
                    <section class="tgs-day">
                        <h2><span class="tgs-day__n">{{ $d['day'] }}</span> Ngày {{ $d['day'] }}@if ($start) <small>{{ $start->copy()->addDays($d['day'] - 1)->locale('vi')->isoFormat('dddd, DD/MM') }}</small>@endif</h2>
                        <ol class="tgs-items">
                            @foreach ($d['items'] as $it)
                                <li class="tgs-it">
                                    <span class="tgs-it__time">{{ $it['startMin'] !== null ? sprintf('%02d:%02d', intdiv($it['startMin'], 60), $it['startMin'] % 60) : (['morning' => 'Sáng', 'noon' => 'Trưa', 'afternoon' => 'Chiều', 'evening' => 'Tối'][$it['slot'] ?? ''] ?? '') }}</span>
                                    <div class="tgs-it__body">
                                        <span class="tgs-it__type">{{ $it['type'] === 'note' ? ($it['lat'] !== null ? 'Tự thêm' : 'Ghi chú') : ($typeLabel[$it['type']] ?? '') }}</span>
                                        @if (!empty($it['url']))<a class="tgs-it__name" href="{{ $it['url'] }}" @if (str_starts_with($it['url'], 'http')) target="_blank" rel="noopener" @endif>{{ $it['name'] }}</a>@else<b class="tgs-it__name">{{ $it['name'] }}</b>@endif
                                        @if ($it['sub'])<small>{{ $it['sub'] }}</small>@endif
                                        @if ($it['when'])<small>Diễn ra {{ $it['when'] }}</small>@endif
                                        @if ($show['notes'] && $it['note'])<p class="tgs-it__note">{{ $it['note'] }}</p>@endif
                                        @if (!empty($it['reasons']))<p class="tgs-it__why">Vì sao chọn: {{ implode('; ', $it['reasons']) }}</p>@endif
                                        @if ($show['costs'] && !empty($it['cost']))<small class="tgs-it__cost">≈ {{ $money($it['cost']) }}</small>@endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                        @if ($d['legs'])<p class="tgs-day__foot">Di chuyển khoảng {{ $d['km'] }} km trong ngày (ước tính).</p>@endif
                    </section>
                @endif
            @endforeach

            @if ($payload['unassigned'])
                <section class="tgs-day">
                    <h2>Chưa xếp ngày</h2>
                    <ul class="tgs-plain">@foreach ($payload['unassigned'] as $it)<li>@if (!empty($it['url']))<a href="{{ $it['url'] }}">{{ $it['name'] }}</a>@else{{ $it['name'] }}@endif</li>@endforeach</ul>
                </section>
            @endif

            @if (!empty($payload['rain']['plan']))
                <section class="tgs-day">
                    <h2>Nếu trời mưa</h2>
                    @foreach ($payload['rain']['plan'] as $dayNo => $list)
                        <h3>Ngày {{ $dayNo }}</h3>
                        <ul class="tgs-plain">
                            @foreach ($list as $r)
                                <li>@if (!empty($r['url']))<a href="{{ $r['url'] }}">{{ $r['name'] }}</a>@else{{ $r['name'] }}@endif @if ($r['sub'])<small>· {{ $r['sub'] }}</small>@endif</li>
                            @endforeach
                        </ul>
                    @endforeach
                </section>
            @endif
        </div>

        <aside class="tgs-side">
            <section class="tgs-card tgs-card--cta">
                <h2>Thích lịch trình này?</h2>
                <p>Lưu một bản sao về tài khoản để đổi ngày, số người và sửa theo ý bạn. Chuyến gốc của người chia sẻ không bị ảnh hưởng.</p>
                <form method="POST" action="{{ route('planner.shared.copy', ['token' => $token]) }}">@csrf
                    <button type="submit" class="tv-btn-solid">Lưu bản sao của tôi</button>
                </form>
            </section>
            @if ($bookings)
                <section class="tgs-card">
                    <h2>Đã đặt chỗ</h2>
                    <ul class="tgs-book">
                        @foreach ($bookings as $b)
                            <li><i class="fas {{ ['hotel' => 'fa-hotel', 'flight' => 'fa-plane', 'train' => 'fa-train', 'bus' => 'fa-bus', 'ticket' => 'fa-ticket-alt'][$b->kind] ?? 'fa-ticket-alt' }}" aria-hidden="true"></i>
                                <span><b>{{ $b->title }}</b><small>{{ \Carbon\Carbon::parse($b->startAt)->format('H:i d/m') }}</small></span></li>
                        @endforeach
                    </ul>
                </section>
            @endif
            <p class="tgs-note">Thông tin lễ hội, giá và lịch tour có thể thay đổi, hãy kiểm tra lại trước khi đi.</p>
        </aside>
    </div>
</main>

@include('clients.blocks.footer')
