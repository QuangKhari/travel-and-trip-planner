@include('clients.blocks.header')

{{-- E1/E2 — /so-tay-du-hanh. Biến từ JournalController: $metrics, $badges, $earned, $provinces, $upcoming, $done --}}
@php
    $counters = [
        ['n' => $metrics['visited'], 'label' => 'Nơi đã đến', 'icon' => '📍'],
        ['n' => $metrics['tried'], 'label' => 'Món đã thử', 'icon' => '🍜'],
        ['n' => $metrics['attended'], 'label' => 'Lễ hội đã dự', 'icon' => '🏮'],
        ['n' => $metrics['saved'], 'label' => 'Mục đã lưu', 'icon' => '🔖'],
    ];
    $stateText = ['done' => 'Đã đến', 'planned' => 'Sắp đi'];
    $fmt = fn($d) => $d ? \Carbon\Carbon::parse($d)->format('d/m/Y') : '';
@endphp

<main class="jn" id="jnPage">
    <div class="container">

        <header class="jn-head">
            <h1>Sổ tay du hành</h1>
            <p>Những nơi bạn đã đặt chân, món đã nếm, lễ hội đã dự và các chuyến tour của bạn.</p>
        </header>

        {{-- ===== Bộ đếm ===== --}}
        <ul class="jn-counters" aria-label="Thống kê của bạn">
            @foreach ($counters as $c)
                <li>
                    <span class="jn-counters__ico" aria-hidden="true">{{ $c['icon'] }}</span>
                    <b>{{ $c['n'] }}</b>
                    <span>{{ $c['label'] }}</span>
                </li>
            @endforeach
        </ul>

        <div class="jn-layout">

            {{-- ===== Bản đồ cá nhân ===== --}}
            <section class="jn-card" aria-labelledby="jnMapH">
                <h2 id="jnMapH">Bản đồ của bạn</h2>

                <div class="jn-map" role="img"
                    aria-label="Bản đồ Việt Nam, {{ count($provinces) }} tỉnh đã đánh dấu">
                    @include('clients.partials.vn-map-svg')
                    <div class="jn-map__pins" aria-hidden="true">
                        @foreach ($provinces as $p)
                            <span class="jn-pin jn-pin--{{ $p['state'] }}"
                                style="left: {{ $p['x'] }}%; top: {{ $p['y'] }}%"></span>
                        @endforeach
                    </div>
                </div>

                <p class="jn-legend" aria-hidden="true">
                    <span><i class="jn-dot jn-dot--done"></i> Đã đến</span>
                    <span><i class="jn-dot jn-dot--planned"></i> Sắp đi (đã đặt tour)</span>
                </p>

                @if (count($provinces))
                    {{-- Danh sách chữ: người dùng đọc màn hình và mobile không phải đoán vệt màu là tỉnh nào --}}
                    <ul class="jn-prov">
                        @foreach ($provinces as $p)
                            <li class="jn-prov--{{ $p['state'] }}">
                                <b>{{ $p['province'] }}</b>
                                <small>{{ $stateText[$p['state']] }} · {{ implode(', ', $p['places']) }}</small>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="jn-empty">Bản đồ còn trống. Mở một điểm đến và bấm “Đã đến”, hoặc đặt một tour, tỉnh đó sẽ
                        sáng lên ở đây.</p>
                    <a class="jn-btn" href="{{ route('destination') }}">Xem điểm đến</a>
                @endif
            </section>

            <div class="jn-col">

                {{-- ===== Huy hiệu ===== --}}
                <section class="jn-card" aria-labelledby="jnBadgeH">
                    <h2 id="jnBadgeH">Huy hiệu <span class="jn-count">{{ $earned }}/{{ count($badges) }}</span></h2>
                    <ul class="jn-badges">
                        @foreach ($badges as $b)
                            <li class="jn-badge {{ $b['earned'] ? 'is-earned' : '' }}">
                                <span class="jn-badge__ico" aria-hidden="true">{{ $b['icon'] }}</span>
                                <div>
                                    <b>{{ $b['name'] }}</b>
                                    <small>{{ $b['desc'] }}</small>
                                    @if ($b['earned'])
                                        <em class="jn-badge__ok">✓ Đã đạt</em>
                                    @else
                                        <span class="jn-bar" aria-hidden="true"><i
                                                style="width: {{ $b['percent'] }}%"></i></span>
                                        <small class="jn-badge__n">{{ $b['have'] }}/{{ $b['need'] }}</small>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>

                {{-- ===== Tour đã đặt ===== --}}
                <section class="jn-card" aria-labelledby="jnTripH">
                    <h2 id="jnTripH">Tour của bạn</h2>

                    @if (!count($upcoming) && !count($done))
                        <p class="jn-empty">Bạn chưa đặt tour nào.</p>
                        <a class="jn-btn" href="{{ route('tours') }}">Khám phá tour</a>
                    @endif

                    @if (count($upcoming))
                        <h3>Sắp đi</h3>
                        <ul class="jn-trips">
                            @foreach ($upcoming as $t)
                                <li>
                                    <a href="{{ $t->url }}">
                                        <b>{{ $t->title }}</b>
                                        <small>{{ $fmt($t->startDate) }} – {{ $fmt($t->endDate) }}
                                            @if ($t->destName)
                                                · {{ $t->destName }}
                                            @endif
                                            · {{ $t->bookingStatus === 'y' ? 'Đã xác nhận' : 'Chờ xác nhận' }}</small>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if (count($done))
                        <h3>Đã đi</h3>
                        <ul class="jn-trips">
                            @foreach ($done as $t)
                                <li>
                                    <a href="{{ $t->url }}">
                                        <b>{{ $t->title }}</b>
                                        <small>{{ $fmt($t->startDate) }} – {{ $fmt($t->endDate) }}
                                            @if ($t->destName)
                                                · {{ $t->destName }}
                                            @endif
                                        </small>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>
        </div>
    </div>
</main>

@include('clients.blocks.footer')
