@include('clients.blocks.header')
<link rel="stylesheet" href="{{ asset('clients/assets/css/month-brief.css') }}?v=1">
{{-- /diem-den/{slug}/thang-{n}. Biến: $b (DestinationMonthBrief::for), $plannerUrl. Không có logic mùa/lễ hội ở đây: chỉ hiển thị. --}}
@php
    $d = $b['destination'];
    $m = $b['month'];
    $r = $b['rating'];
    $money = fn($v) => number_format((float) $v, 0, ',', '.') . 'đ';
    $tone = ['best' => 'best', 'ok' => 'ok', 'avoid' => 'avoid'][$r['level'] ?? ''] ?? 'unknown';
@endphp
<main class="gx-page" id="main">
    <div class="gx-wrap">
        @include('clients.partials.gx.crumb', ['crumbs' => [['Trang chủ', route('home')], ['Điểm đến', route('destinations.list')], [$d['name'], $d['url']], ['Tháng ' . $m, null]]])
        <header class="gx-head">
            <p class="gx-eyebrow">{{ $d['province'] }} · tháng {{ $m }}</p>
            <h1>{{ $d['name'] }} vào tháng {{ $m }}</h1>
            <p class="gx-lead">{{ \App\Services\Content\DestinationMonthBrief::summaryText($b) }}</p>
        </header>

        <nav aria-label="Chọn tháng">
            <div class="gx-chips">
                @foreach (range(1, 12) as $i)
                    <a class="gx-chip {{ $i === $m ? 'is-on' : '' }}" href="{{ route('destination.month', ['slug' => $d['slug'], 'n' => $i]) }}" @if ($i === $m) aria-current="true" @endif>Tháng {{ $i }}</a>
                @endforeach
            </div>
        </nav>

        @if ($b['warnings'])
            <div class="gx-note mb-warn" role="note">
                <ul>@foreach ($b['warnings'] as $w)<li>{{ $w }}</li>@endforeach</ul>
            </div>
        @endif

        <section class="gx-box" id="thoi-diem" aria-labelledby="h-td">
            <h2 id="h-td">Thời điểm đi</h2>
            <p><span class="gx-m gx-m--{{ $tone }}">{{ $r['label'] }}</span>
                @if ($r['note'])<span class="mb-note">{{ $r['note'] }}</span>@endif</p>
            @if ($b['climate'])
                @php $c = $b['climate']; @endphp
                <dl class="ex-facts">
                    <div><dt>Nhiệt độ</dt><dd>{{ round($c['tMin']) }}–{{ round($c['tMax']) }}°C</dd></div>
                    <div><dt>Ngày mưa</dt><dd>{{ round($c['rainyDays']) }} ngày</dd></div>
                    @if ($c['rainMm'] !== null)<div><dt>Lượng mưa</dt><dd>{{ round($c['rainMm']) }} mm</dd></div>@endif
                </dl>
                <p class="gx-credit">{{ $b['climateNote'] }} Trung bình {{ $c['periodFrom'] }}–{{ $c['periodTo'] }}.</p>
            @endif
            @if ($r['suitable'])<h3>Hợp với</h3><ul class="mb-list">@foreach ($r['suitable'] as $s)<li>{{ $s }}</li>@endforeach</ul>@endif
            @if ($r['caution'])<h3>Cần lưu ý</h3><ul class="mb-list">@foreach ($r['caution'] as $s)<li>{{ $s }}</li>@endforeach</ul>@endif
            @if ($r['packing'])<h3>Nên mang</h3><ul class="mb-list">@foreach ($r['packing'] as $s)<li>{{ $s }}</li>@endforeach</ul>@endif
        </section>

        @if ($b['events'])
            <section class="gx-box" id="le-hoi" aria-labelledby="h-lh">
                <h2 id="h-lh">Lễ hội trong tháng {{ $m }}</h2>
                <ul class="mb-list">
                    @foreach ($b['events'] as $e)
                        <li><a href="{{ $e['url'] }}">{{ $e['name'] }}</a> <span class="mb-note">{{ $e['when'] }}@if ($e['approx']) (chưa rõ ngày cụ thể)@endif</span></li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($b['foods'])
            <section class="gx-box" id="mon-an" aria-labelledby="h-ma">
                <h2 id="h-ma">Món vào mùa</h2>
                <ul class="mb-list">
                    @foreach ($b['foods'] as $f)<li><a href="{{ $f['url'] }}">{{ $f['name'] }}</a> <span class="mb-note">{{ $f['season'] }}</span></li>@endforeach
                </ul>
            </section>
        @endif

        @if ($b['experiences'])
            <section class="gx-box" id="trai-nghiem" aria-labelledby="h-tn">
                <h2 id="h-tn">Trải nghiệm phù hợp</h2>
                <ul class="mb-list">
                    @foreach ($b['experiences'] as $x)
                        <li><a href="{{ $x['url'] }}">{{ $x['name'] }}</a>
                            <span class="mb-note">{{ $x['seasonal'] ? 'Hợp mùa: ' . $x['season'] : 'Quanh năm' }}@if ($x['duration']) · {{ $x['duration'] }}@endif</span></li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($b['itineraries'])
            <section class="gx-box" id="lich-trinh" aria-labelledby="h-lt">
                <h2 id="h-lt">Lịch trình gợi ý</h2>
                <ul class="mb-list">
                    @foreach ($b['itineraries'] as $i)<li><a href="{{ $i['url'] }}">{{ $i['title'] }}</a> <span class="mb-note">{{ $i['days'] }} ngày</span></li>@endforeach
                </ul>
            </section>
        @endif

        @if ($b['tours'])
            <section class="gx-box" id="tour" aria-labelledby="h-tour">
                <h2 id="h-tour">Tour khởi hành trong tháng {{ $m }}</h2>
                <ul class="mb-list">
                    @foreach ($b['tours'] as $t)<li><a href="{{ $t['url'] }}">{{ $t['title'] }}</a> <span class="mb-note">Khởi hành {{ $t['start'] }} · từ {{ $money($t['price']) }}</span></li>@endforeach
                </ul>
            </section>
        @endif

        <div class="gx-actions">
            <a class="gx-btn" href="{{ $d['url'] }}">Về trang {{ $d['name'] }}</a>
            @if ($plannerUrl)<a class="gx-btn2" href="{{ $plannerUrl }}?thang={{ $m }}">Lên kế hoạch cho tháng {{ $m }}</a>@endif
        </div>
        <p class="gx-credit">{{ $b['disclaimer'] }}</p>
    </div>
</main>
@include('clients.blocks.footer')
