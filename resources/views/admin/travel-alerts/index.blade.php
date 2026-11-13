@extends('admin.layout')
@section('content')
    @php
        $tone = ['info' => 'info', 'warn' => 'warn', 'bad' => 'bad'];
        $f = $edit ?? null;
        $val = fn(string $k, $d = '') => old($k, $f->$k ?? $d);
        $today = now()->toDateString();
    @endphp
    <div class="ad-head">
        <div>
            <h1>Cảnh báo điểm đến</h1>
            <p>Sạt lở, đường cấm, điểm tạm đóng cửa, bão… La bàn đối chiếu với điểm đến, địa điểm và ngày đi của từng chuyến để <b>nhắc chủ động</b> và gợi ý đổi lịch. Ghi rõ nguồn để người dùng tự kiểm tra; cảnh báo hết hạn tự thôi hiển thị.</p>
        </div>
    </div>

    @if (!$ready)
        <div class="ad-card"><div class="ad-empty"><i class="fa fa-database"></i><b>Chưa chạy migration</b>Chạy <code>php artisan migrate</code> (2026_12_04_000001).</div></div>
    @else
        <section class="ad-card" style="margin-bottom:20px" id="ta-form">
            <div class="ad-card__head"><h2>{{ $f ? 'Sửa cảnh báo #' . $f->alertId : 'Thêm cảnh báo' }}</h2>
                @if ($f)<a class="ad-btn ad-btn--sm ad-btn--ghost" href="{{ route('admin.travel-alerts') }}">Hủy sửa</a>@endif</div>
            <form method="POST" action="{{ $f ? route('admin.travel-alerts.update', $f->alertId) : route('admin.travel-alerts.store') }}" class="ad-form" style="padding:0 16px 16px">@csrf
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px">
                    <div class="ad-fld" style="grid-column:1/-1"><label for="ta-title">Tiêu đề</label>
                        <input id="ta-title" name="title" required minlength="4" maxlength="160" value="{{ $val('title') }}" placeholder="Ví dụ: Đèo Prenn phân luồng sửa đường"></div>
                    <div class="ad-fld"><label for="ta-kind">Loại</label><select id="ta-kind" name="kind">@foreach ($kinds as $k => $l)<option value="{{ $k }}" @selected($val('kind', 'other') === $k)>{{ $l }}</option>@endforeach</select></div>
                    <div class="ad-fld"><label for="ta-level">Mức</label><select id="ta-level" name="level">@foreach ($levels as $k => $l)<option value="{{ $k }}" @selected($val('level', 'warn') === $k)>{{ $l }}</option>@endforeach</select></div>
                    <div class="ad-fld"><label for="ta-dest">Điểm đến</label><select id="ta-dest" name="destinationId"><option value="">— chọn —</option>
                        @foreach ($destinations as $d)<option value="{{ $d->destinationId }}" @selected((string) $val('destinationId') === (string) $d->destinationId)>{{ $d->name }}</option>@endforeach</select></div>
                    <div class="ad-fld"><label for="ta-place">Mã địa điểm (nếu chỉ một nơi)</label><input id="ta-place" type="number" min="1" name="placeId" value="{{ $val('placeId') }}" placeholder="placeId trong Địa điểm (OSM)"></div>
                    <div class="ad-fld"><label for="ta-from">Từ ngày</label><input id="ta-from" type="date" name="startsOn" required value="{{ $val('startsOn', $today) }}"></div>
                    <div class="ad-fld"><label for="ta-to">Đến ngày <small class="ad-muted">(bỏ trống nếu chưa rõ)</small></label><input id="ta-to" type="date" name="endsOn" value="{{ $val('endsOn') }}"></div>
                    <div class="ad-fld" style="grid-column:1/-1"><label for="ta-detail">Chi tiết</label><textarea id="ta-detail" name="detail" rows="2" maxlength="600">{{ $val('detail') }}</textarea></div>
                    <div class="ad-fld"><label for="ta-advice">Nên làm gì</label><input id="ta-advice" name="advice" maxlength="300" value="{{ $val('advice') }}" placeholder="Ví dụ: đi đèo Mimosa, xuất phát trước 21:00"></div>
                    <div class="ad-fld"><label for="ta-src">Nguồn</label><input id="ta-src" type="url" name="sourceUrl" maxlength="255" value="{{ $val('sourceUrl') }}" placeholder="https://…"></div>
                </div>
                <div style="margin-top:14px"><button class="ad-btn ad-btn--primary" type="submit" style="width:auto">{{ $f ? 'Lưu thay đổi' : 'Thêm cảnh báo' }}</button></div>
            </form>
        </section>

        <div class="ad-card">
            <div class="ad-toolbar"><div class="ad-chips">
                @foreach (['active' => 'Đang hiệu lực', 'ended' => 'Đã hết hạn', 'off' => 'Đã tắt', 'all' => 'Tất cả'] as $k => $l)
                    <a class="ad-chip {{ $status === $k ? 'is-on' : '' }}" href="{{ route('admin.travel-alerts', ['status' => $k]) }}">{{ $l }} <b>{{ $counts[$k] }}</b></a>
                @endforeach
            </div></div>
            @if ($rows->count())
                <div class="ad-tablewrap"><table class="ad-table"><thead><tr><th>Cảnh báo</th><th>Phạm vi</th><th>Thời gian</th><th>Chuyến bị ảnh hưởng</th><th class="num">Thao tác</th></tr></thead><tbody>
                    @foreach ($rows as $r)
                        @php $ended = $r->endsOn && $r->endsOn < $today; @endphp
                        <tr class="{{ !$r->isActive || $ended ? 'is-muted' : '' }}">
                            <td><span class="ad-badge ad-badge--{{ $tone[$r->level] ?? 'warn' }}">{{ $levels[$r->level] ?? $r->level }}</span> <b>{{ $r->title }}</b>
                                <span class="ad-sub">{{ $kinds[$r->kind] ?? $r->kind }}{{ $r->detail ? ' · ' . \Illuminate\Support\Str::limit($r->detail, 90) : '' }}</span>
                                @if ($r->sourceUrl)<a class="ad-sub" href="{{ $r->sourceUrl }}" target="_blank" rel="noopener">Nguồn</a>@endif</td>
                            <td>{{ $r->destName ?? '—' }}@if ($r->placeName)<span class="ad-sub">Chỉ: {{ $r->placeName }}</span>@endif</td>
                            <td class="nowrap">{{ date('d/m/Y', strtotime($r->startsOn)) }} → {{ $r->endsOn ? date('d/m/Y', strtotime($r->endsOn)) : 'chưa rõ' }}
                                @if ($ended)<span class="ad-sub">đã hết hạn</span>@elseif (!$r->isActive)<span class="ad-sub">đang tắt</span>@endif</td>
                            <td>{{ $r->trips }} chuyến</td>
                            <td class="num nowrap">
                                <a class="ad-btn ad-btn--sm ad-btn--ghost" href="{{ route('admin.travel-alerts', ['status' => $status, 'edit' => $r->alertId]) }}#ta-form">Sửa</a>
                                <form method="POST" action="{{ route('admin.travel-alerts.toggle', $r->alertId) }}" style="display:inline">@csrf<button class="ad-btn ad-btn--sm" type="submit">{{ $r->isActive ? 'Tắt' : 'Bật lại' }}</button></form>
                            </td>
                        </tr>
                    @endforeach
                </tbody></table></div>
            @else
                <div class="ad-empty"><i class="fa fa-check-circle"></i><b>Không có cảnh báo nào</b>{{ $status === 'active' ? 'Hiện chưa có cảnh báo đang hiệu lực.' : 'Không có mục nào ở nhóm này.' }}</div>
            @endif
        </div>
    @endif
@endsection
