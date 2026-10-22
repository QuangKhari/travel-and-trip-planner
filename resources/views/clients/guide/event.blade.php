@include('clients.blocks.header')
{{-- /le-hoi/{slug} — biến: $event, $cover, $dates, $foods, $marks, $lunarText --}}
@php
    $ld = [
        '@context' => 'https://schema.org',
        '@type' => 'Event',
        'name' => $event->name,
        'description' => \Illuminate\Support\Str::limit(strip_tags((string) $event->description), 300),
        'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
    ];
    if (!empty($dates)) {
        $ld['startDate'] = $dates[0]['start'];
        $ld['endDate'] = $dates[0]['end'];
    }
    if ($event->destName) {
        $ld['location'] = ['@type' => 'Place', 'name' => $event->destName, 'address' => $event->destProvince];
    }
    if ($cover) {
        $ld['image'] = $cover;
    }
@endphp
<main class="gx-page" id="main">
    <div class="gx-wrap">
        @include('clients.partials.gx.crumb', ['crumbs' => array_values(array_filter([
            ['Trang chủ', route('home')], ['Lễ hội', route('events.index')],
            $event->destSlug ? [$event->destName, route('destination.show', ['slug' => $event->destSlug])] : null,
            [$event->name, null],
        ]))])
        <header class="gx-head">
            <p class="gx-eyebrow">{{ $event->destName ?: 'Lễ hội cả nước' }}</p>
            <h1>{{ $event->name }}</h1>
        </header>
        @if ($cover)
            <figure class="gx-cover"><img src="{{ $cover }}" alt="{{ $event->name }}" fetchpriority="high"></figure>
        @endif
        <div class="gx-stats">
            @if ($lunarText)<div class="gx-stat"><b>{{ $lunarText }}</b><span>Quy tắc ngày</span></div>@endif
            @if ($event->destProvince)<div class="gx-stat"><b>{{ $event->destProvince }}</b><span>Tỉnh/thành</span></div>@endif
            <div class="gx-stat"><b>{{ (int) $event->durationDays }} ngày</b><span>Thời lượng</span></div>
        </div>
        <div class="gx-actions">
            @include('clients.partials.gx.tripbtn', ['type' => 'event', 'slug' => $event->slug, 'name' => $event->name, 'url' => route('events.show', ['slug' => $event->slug])])
            @include('clients.partials.gx.marks', ['refType' => 'event', 'slug' => $event->slug, 'mark' => 'attended', 'on' => isset($marks['attended']), 'labels' => ['Tôi đã dự', '✓ Đã dự']])
            @include('clients.partials.gx.marks', ['refType' => 'event', 'slug' => $event->slug, 'mark' => 'saved', 'on' => isset($marks['saved']), 'labels' => ['Lưu', '✓ Đã lưu']])
        </div>
        <section class="gx-box" id="ngay-duong">
            <h2>Ngày diễn ra (dương lịch)</h2>
            @if ($dates)
                <table class="gx-table">
                    <thead><tr><th>Năm</th><th>Thời gian</th></tr></thead>
                    <tbody>
                        @foreach ($dates as $d)
                            <tr><td>{{ substr($d['start'], 0, 4) }}</td><td>{{ $d['label'] }}@if ($d['approx']) <small>(chưa rõ ngày)</small>@endif</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p>Chưa tính được ngày cho lễ hội này.</p>
            @endif
            <p class="gx-note">Ngày dương của lễ hội âm lịch thay đổi theo từng năm. Hãy kiểm tra lại với ban tổ chức hoặc địa phương trước khi đặt vé và chỗ ở.@if ($event->dateNote) {{ $event->dateNote }}.@endif</p>
        </section>
        <section class="gx-box gx-text" id="gioi-thieu">
            <h2>Giới thiệu</h2>
            @foreach (preg_split('/\R{2,}/', trim((string) $event->description)) as $p)
                <p>{{ $p }}</p>
            @endforeach
            @include('clients.partials.gx.attribution', ['row' => $event])
        </section>
        @if ($event->destSlug)
            <section class="gx-box" id="lien-quan">
                <h2>Đi cùng chuyến</h2>
                <p><a class="gx-btn2" href="{{ route('destination.show', ['slug' => $event->destSlug]) }}">Xem điểm đến {{ $event->destName }}</a></p>
                @if ($foods->isNotEmpty())
                    <div class="gx-grid">@foreach ($foods as $c) @include('clients.partials.gx.card', ['c' => $c]) @endforeach</div>
                @endif
            </section>
        @endif
    </div>
</main>
<script type="application/ld+json">{!! json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
@include('clients.blocks.footer')
