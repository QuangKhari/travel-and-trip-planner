@extends('admin.layout')
@php
    $wd = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];
    $fmt = fn($d) => $wd[\Carbon\Carbon::parse($d)->dayOfWeek] . ', ' . \Carbon\Carbon::parse($d)->format('d/m');
    $vnd = fn($n) => number_format((int) $n, 0, ',', '.') . 'đ';
@endphp
@section('content')
    <div class="ad-head">
        <div>
            <a class="ad-back" href="{{ route('admin.departures') }}"><i class="fa fa-arrow-left"></i> Lịch khởi hành</a>
            <h1>Điều hành khởi hành</h1>
            <p>Các ngày khởi hành trong {{ $days }} ngày tới và đang đi trên một màn hình. Dòng <b>đỏ</b> cần xử lý ngay (thiếu khách sát ngày, giữ chỗ chưa trả sát ngày, chưa có hướng dẫn viên, sự cố mức cao), dòng <b>vàng</b> nên xem trong ngày.</p>
        </div>
        <form class="ad-actions" method="GET" action="{{ route('admin.departures.ops') }}">
            <select name="days" class="ad-field" onchange="this.form.submit()" aria-label="Khoảng ngày">
                @foreach ([7, 14, 30] as $d)<option value="{{ $d }}" @selected($days === $d)>{{ $d }} ngày tới</option>@endforeach
            </select>
        </form>
    </div>

    <div class="ad-kpis">
        <div class="ad-kpi"><div class="ad-kpi__ic ad-kpi__ic--red"><i class="fa fa-exclamation-circle"></i></div><div><div class="ad-kpi__l">Cần xử lý ngay</div><div class="ad-kpi__v">{{ $counts['red'] }}</div></div></div>
        <div class="ad-kpi"><div class="ad-kpi__ic ad-kpi__ic--violet"><i class="fa fa-exclamation-triangle"></i></div><div><div class="ad-kpi__l">Nên xem</div><div class="ad-kpi__v">{{ $counts['amber'] }}</div></div></div>
        <div class="ad-kpi"><div class="ad-kpi__ic ad-kpi__ic--teal"><i class="fa fa-check"></i></div><div><div class="ad-kpi__l">Ổn</div><div class="ad-kpi__v">{{ $counts['ok'] }}</div></div></div>
    </div>

    <section class="ad-card">
        @if ($rows)
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead><tr><th>Ngày đi → về</th><th>Tour</th><th class="num">Khách / tối thiểu</th><th class="num">Đã thu / còn thu</th><th>HDV · nguồn lực</th><th style="min-width:260px">Việc cần xử lý</th></tr></thead>
                    <tbody>
                    @foreach ($rows as $r)
                        <tr>
                            <td class="nowrap"><span class="ad-badge {{ ['red' => 'ad-badge--bad', 'amber' => 'ad-badge--warn', 'ok' => 'ad-badge--ok'][$r['tone']] }}">{{ ['red' => 'Gấp', 'amber' => 'Xem', 'ok' => 'Ổn'][$r['tone']] }}</span>
                                <b style="color:var(--ad-ink)">{{ $fmt($r['date']) }}</b> → {{ $fmt($r['end']) }}
                                <span class="ad-sub">{{ $r['ongoing'] ? 'Đang đi' : 'còn ' . $r['inDays'] . ' ngày' }}</span></td>
                            <td><a href="{{ route('admin.departures', ['tour' => $r['tourId']]) }}">{{ \Illuminate\Support\Str::limit($r['title'], 40) }}</a></td>
                            <td class="num"><b>{{ $r['seats'] }}</b>/{{ $r['total'] }}<span class="ad-sub">tối thiểu {{ $r['min'] }} · {{ $r['bookings'] }} đơn</span></td>
                            <td class="num">{{ $vnd($r['paid']) }}<span class="ad-sub">{{ $r['due'] ? 'còn ' . $vnd($r['due']) : 'đã thu đủ' }}</span></td>
                            <td>{{ $r['guide'] ?: '— chưa có HDV' }}<span class="ad-sub">{{ $r['resources'] }} nguồn lực đã xếp</span></td>
                            <td>
                                @forelse ($r['flags'] as $f)
                                    <div style="margin:0 0 3px"><i class="fa fa-circle" style="font-size:.55rem;color:{{ $f['tone'] === 'red' ? 'var(--ad-bad)' : '#d99a2b' }}"></i>
                                        @if ($f['link'])<a href="{{ $f['link'] }}">{{ $f['text'] }}</a>@else{{ $f['text'] }}@endif</div>
                                @empty
                                    <span class="ad-muted">Không có việc tồn.</span>
                                @endforelse
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="ad-note" style="margin:16px">Không có ngày khởi hành nào trong khoảng này.</p>
        @endif
    </section>
@endsection
