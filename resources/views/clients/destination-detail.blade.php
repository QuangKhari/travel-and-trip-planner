@include('clients.blocks.header')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="{{ asset('clients/assets/css/destination-detail.css') }}?v=1">
<link rel="stylesheet" href="{{ asset('clients/assets/css/tv-weather.css') }}?v=1">

{{-- /destination/{slug} — biến: $destination, $data (DestinationService::payload), $tours --}}
@php
    $w = $data['weather'];
    $cats = ['see' => 'Tham quan', 'culture' => 'Văn hoá', 'eat' => 'Ăn uống'];
    $intro = $destination->wikiExtract ?: $destination->description;
@endphp

<section class="dd-hero">
    @if ($destination->image)
        <img class="dd-hero__img" src="{{ $destination->image }}" alt="{{ $destination->name }}" fetchpriority="high">
    @endif
    <span class="dd-hero__shade" aria-hidden="true"></span>
    <div class="container">
        <nav class="dd-crumb" aria-label="breadcrumb">
            <a href="{{ route('home') }}">Trang chủ</a><i class="fas fa-chevron-right"></i>
            <a href="{{ route('home') }}#kham-pha">Khám phá theo cảm hứng</a><i class="fas fa-chevron-right"></i>
            <span>{{ $destination->name }}</span>
        </nav>
        <p class="dd-hero__eyebrow"><i class="fas fa-map-marker-alt"></i> {{ $destination->province }} ·
            {{ $destination->domainName }}</p>
        <h1 class="dd-hero__title">{{ $destination->name }}</h1>
        @if ($destination->imageCredit)
            <p class="dd-hero__credit">Ảnh:
                @if ($destination->imageSourceUrl)
                    <a href="{{ $destination->imageSourceUrl }}" target="_blank"
                        rel="noopener noreferrer">{{ $destination->imageCredit }}</a>
                @else
                    {{ $destination->imageCredit }}
                @endif
            </p>
        @endif
    </div>
</section>

