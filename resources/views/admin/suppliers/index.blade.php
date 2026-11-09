@extends('admin.layout')
@section('content')
    @php $m = fn ($v) => number_format((float) $v, 0, ',', '.'); @endphp
    <div class="ad-head"><div><h1>Nhà cung cấp</h1>
        <p>Danh mục nhà cung cấp (xe, khách sạn, hướng dẫn viên, nhà hàng…) và chất lượng: điểm đánh giá sau mỗi đoàn, tỷ lệ đúng giờ, sự cố và khiếu nại gắn với nhà cung cấp. Nhà cung cấp có dấu hiệu kém được đưa lên đầu.</p></div></div>
    @if (!$ready)
        <div class="ad-card"><div class="ad-empty"><i class="fa fa-database"></i><b>Chưa chạy migration</b>Chạy <code>php artisan migrate</code> (2026_11_30_000001).</div></div>
    @else
        <section class="ad-card" style="margin-bottom:20px"><div class="ad-card__head"><h2>Thêm nhà cung cấp</h2></div>
            <form method="POST" action="{{ route('admin.suppliers.store') }}" class="ad-form" style="padding:0 16px 16px">@csrf
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px">
                    <div class="ad-fld"><label>Tên</label><input name="name" required minlength="2" maxlength="150" value="{{ old('name') }}"></div>
                    <div class="ad-fld"><label>Loại</label><select name="kind">@foreach ($kinds as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                    <div class="ad-fld"><label>Điện thoại</label><input name="phone" maxlength="20"></div>
                    <div class="ad-fld"><label>Email</label><input type="email" name="email" maxlength="80"></div>
                </div><button class="ad-btn ad-btn--primary" type="submit">Thêm</button></form></section>
        <div class="ad-card">
            @if (count($stats))
                <div class="ad-tablewrap"><table class="ad-table"><thead><tr><th>Nhà cung cấp</th><th>Số đoàn</th><th class="num">Tổng chi</th><th>Đánh giá</th><th>Đúng giờ</th><th>Sự cố</th><th>Khiếu nại</th><th></th></tr></thead><tbody>
                    @foreach ($stats as $id => $s)
                        <tr @if ($s['attention']) style="background:#FFF6F2" @endif>
                            <td><a href="{{ route('admin.suppliers.show', $id) }}"><b>{{ $s['supplier']->name }}</b></a><span class="ad-sub">{{ $kinds[$s['supplier']->kind] ?? $s['supplier']->kind }}{{ $s['supplier']->active ? '' : ' · ngưng dùng' }}</span>
                                @foreach ($s['flags'] as $f)<span class="ad-badge ad-badge--bad" style="display:block;margin-top:4px">{{ $f }}</span>@endforeach</td>
                            <td>{{ $s['trips'] }}</td><td class="num">{{ $m($s['spend']) }}</td>
                            <td>{{ $s['avg'] !== null ? $s['avg'] . '/5 (' . $s['ratings'] . ')' : '—' }}</td><td>{{ $s['onTimePct'] !== null ? $s['onTimePct'] . '%' : '—' }}</td>
                            <td>{{ $s['incidents'] }}</td><td>{{ $s['complaints'] }}</td>
                            <td class="num"><form method="POST" action="{{ route('admin.suppliers.toggle', $id) }}">@csrf<button class="ad-btn ad-btn--sm" type="submit">{{ $s['supplier']->active ? 'Ngưng dùng' : 'Dùng lại' }}</button></form></td></tr>
                    @endforeach
                </tbody></table></div>
            @else
                <div class="ad-empty"><b>Chưa có nhà cung cấp</b>Thêm nhà cung cấp ở trên rồi gắn vào chi phí của đoàn.</div>
            @endif
        </div>
    @endif
@endsection
