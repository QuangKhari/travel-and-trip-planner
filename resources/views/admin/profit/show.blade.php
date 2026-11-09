@extends('admin.layout')
@section('content')
    @php $m = fn ($v) => number_format((float) $v, 0, ',', '.'); @endphp
    <div class="ad-head"><div><h1>{{ $dep->title }} — {{ date('d/m/Y', strtotime($dep->departureDate)) }}</h1>
        <p>Lịch #{{ $dep->availabilityId }} · {{ $p['guests'] }} khách xác nhận / {{ $dep->totalSlots }} chỗ</p></div><a class="ad-btn" href="{{ route('admin.profit') }}">← Danh sách</a></div>
    <div class="ad-kpis">
        <div class="ad-kpi"><div><div class="ad-kpi__l">Thực thu</div><div class="ad-kpi__v">{{ $m($p['netRevenue']) }}</div><div class="ad-kpi__h">Đã thu {{ $m($p['cashIn']) }} − hoàn {{ $m($p['refunded']) }} − bồi hoàn {{ $m($p['compensationPaid']) }}</div></div></div>
        <div class="ad-kpi"><div><div class="ad-kpi__l">Chi phí</div><div class="ad-kpi__v">{{ $m($p['costTotal']) }}</div><div class="ad-kpi__h">Thực tế {{ $m($p['costActual']) }} · dự kiến {{ $m($p['costEstimated']) }}</div></div></div>
        <div class="ad-kpi"><div><div class="ad-kpi__l">Lợi nhuận</div><div class="ad-kpi__v" style="color:{{ $p['profit'] < 0 ? '#B3261E' : '#1E7B4F' }}">{{ $m($p['profit']) }}</div><div class="ad-kpi__h">@if ($p['margin'] !== null)Biên {{ $p['margin'] }}% · @endif dự kiến sau khi xử lý hết khoản chờ: {{ $m($p['projectedProfit']) }}</div></div></div>
    </div>
    @if ($p['hasCosts'] && $p['loss'])<div class="ad-card" style="margin-bottom:16px;border-left:4px solid #E76F51;padding:12px 16px"><b>Đoàn này đang lỗ {{ $m(-$p['profit']) }} đ.</b> Đủ khách vẫn có thể lỗ khi chi phí vận hành cao hơn thực thu.</div>@endif
    <div style="display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr);gap:20px;align-items:start">
        <section class="ad-card"><div class="ad-card__head"><h2>Chi phí</h2></div>
            @if ($costs->count())
                <div class="ad-tablewrap"><table class="ad-table"><thead><tr><th>Hạng mục</th><th>Nhà cung cấp</th><th class="num">Số tiền</th><th>Độ chắc</th><th></th></tr></thead><tbody>
                    @foreach ($costs as $c)
                        <tr><td>{{ $cats[$c->category] ?? $c->category }}<span class="ad-sub">{{ $c->description }}</span></td><td>{{ $c->supplierName ?? '—' }}</td><td class="num">{{ $m($c->amount) }}</td>
                            <td><span class="ad-badge ad-badge--{{ $c->status === 'actual' ? 'ok' : 'warn' }}">{{ $c->status === 'actual' ? 'Thực tế' : 'Dự kiến' }}</span></td>
                            <td class="num" style="white-space:nowrap">@if ($c->status === 'estimated')<form method="POST" action="{{ route('admin.profit.cost.actual', [$dep->availabilityId, $c->costId]) }}" style="display:inline">@csrf<button class="ad-btn ad-btn--sm" type="submit">Chốt thực tế</button></form>@endif
                                <form method="POST" action="{{ route('admin.profit.cost.delete', [$dep->availabilityId, $c->costId]) }}" style="display:inline">@csrf<button class="ad-btn ad-btn--sm" type="submit" data-confirm="Xóa dòng chi phí này?">Xóa</button></form></td></tr>
                    @endforeach
                </tbody></table></div>
            @else
                <div class="ad-empty"><b>Chưa nhập chi phí</b>Thêm chi phí xe, khách sạn, hướng dẫn viên… để biết lợi nhuận.</div>
            @endif
        </section>
        <div>
            <section class="ad-card" style="margin-bottom:20px"><div class="ad-card__head"><h2>Thêm chi phí</h2></div><div class="ad-card__body">
                <form method="POST" action="{{ route('admin.profit.cost.add', $dep->availabilityId) }}" class="ad-form">@csrf
                    <div class="ad-fld"><label>Hạng mục</label><select name="category">@foreach ($cats as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                    <div class="ad-fld"><label>Mô tả</label><input name="description" required minlength="3" maxlength="200" placeholder="Vd: Xe 45 chỗ 2 ngày"></div>
                    <div class="ad-fld"><label>Số tiền (đ)</label><input type="number" name="amount" min="1" required></div>
                    <div class="ad-fld"><label>Nhà cung cấp</label><select name="supplierId"><option value="">Không gắn</option>@foreach ($suppliers as $s)<option value="{{ $s->supplierId }}">{{ $s->name }}</option>@endforeach</select></div>
                    <div class="ad-fld"><label>Độ chắc chắn</label><select name="status"><option value="estimated">Dự kiến</option><option value="actual">Thực tế</option></select></div>
                    <button class="ad-btn ad-btn--primary" type="submit">Thêm</button></form></div></section>
            @if ($used->count())
                <section class="ad-card"><div class="ad-card__head"><h2>Đánh giá nhà cung cấp</h2></div><div class="ad-card__body">
                    @if (!$past)<p>Đánh giá được sau khi đoàn khởi hành.</p>
                    @else
                        @foreach ($suppliers->whereIn('supplierId', $used->all()) as $s)
                            @if ($rated->has($s->supplierId))<p><b>{{ $s->name }}</b>: đã đánh giá {{ $rated[$s->supplierId] }}/5</p>
                            @else
                                <form method="POST" action="{{ route('admin.profit.rate', $dep->availabilityId) }}" class="ad-form" style="margin-bottom:12px">@csrf
                                    <input type="hidden" name="supplierId" value="{{ $s->supplierId }}"><b>{{ $s->name }}</b>
                                    <div class="ad-fld"><label>Điểm (1–5)</label><select name="rating">@foreach ([5, 4, 3, 2, 1] as $i)<option>{{ $i }}</option>@endforeach</select></div>
                                    <div class="ad-fld"><label>Đúng giờ?</label><select name="onTime"><option value="">Không rõ</option><option value="1">Đúng giờ</option><option value="0">Trễ / sự cố</option></select></div>
                                    <div class="ad-fld"><input name="comment" maxlength="500" placeholder="Nhận xét ngắn"></div>
                                    <button class="ad-btn ad-btn--sm" type="submit">Lưu đánh giá</button></form>
                            @endif
                        @endforeach
                    @endif
                </div></section>
            @endif
        </div>
    </div>
@endsection