<section class="dd-main">
    <div class="container">
        <div class="dd-grid">
            <div class="dd-col">
                <article class="dd-card">
                    <h2>Giới thiệu</h2>
                    <p class="dd-intro">{{ $intro }}</p>
                    @if ($destination->wikiExtract && $destination->wikiUrl)
                        <p class="dd-attr">Nguồn: <a href="{{ $destination->wikiUrl }}" target="_blank"
                                rel="noopener noreferrer">Wikipedia</a>
                            (<a href="https://creativecommons.org/licenses/by-sa/4.0/deed.vi" target="_blank"
                                rel="noopener noreferrer">CC BY-SA</a>)</p>
                    @endif
                    @if (count($data['inspirations']))
                        <div class="dd-ins">
                            <span>Hợp với</span>
                            @foreach ($data['inspirations'] as $i)
                                <a href="{{ route('explore', ['inspiration' => $i['slug']]) }}" class="dd-chip">
                                    <i class="fas {{ $i['icon'] }}"></i> {{ $i['name'] }}
                                    <b>{{ $i['score'] }}</b>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </article>

                <article class="dd-card" id="ddPlacesCard">
                    <div class="dd-card__head">
                        <h2>Nên đi đâu, ăn gì</h2>
                        <div class="dd-tabs" id="ddTabs" role="tablist">
                            <button type="button" class="is-on" data-cat="all">Tất cả</button>
                            @foreach ($cats as $k => $label)
                                @if (!empty($data['places'][$k]))
                                    <button type="button" data-cat="{{ $k }}">{{ $label }}</button>
                                @endif
                            @endforeach
                        </div>
                    </div>
                    @if (empty($data['places']))
                        <p class="dd-empty">Chưa có dữ liệu địa điểm. Quản trị viên chạy <code>php artisan
                                discover:sync-places --slug={{ $destination->slug }}</code> để nạp.</p>
                    @else
                        <ul class="dd-places" id="ddPlaces">
                            @foreach ($cats as $k => $label)
                                @foreach ($data['places'][$k] ?? [] as $p)
                                    <li data-cat="{{ $k }}" data-id="{{ $p['id'] }}">
                                        <button type="button" class="dd-place">
                                            <span class="dd-place__dot dd-place__dot--{{ $k }}"></span>
                                            <span class="dd-place__tx"><strong>{{ $p['name'] }}</strong>
                                                <small>{{ $label }}@if ($p['address'])
                                                        · {{ $p['address'] }}
                                                    @endif
                                                </small></span>
                                        </button>
                                    </li>
                                @endforeach
                            @endforeach
                        </ul>
                    @endif
                </article>
            </div>

            <aside class="dd-col">
                <article class="dd-card dd-weather">
                    <h2>Thời tiết</h2>
                    @if ($w)
                        @php
                            $themes = [
                                'fa-sun' => 'clear',
                                'fa-cloud-sun' => 'partly',
                                'fa-cloud' => 'cloud',
                                'fa-cloud-rain' => 'rain',
                                'fa-cloud-sun-rain' => 'rain',
                                'fa-cloud-showers-heavy' => 'rain',
                                'fa-bolt' => 'storm',
                                'fa-smog' => 'fog',
                                'fa-snowflake' => 'snow',
                            ];
                            $theme = $themes[$w['current']['icon']] ?? 'cloud';
                            $d0 = $w['daily'][0] ?? null;
                            $tip = null;
                            if ($d0) {
                                if (($d0['rain'] ?? 0) >= 60) {
                                    $tip = ['fa-umbrella', 'Khả năng mưa cao hôm nay — nên mang áo mưa hoặc ô.'];
                                } elseif (($d0['tMax'] ?? 0) >= 34) {
                                    $tip = ['fa-sun', 'Trời nóng — nhớ mang mũ, kem chống nắng và nước uống.'];
                                } elseif (isset($d0['tMax']) && $d0['tMax'] <= 17) {
                                    $tip = ['fa-snowflake', 'Trời lạnh — nên mang theo áo ấm.'];
                                }
                            }
                        @endphp
                        <div class="tvw" data-wx="{{ $theme }}">
                            <div class="tvw__now">
                                <span class="tvw__ico"><i class="fas {{ $w['current']['icon'] }}"></i></span>
                                <div>
                                    <span class="tvw__temp">{{ $w['current']['temp'] }}<sup>°C</sup></span>
                                    <span class="tvw__lbl">{{ $w['current']['label'] }}</span>
                                </div>
                                <div class="tvw__chips">
                                    <span class="tvw__chip"><i class="fas fa-thermometer-half"></i>Cảm giác
                                        {{ $w['current']['feels'] }}°</span>
                                    <span class="tvw__chip"><i
                                            class="fas fa-tint"></i>{{ $w['current']['humidity'] }}%</span>
                                    <span class="tvw__chip"><i class="fas fa-wind"></i>{{ $w['current']['wind'] }}
                                        km/h</span>
                                </div>
                            </div>
                            @if ($tip)
                                <p class="tvw__tip"><i
                                        class="fas {{ $tip[0] }}"></i><span>{{ $tip[1] }}</span></p>
                            @endif
                            <ol class="tvw__days">
                                @foreach ($w['daily'] as $i => $day)
                                    <li class="tvw__day {{ $i === 0 ? 'is-today' : '' }}">
                                        <span
                                            class="tvw__dn">{{ $i === 0 ? 'Hôm nay' : \Carbon\Carbon::parse($day['date'])->locale('vi')->isoFormat('dd') }}</span>
                                        <i class="fas {{ $day['icon'] }}" title="{{ $day['label'] }}"></i>
                                        <b>{{ $day['tMax'] }}°</b><small>{{ $day['tMin'] }}°</small>
                                        @if ($day['rain'] !== null)
                                            <span class="tvw__rain"><i
                                                    style="--r: {{ (int) $day['rain'] }}%"></i></span>
                                            <span class="tvw__rn">{{ $day['rain'] }}%</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ol>
                            <p class="tvw__src">Cập nhật
                                {{ \Carbon\Carbon::parse($w['fetchedAt'])->locale('vi')->diffForHumans() }} ·
                                <a href="https://open-meteo.com/" target="_blank"
                                    rel="noopener noreferrer">Open-Meteo.com</a>
                            </p>
                        </div>
                    @else
                        <p class="dd-empty">Chưa có dữ liệu thời tiết.</p>
                    @endif
                </article>

                <article class="dd-card dd-mapcard">
                    <h2>Bản đồ</h2>
                    <div id="ddMap" class="dd-map" role="application" aria-label="Bản đồ {{ $destination->name }}">
                    </div>
                    <p class="dd-attr">Bản đồ © <a href="https://www.openstreetmap.org/copyright" target="_blank"
                            rel="noopener noreferrer">OpenStreetMap</a> contributors</p>
                </article>
            </aside>
        </div>

        <section class="dd-tours">
            <h2>Tour tại {{ $destination->name }}</h2>
            @if ($tours->isEmpty())
                <p class="dd-empty">Chưa có tour đang mở bán tại đây.
                    <a href="{{ route('explore', ['inspiration' => $data['inspirations'][0]['slug'] ?? 'bien']) }}">Xem
                        điểm đến tương tự</a>
                </p>
            @else
                <div class="dd-tourgrid">
                    @foreach ($tours as $t)
                        <a class="dd-tour" href="{{ route('tour-detail', ['id' => $t->tourId]) }}">
                            <strong>{{ $t->title }}</strong>
                            <span><i class="fal fa-calendar-alt"></i>
                                {{ \Carbon\Carbon::parse($t->startDate)->format('d/m/Y') }}
                                · <i class="fal fa-clock"></i> {{ $t->time }} · còn {{ (int) $t->quantity }}
                                chỗ</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</section>

@php
    $ddJson = [
        'lat' => $data['latitude'],
        'lng' => $data['longitude'],
        'name' => $data['name'],
        'places' => $data['places'],
    ];
@endphp
<script type="application/json" id="ddData">@json($ddJson)</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="{{ asset('clients/assets/js/destination-detail.js') }}?v=1" defer></script>

@include('clients.blocks.new_letter')
@include('clients.blocks.footer')
