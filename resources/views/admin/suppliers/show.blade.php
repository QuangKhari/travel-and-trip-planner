@extends('admin.layout')
@section('content')
    @php $s = $st['supplier']; $m = fn ($v) => number_format((float) $v, 0, ',', '.'); @endphp
    <div class="ad-head"><div><h1>{{ $s->name }}</h1><p>{{ $kinds[$s->kind] ?? $s->kind }} · {{ $s->phone }} {{ $s->email }}</p></div><a class="ad-btn" href="{{ route('admin.suppliers') }}">← Danh sách</a></div>
    @foreach ($st['flags'] as $f)<div class="ad-card" style="margin-bottom:10px;border-left:4px solid #E76F51;padding:10px 16px">{{ $f }}</div>@endforeach
    <div class="ad-kpis">
        <div class="ad-kpi"><div><div class="ad-kpi__l">Điểm trung bình</div><div class="ad-kpi__v">{{ $st['avg'] ?? '—' }}</div><div class="ad-kpi__h">{{ $st['ratings'] }} đánh giá</div></div></div>
        <div class="ad-kpi"><div><div class="ad-kpi__l">Đúng giờ</div><div class="ad-kpi__v">{{ $st['onTimePct'] !== null ? $st['onTimePct'] . '%' : '—' }}</div></div></div>
        <div class="ad-kpi"><div><div class="ad-kpi__l">Sự cố / đoàn</div><div class="ad-kpi__v">{{ $st['incidents'] }}/{{ $st['trips'] }}</div><div class="ad-kpi__h">{{ $st['complaints'] }} khiếu nại · tổng chi {{ $m($st['spend']) }} đ</div></div></div>
    </div>
    <section class="ad-card"><div class="ad-card__head"><h2>Đánh giá gần đây</h2></div>
        @if ($ratings->count())
            <div class="ad-tablewrap"><table class="ad-table"><thead><tr><th>Đoàn</th><th>Điểm</th><th>Đúng giờ</th><th>Nhận xét</th><th>Lúc</th></tr></thead><tbody>
                @foreach ($ratings as $r)<tr><td>{{ $r->title ?? '—' }}<span class="ad-sub">{{ $r->departureDate ? date('d/m/Y', strtotime($r->departureDate)) : '' }}</span></td><td><b>{{ $r->rating }}/5</b></td>
                    <td>{{ $r->onTime === null ? '—' : ($r->onTime ? 'Đúng giờ' : 'Trễ / sự cố') }}</td><td>{{ $r->comment }}</td><td class="nowrap">{{ date('d/m/Y', strtotime($r->createdAt)) }}</td></tr>@endforeach
            </tbody></table></div>
        @else<div class="ad-empty"><b>Chưa có đánh giá</b>Đánh giá được nhập ở trang chi phí của từng đoàn sau khi khởi hành.</div>@endif
    </section>
@endsection
