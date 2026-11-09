@extends('admin.layout')
@section('content')
    @php $c = $fz['counts']; $byDay = collect($fz['findings'])->groupBy(fn ($x) => $x['day'] ?? 0)->sortKeys(); $lv = ['error' => ['bad', 'Lỗi'], 'warn' => ['warn', 'Cảnh báo'], 'info' => ['mute', 'Ghi chú']]; @endphp
    <div class="ad-head"><div><h1>{{ $tour->title }}</h1>
        <p>{{ $fz['days'] }} ngày · ngày đi đối chiếu giờ mở cửa: <b>{{ $fz['date'] ?? 'chưa có' }}</b> · {{ $fz['matchedPlaces'] }} điểm khớp dữ liệu địa điểm</p></div><a class="ad-btn" href="{{ route('admin.feasibility') }}">← Danh sách</a></div>
    <div class="ad-kpis">
        <div class="ad-kpi"><div><div class="ad-kpi__l">Kết quả</div><div class="ad-kpi__v">{{ ['ok' => 'Đạt', 'review' => 'Cần xem xét', 'blocked' => 'Có lỗi'][$fz['verdict']] }}</div></div></div>
        <div class="ad-kpi"><div><div class="ad-kpi__l">Lỗi / cảnh báo</div><div class="ad-kpi__v">{{ $c['error'] }} / {{ $c['warn'] }}</div></div></div>
        <div class="ad-kpi"><div><div class="ad-kpi__l">Kiểm tra thủ công</div><div class="ad-kpi__v">{{ $c['manual'] }}</div><div class="ad-kpi__h">Chưa có nguồn xác minh</div></div></div>
    </div>
    @if ($deps->count())
        <div class="ad-card" style="margin-bottom:16px;padding:12px 16px"><form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">Đối chiếu giờ mở cửa theo ngày đi:
            <select name="date">@foreach ($deps as $d)<option value="{{ $d }}" @selected($fz['date'] === $d)>{{ date('d/m/Y', strtotime($d)) }}</option>@endforeach</select><button class="ad-btn ad-btn--sm" type="submit">Kiểm tra</button></form></div>
    @endif
    <div class="ad-card">
        @if (count($fz['findings']))
            <div class="ad-tablewrap"><table class="ad-table"><thead><tr><th>Phạm vi</th><th>Mức</th><th>Nội dung</th></tr></thead><tbody>
                @foreach ($byDay as $day => $rows)
                    @foreach ($rows as $x)
                        <tr><td class="nowrap">{{ $day ? 'Ngày ' . $day : 'Cả tour' }}</td>
                            <td><span class="ad-badge ad-badge--{{ $lv[$x['level']][0] }}">{{ $lv[$x['level']][1] }}</span>@if ($x['manual'])<span class="ad-sub">Kiểm tra thủ công</span>@endif</td>
                            <td>{{ $x['message'] }}</td></tr>
                    @endforeach
                @endforeach
            </tbody></table></div>
        @else
            <div class="ad-empty"><i class="fa fa-check-circle"></i><b>Không phát hiện vấn đề</b>Các mốc giờ, giờ mở cửa và thời gian di chuyển kiểm được đều hợp lý.</div>
        @endif
    </div>
@endsection
