@php
    $metaDescription = $month
        ? 'Nên đi đâu vào tháng ' . $month . ' ở Việt Nam: bảng mùa đẹp theo điểm đến và tháng.'
        : 'Nên đi tháng nào: bảng mùa đẹp của từng điểm đến ở Việt Nam theo 12 tháng, chia theo miền.';
    $sym = ['best' => '★', 'ok' => '○', 'avoid' => '×', 'unknown' => '·'];
    $lbl = ['best' => 'đẹp nhất', 'ok' => 'đi được', 'avoid' => 'nên tránh', 'unknown' => 'chưa có dữ liệu'];
    $q = fn($m) => array_filter(['thang' => $m, 'mien' => $region]);
@endphp
@include('clients.blocks.header')
{{-- /nen-di-thang-nao[/{thang}] — biến: $groups (theo miền), $regions, $region, $month, $best --}}
<main class="gx-page" id="main">
    <div class="gx-wrap">
        @include('clients.partials.gx.crumb', ['crumbs' => array_values(array_filter([
            ['Trang chủ', route('home')],
            $month ? ['Nên đi tháng nào', route('seasons.matrix')] : ['Nên đi tháng nào'],
            $month ? ['Tháng ' . $month] : null,
        ]))])
        <header class="gx-head">
            <p class="gx-eyebrow">Mùa đẹp</p>
            <h1>{{ $month ? 'Nên đi tháng ' . $month . ' ở đâu?' : 'Nên đi tháng nào?' }}</h1>
            <p class="gx-lead">Mỗi điểm đến một dòng, mỗi tháng một ô. Bấm một ô để xem điểm đến đẹp của tháng đó.</p>
        </header>

        <nav aria-label="Lọc theo miền">
            <div class="gx-chips">
                <a class="gx-chip {{ $region ? '' : 'is-on' }}" href="{{ route('seasons.matrix', array_filter(['thang' => $month])) }}" @if (!$region) aria-current="true" @endif>Cả nước</a>
                @foreach ($regions as $key => $label)
                    <a class="gx-chip {{ $region === $key ? 'is-on' : '' }}" href="{{ route('seasons.matrix', array_filter(['thang' => $month, 'mien' => $key])) }}" @if ($region === $key) aria-current="true" @endif>{{ $label }}</a>
                @endforeach
            </div>
        </nav>

        @include('clients.partials.gx.season-heatmap', ['summary' => $summary, 'month' => $month, 'region' => $region])

        <h2 class="hm-sub">Chi tiết từng điểm đến</h2>
        <ul class="gx-legend" aria-label="Chú giải">
            @foreach ($lbl as $k => $t)
                <li><span class="gx-sm gx-sm--{{ $k }}" aria-hidden="true">{{ $sym[$k] }}</span> {{ ucfirst($t) }}</li>
            @endforeach
        </ul>

        @php $shown = 0; @endphp
        @foreach ($regions as $key => $label)
            @if (isset($groups[$key]))
                @php $shown++; @endphp
                <section class="gx-box" aria-labelledby="mx-{{ $key }}">
                    <h2 id="mx-{{ $key }}">{{ $label }}</h2>
                    <div class="gx-matrix-wrap" tabindex="0" role="region" aria-label="Bảng mùa đẹp {{ $label }}">
                        <table class="gx-matrix">
                            <thead>
                                <tr>
                                    <th scope="col">Điểm đến</th>
                                    @for ($m = 1; $m <= 12; $m++)
                                        <th scope="col" class="{{ $month === $m ? 'is-sel' : '' }}"><a href="{{ route('seasons.matrix', $q($m)) }}">T{{ $m }}</a></th>
                                    @endfor
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($groups[$key] as $row)
                                    <tr>
                                        <th scope="row"><a href="{{ $row['url'] }}">{{ $row['name'] }}</a></th>
                                        @for ($m = 1; $m <= 12; $m++)
                                            @php $lv = $row['months'][$m]; @endphp
                                            <td class="gx-sm gx-sm--{{ $lv }} {{ $month === $m ? 'is-sel' : '' }}">
                                                <a href="{{ route('seasons.matrix', $q($m)) }}"><span aria-hidden="true">{{ $sym[$lv] }}</span><span class="gx-sr">{{ $row['name'] }}, tháng {{ $m }}: {{ $lbl[$lv] }}</span></a>
                                            </td>
                                        @endfor
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
        @endforeach
        @if ($shown === 0)
            <p class="gx-empty">Chưa có điểm đến nào có dữ liệu mùa đẹp trong bộ lọc này.</p>
        @endif

        @if ($month)
            <section class="gx-box" id="dep-nhat" aria-labelledby="h-best">
                <h2 id="h-best">Đẹp nhất vào tháng {{ $month }}</h2>
                @if ($best->isEmpty())
                    <p class="gx-empty">Chưa có điểm đến nào được đánh dấu đẹp nhất trong tháng này.</p>
                @else
                    <div class="gx-grid">@foreach ($best as $c) @include('clients.partials.gx.card', ['c' => $c]) @endforeach</div>
                @endif
                <p class="gx-more"><a href="{{ route('seasons.now', ['thang' => $month]) }}">Xem thêm lễ hội tháng {{ $month }}</a></p>
            </section>
        @endif
    </div>
</main>
@include('clients.blocks.footer')
