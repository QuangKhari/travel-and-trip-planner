{{--
  Heatmap 3 màu theo miền (học từ GoVietnam "Nên đi tháng nào"). $summary: từ SeasonPagesController::summarize();
  $month: tháng đang chọn (hoặc null); $region: miền đang lọc (hoặc null). Cần gx-pages.css.
--}}
@php
    $nowM = (int) date('n');
    $lbl = ['best' => 'Đẹp nhất', 'ok' => 'Đi được', 'avoid' => 'Nên tránh', 'unknown' => 'Chưa đủ dữ liệu'];
    $mq = fn($m) => array_filter(['thang' => $m, 'mien' => $region]);
@endphp
@if (!empty($summary))
    <section class="hm" aria-labelledby="hm-h">
        <h2 id="hm-h" class="hm-title">Tổng quan theo miền</h2>
        <div class="hm-scroll" role="region" tabindex="0" aria-label="Bảng mùa đẹp theo miền, 12 tháng">
            <table class="hm-grid">
                <thead>
                    <tr>
                        <td></td>
                        @for ($m = 1; $m <= 12; $m++)
                            <th scope="col" class="{{ $m === $nowM ? 'is-now' : '' }} {{ $month === $m ? 'is-sel' : '' }}"><a href="{{ route('seasons.matrix', $mq($m)) }}">T{{ $m }}</a></th>
                        @endfor
                    </tr>
                </thead>
                <tbody>
                    @foreach ($summary as $key => $s)
                        <tr>
                            <th scope="row">{{ $s['label'] }}</th>
                            @for ($m = 1; $m <= 12; $m++)
                                @php $lv = $s['levels'][$m]; @endphp
                                <td class="hm-c hm-c--{{ $lv }} {{ $m === $nowM ? 'is-now' : '' }}" title="{{ $s['label'] }}, tháng {{ $m }}: {{ $lbl[$lv] }}"><span class="gx-sr">{{ $s['label'] }}, tháng {{ $m }}: {{ $lbl[$lv] }}</span></td>
                            @endfor
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <ul class="hm-legend" aria-label="Chú giải">
            @foreach ($lbl as $k => $t)
                <li><span class="hm-sw hm-c--{{ $k }}" aria-hidden="true"></span>{{ $t }}</li>
            @endforeach
        </ul>
        <div class="hm-notes">
            @foreach ($summary as $key => $s)
                <p><b>{{ $s['label'] }}:</b>
                    @if ($s['best']) đẹp nhất vào tháng {{ $s['best'] }}@else chưa đủ dữ liệu để chỉ ra tháng đẹp nhất @endif
                    @if ($s['avoid']); nên tránh tháng {{ $s['avoid'] }}@endif.
                </p>
            @endforeach
            <p class="hm-foot">Màu từng miền là mức trung bình từ đánh giá của các điểm đến trong miền (miền Bắc {{ $summary['b']['n'] ?? 0 }}, miền Trung {{ $summary['t']['n'] ?? 0 }}, miền Nam {{ $summary['n']['n'] ?? 0 }} điểm). Từng nơi có thể khác, hãy xem bảng chi tiết bên dưới.</p>
        </div>
    </section>
@endif
