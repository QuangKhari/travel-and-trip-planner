@extends('admin.layout')
@section('content')
    @php $m = fn ($v) => number_format((float) $v, 0, ',', '.'); @endphp
    <div class="ad-head"><div><h1>Lợi nhuận từng đoàn</h1>
        <p>Doanh thu thực thu của mỗi ngày khởi hành trừ chi phí xe, khách sạn, hướng dẫn viên, vé… Đoàn đủ khách vẫn có thể lỗ. Nhập chi phí ở trang chi tiết từng đoàn; dòng “dự kiến” chưa chắc chắn.</p></div></div>
    @if (!$ready)
        <div class="ad-card"><div class="ad-empty"><i class="fa fa-database"></i><b>Chưa chạy migration</b>Chạy <code>php artisan migrate</code> (2026_11_30_000001).</div></div>
    @else
        <div class="ad-kpis">
            <div class="ad-kpi"><div><div class="ad-kpi__l">Thực thu (hiển thị)</div><div class="ad-kpi__v">{{ $m($tot['net']) }}</div></div></div>
            <div class="ad-kpi"><div><div class="ad-kpi__l">Chi phí</div><div class="ad-kpi__v">{{ $m($tot['cost']) }}</div></div></div>
            <div class="ad-kpi"><div><div class="ad-kpi__l">Lợi nhuận</div><div class="ad-kpi__v">{{ $m($tot['profit']) }}</div><div class="ad-kpi__h">{{ $tot['loss'] }} đoàn lỗ (đã nhập chi phí)</div></div></div>
        </div>
        <div class="ad-card">
            <div class="ad-toolbar"><div class="ad-chips">
                @foreach (['past' => 'Đã khởi hành', 'future' => 'Sắp khởi hành', 'all' => 'Tất cả'] as $k => $l)
                    <a class="ad-chip {{ $range === $k ? 'is-on' : '' }}" href="{{ route('admin.profit', ['range' => $k]) }}">{{ $l }}</a>
                @endforeach
            </div></div>
            @if (count($rows))
                <div class="ad-tablewrap"><table class="ad-table"><thead><tr><th>Đoàn</th><th>Khách</th><th class="num">Thực thu</th><th class="num">Chi phí</th><th class="num">Lợi nhuận</th><th>Biên</th><th></th></tr></thead><tbody>
                    @foreach ($rows as $r)
                        @php $p = $r->p; @endphp
                        <tr><td><b>{{ $r->title }}</b><span class="ad-sub">{{ date('d/m/Y', strtotime($r->departureDate)) }} · lịch #{{ $r->availabilityId }}</span></td>
                            <td>{{ $p['guests'] }}/{{ $r->totalSlots }}</td><td class="num">{{ $m($p['netRevenue']) }}</td>
                            <td class="num">{{ $p['hasCosts'] ? $m($p['costTotal']) : '—' }}@if ($p['estimated'])<span class="ad-sub">có dòng dự kiến</span>@endif</td>
                            <td class="num">@if ($p['hasCosts'])<b style="color:{{ $p['profit'] < 0 ? '#B3261E' : '#1E7B4F' }}">{{ $m($p['profit']) }}</b>@else<span class="ad-sub">Chưa nhập chi phí</span>@endif</td>
                            <td>@if ($p['hasCosts'] && $p['margin'] !== null){{ $p['margin'] }}%@if ($p['loss'])<span class="ad-badge ad-badge--bad">Lỗ</span>@endif @endif</td>
                            <td class="num"><a class="ad-btn ad-btn--sm" href="{{ route('admin.profit.show', $r->availabilityId) }}">Chi tiết</a></td></tr>
                    @endforeach
                </tbody></table></div>
            @else
                <div class="ad-empty"><i class="fa fa-line-chart"></i><b>Không có đoàn nào</b></div>
            @endif
        </div>
    @endif
@endsection
