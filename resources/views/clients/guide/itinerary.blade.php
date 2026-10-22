@include('clients.blocks.header')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
{{-- /lich-trinh/{slug} — biến: $it, $days, $points, $cover, $plannerUrl --}}
<main class="gx-page" id="main">
    <div class="gx-wrap">
        @include('clients.partials.gx.crumb', ['crumbs' => [['Trang chủ', route('home')], ['Lịch trình', route('itineraries.index')], [$it->title, null]]])
        <header class="gx-head">
            <p class="gx-eyebrow">{{ (int) $it->days }} ngày{{ $it->region ? ' · ' . (\App\Services\Content\ContentCatalog::REGIONS[$it->region] ?? '') : '' }}</p>
            <h1>{{ $it->title }}</h1>
            @if ($it->summary)<p class="gx-lead">{{ $it->summary }}</p>@endif
        </header>
        @if ($cover)
            <figure class="gx-cover"><img src="{{ $cover }}" alt="{{ $it->title }}" fetchpriority="high"></figure>
            @include('clients.partials.gx.attribution', ['row' => $it])
        @endif
        <div class="gx-actions">
            @if ($plannerUrl)
                <a class="gx-btn" href="{{ $plannerUrl }}?lich-trinh={{ $it->slug }}">Dùng làm điểm xuất phát</a>
            @else
                <span class="gx-note">“Dùng làm điểm xuất phát” sẽ có khi trình lên kế hoạch ra mắt.</span>
            @endif
        </div>
        @if ($points)
            <div id="gxMap" class="gx-map" role="application" aria-label="Bản đồ tuyến lịch trình"></div>
            <p class="gx-credit">Bản đồ © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> contributors</p>
        @endif
        <section class="gx-box">
            @forelse ($days as $no => $stops)
                <div class="gx-day" id="ngay-{{ $no }}">
                    <h3>Ngày {{ $no }}</h3>
                    <ul class="gx-stops">
                        @foreach ($stops as $s)
                            <li>
                                @if ($s['url'])<a href="{{ $s['url'] }}">{{ $s['label'] }}</a>@else<strong>{{ $s['label'] }}</strong>@endif
                                @if ($s['note'])<small>{{ $s['note'] }}</small>@endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @empty
                <p>Lịch trình này chưa có điểm dừng.</p>
            @endforelse
        </section>
    </div>
</main>
@if ($points)
    <script type="application/json" id="gxPoints">@json($points)</script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="{{ asset('clients/assets/js/guide-route-map.js') }}?v=1" defer></script>
@endif
@include('clients.blocks.footer')
