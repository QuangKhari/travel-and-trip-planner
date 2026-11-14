@extends('admin.layout')
@section('content')
    @php $hm = fn($m) => $m === null ? '' : sprintf('%02d:%02d', intdiv($m, 60), $m % 60); @endphp
    <div class="ad-head">
        <div>
            <h1>Dữ liệu địa điểm</h1>
            <p>Người dùng báo giờ / giá sai ngay trên thẻ địa điểm của Trip Planner. Duyệt thì dữ liệu cập nhật cho mọi người; giờ đặc biệt (đóng cửa tạm thời, giờ theo mùa) áp dụng ngay cho bộ xếp lịch và chuông theo dõi chuyến.</p>
        </div>
    </div>
    @if (!$ready)
        <div class="ad-card"><div class="ad-empty"><i class="fa fa-database"></i><b>Chưa chạy migration</b>Chạy <code>php artisan migrate</code> (2026_12_09_000001).</div></div>
    @else
        <nav class="ad-tabs" style="display:flex;gap:8px;margin-bottom:14px">
            <a class="ad-btn ad-btn--sm {{ $tab === 'reports' ? 'ad-btn--primary' : 'ad-btn--ghost' }}" href="{{ route('admin.place-data') }}">Báo sai <span class="ad-badge">{{ $counts['open'] }}</span></a>
            <a class="ad-btn ad-btn--sm {{ $tab === 'rules' ? 'ad-btn--primary' : 'ad-btn--ghost' }}" href="{{ route('admin.place-data', ['tab' => 'rules']) }}">Giờ đặc biệt</a>
        </nav>
        @if ($errors->any())<div class="ad-alert ad-alert--bad">{{ $errors->first() }}</div>@endif

        @if ($tab === 'reports')
            <section class="ad-card">
                <div class="ad-card__head"><h2>Báo cáo {{ ['open' => 'chờ duyệt', 'accepted' => 'đã duyệt', 'rejected' => 'đã từ chối'][$status] }}</h2>
                    <div style="display:flex;gap:6px">@foreach (['open' => 'Chờ duyệt', 'accepted' => 'Đã duyệt', 'rejected' => 'Từ chối'] as $k => $l)
                        <a class="ad-btn ad-btn--sm {{ $status === $k ? '' : 'ad-btn--ghost' }}" href="{{ route('admin.place-data', ['status' => $k]) }}">{{ $l }}</a>@endforeach</div></div>
                @if ($reports->isEmpty())
                    <div class="ad-empty"><i class="fa fa-check"></i><b>Không có báo cáo</b></div>
                @else
                    <table class="ad-table">
                        <thead><tr><th>#</th><th>Nơi</th><th>Loại</th><th>Người báo / chi tiết</th><th>Đề xuất</th><th></th></tr></thead>
                        <tbody>
                        @foreach ($reports as $r)
                            <tr>
                                <td>{{ $r->reportId }}<br><small class="ad-muted">{{ \Carbon\Carbon::parse($r->createdAt)->format('d/m H:i') }}</small></td>
                                <td><b>{{ $r->name }}</b><br><small class="ad-muted">{{ $r->refType }} #{{ $r->refId }}{{ $r->hours ? ' · đã có giờ' : ' · chưa có giờ' }}</small></td>
                                <td>{{ $kinds[$r->kind] ?? $r->kind }}@if ($r->same > 1)<br><span class="ad-badge">{{ $r->same }} người cùng báo</span>@endif</td>
                                <td>{{ $r->username ?? 'khách' }}<br><small>{{ $r->detail }}</small></td>
                                @if ($status === 'open')
                                    <td colspan="2">
                                        <form method="POST" action="{{ route('admin.place-data.decide', $r->reportId) }}" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">@csrf
                                            @if (in_array($r->kind, ['hours', 'price'], true))
                                                <input name="suggested" value="{{ $r->suggested }}" maxlength="120" style="min-width:180px" placeholder="{{ $r->kind === 'hours' ? 'Mo-Su 08:00-17:00; Mo off' : '50000' }}">
                                            @endif
                                            <button class="ad-btn ad-btn--sm ad-btn--primary" name="decision" value="accept">Duyệt</button>
                                            <button class="ad-btn ad-btn--sm ad-btn--ghost" name="decision" value="reject">Từ chối</button>
                                        </form>
                                    </td>
                                @else
                                    <td>{{ $r->suggested }}</td><td><small class="ad-muted">{{ $r->reviewNote }}</small></td>
                                @endif
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </section>
        @else
            <section class="ad-card" style="margin-bottom:20px">
                <div class="ad-card__head"><h2>Thêm giờ đặc biệt</h2></div>
                <form method="POST" action="{{ route('admin.place-data.rules.store') }}" class="ad-form" style="padding:0 16px 16px">@csrf
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px">
                        <div class="ad-fld"><label for="hr-t">Áp dụng cho</label><select id="hr-t" name="target">
                            <option value="place" @selected(old('target') === 'place')>Một địa điểm</option><option value="experience" @selected(old('target') === 'experience')>Một trải nghiệm</option>
                            <option value="destination" @selected(old('target') === 'destination')>Cả điểm đến</option></select></div>
                        <div class="ad-fld"><label for="hr-id">Mã địa điểm / trải nghiệm</label><input id="hr-id" type="number" min="1" name="refId" value="{{ old('refId') }}"></div>
                        <div class="ad-fld"><label for="hr-d">Điểm đến (nếu cả điểm đến)</label><select id="hr-d" name="destinationId"><option value="">—</option>
                            @foreach ($destinations as $d)<option value="{{ $d->destinationId }}" @selected((string) old('destinationId') === (string) $d->destinationId)>{{ $d->name }}</option>@endforeach</select></div>
                        <div class="ad-fld"><label for="hr-k">Loại</label><select id="hr-k" name="kind"><option value="closed">Đóng cửa</option><option value="hours" @selected(old('kind') === 'hours')>Giờ khác thường</option></select></div>
                        <div class="ad-fld"><label for="hr-f">Từ ngày</label><input id="hr-f" type="date" name="dateFrom" required value="{{ old('dateFrom', now()->toDateString()) }}"></div>
                        <div class="ad-fld"><label for="hr-to">Đến ngày</label><input id="hr-to" type="date" name="dateTo" required value="{{ old('dateTo') }}"></div>
                        <div class="ad-fld"><label for="hr-o">Mở (nếu giờ khác thường)</label><input id="hr-o" type="time" name="open" value="{{ old('open') }}"></div>
                        <div class="ad-fld"><label for="hr-c">Đóng</label><input id="hr-c" type="time" name="close" value="{{ old('close') }}"></div>
                        <div class="ad-fld"><label><input type="checkbox" name="yearly" value="1" @checked(old('yearly'))> Lặp lại mỗi năm (giờ theo mùa)</label></div>
                        <div class="ad-fld" style="grid-column:1/-1"><label for="hr-n">Ghi chú (người dùng thấy)</label><input id="hr-n" name="note" maxlength="200" value="{{ old('note') }}" placeholder="Ví dụ: tu bổ mái, mở lại 15/1"></div>
                    </div>
                    <div style="margin-top:14px"><button class="ad-btn ad-btn--primary" type="submit" style="width:auto">Thêm</button></div>
                </form>
                <p class="ad-muted" style="padding:0 16px 16px">Ngày lễ (Tết, 30/4, 1/5, 2/9, Giỗ Tổ) lấy theo giờ "PH" của từng nơi, ví dụ <code>Mo-Su 08:00-17:00; PH off</code>. Ngày nghỉ bù do nhà nước công bố thì thêm quy tắc "Đóng cửa" cho cả điểm đến.</p>
            </section>
            <section class="ad-card">
                <div class="ad-card__head"><h2>Đang áp dụng / sắp tới</h2></div>
                @if ($rules->isEmpty())
                    <div class="ad-empty"><i class="fa fa-clock-o"></i><b>Chưa có quy tắc</b></div>
                @else
                    <table class="ad-table"><thead><tr><th>#</th><th>Áp dụng cho</th><th>Loại</th><th>Thời gian</th><th>Ghi chú</th><th></th></tr></thead><tbody>
                    @foreach ($rules as $h)
                        <tr><td>{{ $h->ruleId }}</td><td>{{ $h->name }}</td>
                            <td>{{ $h->kind === 'closed' ? 'Đóng cửa' : 'Giờ ' . $hm($h->openMin) . '–' . $hm($h->closeMin) }}</td>
                            <td>{{ \Carbon\Carbon::parse($h->dateFrom)->format($h->yearly ? 'd/m' : 'd/m/Y') }} – {{ \Carbon\Carbon::parse($h->dateTo)->format($h->yearly ? 'd/m' : 'd/m/Y') }}{{ $h->yearly ? ' hằng năm' : '' }}</td>
                            <td><small>{{ $h->note }}</small> <small class="ad-muted">({{ $h->source }})</small></td>
                            <td><form method="POST" action="{{ route('admin.place-data.rules.delete', $h->ruleId) }}" onsubmit="return confirm('Xóa quy tắc này?')">@csrf<button class="ad-btn ad-btn--sm ad-btn--ghost">Xóa</button></form></td></tr>
                    @endforeach
                    </tbody></table>
                @endif
            </section>
        @endif
    @endif
@endsection
