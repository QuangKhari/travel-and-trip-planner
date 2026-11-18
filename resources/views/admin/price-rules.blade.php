@extends('admin.layout')
@section('content')
    <div class="ad-head">
        <div>
            <h1>Giá theo mùa</h1>
            <p>Tăng / giảm % đơn giá theo <b>ngày khởi hành</b>, cho một tour hoặc mọi tour (quy tắc riêng của tour được ưu tiên; mỗi đơn chỉ áp một quy tắc).
                Mức đã áp được lưu vào đơn lúc đặt: đổi quy tắc không làm đổi giá đơn đã đặt, và điều chỉnh số khách về sau tính theo đúng mức đó. Giới hạn {{ \App\Services\PriceRules::MIN }}% đến +{{ \App\Services\PriceRules::MAX }}%.</p>
        </div>
    </div>
    @if (session('success'))<div class="ad-flash ad-flash--ok">{{ session('success') }}</div>@endif
    @if ($errors->any())<div class="ad-flash ad-flash--bad">{{ $errors->first() }}</div>@endif

    <div class="ad-grid ad-grid--side">
        <section class="ad-card">
            <div class="ad-card__head"><h2>Quy tắc</h2></div>
            @if ($rules->count())
                <div class="ad-tablewrap"><table class="ad-table">
                    <thead><tr><th>Nhãn</th><th>Phạm vi</th><th>Khoảng ngày khởi hành</th><th class="num">Mức</th><th>Trạng thái</th><th class="num"></th></tr></thead>
                    <tbody>
                    @foreach ($rules as $r)
                        <tr class="{{ $r->isActive ? '' : 'is-muted' }}">
                            <td><b>{{ $r->label }}</b></td>
                            <td>{{ $r->tourId ? \Illuminate\Support\Str::limit($r->title, 36) : 'Mọi tour' }}</td>
                            <td class="nowrap">{{ \Carbon\Carbon::parse($r->dateFrom)->format('d/m/Y') }} → {{ \Carbon\Carbon::parse($r->dateTo)->format('d/m/Y') }}</td>
                            <td class="num"><b style="color:{{ $r->pct > 0 ? 'var(--ad-bad)' : 'var(--ad-primary)' }}">{{ $r->pct > 0 ? '+' : '' }}{{ $r->pct }}%</b></td>
                            <td>{!! $r->isActive ? '<span class="ad-badge ad-badge--ok">Đang áp</span>' : '<span class="ad-badge ad-badge--mute">Tắt</span>' !!}</td>
                            <td class="num"><form method="POST" action="{{ route('admin.price-rules.toggle', $r->ruleId) }}">@csrf<button class="ad-btn ad-btn--sm" type="submit">{{ $r->isActive ? 'Tắt' : 'Bật' }}</button></form></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            @else
                <p class="ad-note" style="margin:16px">Chưa có quy tắc nào: mọi ngày khởi hành dùng giá gốc của tour.</p>
            @endif
        </section>
        <section class="ad-card">
            <div class="ad-card__head"><h2>Thêm quy tắc</h2></div>
            <form method="POST" action="{{ route('admin.price-rules.store') }}" class="ad-card__body" style="display:grid;gap:10px">
                @csrf
                <label>Nhãn<input class="ad-field" name="label" maxlength="80" required placeholder="vd: Cao điểm Tết" value="{{ old('label') }}"></label>
                <label>Tour<select class="ad-field" name="tourId"><option value="">Mọi tour</option>@foreach ($tours as $t)<option value="{{ $t->tourId }}" @selected(old('tourId') == $t->tourId)>{{ \Illuminate\Support\Str::limit($t->title, 50) }}</option>@endforeach</select></label>
                <label>Từ ngày<input class="ad-field" type="date" name="dateFrom" required value="{{ old('dateFrom') }}"></label>
                <label>Đến ngày<input class="ad-field" type="date" name="dateTo" required value="{{ old('dateTo') }}"></label>
                <label>Mức % (âm = giảm giá)<input class="ad-field" type="number" name="pct" min="{{ \App\Services\PriceRules::MIN }}" max="{{ \App\Services\PriceRules::MAX }}" required value="{{ old('pct') }}"></label>
                <button class="ad-btn ad-btn--primary" type="submit">Thêm quy tắc</button>
            </form>
        </section>
    </div>
@endsection
