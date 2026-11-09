@extends('admin.layout')
{{-- /admin/resources: xếp xe / hướng dẫn viên cho đoàn thường. Biến: ResourceScheduleController::index. --}}
@php
    $wd = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];
    $fmtD = fn($d) => $wd[\Carbon\Carbon::parse($d)->dayOfWeek] . ' ' . \Carbon\Carbon::parse($d)->format('d/m');
    $fmtT = fn($d) => \Carbon\Carbon::parse($d)->format('d/m H:i');
    $kindIcon = ['transport' => 'fa-bus', 'guide' => 'fa-user', 'activity' => 'fa-ticket'];
@endphp
@section('content')
<div class="ad-head">
    <div>
        <a class="ad-back" href="{{ route('admin.departures') }}"><i class="fa fa-arrow-left"></i> Lịch khởi hành</a>
        <h1>Xếp xe &amp; hướng dẫn viên</h1>
        <p>Mỗi đoàn được xếp tài nguyên theo <b>khung giờ</b>. Hệ thống không cho một xe chở quá số chỗ hay một hướng dẫn viên nhận hai đoàn trùng giờ; hai ca nối tiếp nhau (12:00 kết thúc, 12:00 bắt đầu) không bị coi là trùng.</p>
    </div>
    @if ($ready)
        <form class="ad-actions" method="GET" action="{{ route('admin.resources') }}">
            <a class="ad-btn ad-btn--sm ad-btn--ghost" href="{{ route('admin.resources', ['from' => $from->copy()->subDays($span)->toDateString(), 'days' => $span, 'kind' => $kind]) }}" aria-label="Khoảng trước"><i class="fa fa-chevron-left"></i></a>
            <input type="date" name="from" class="ad-field" value="{{ $from->toDateString() }}" aria-label="Từ ngày" onchange="this.form.submit()">
            <select name="days" class="ad-field" onchange="this.form.submit()" aria-label="Số ngày">@foreach ([7, 14, 21, 28] as $n)<option value="{{ $n }}" @selected($span === $n)>{{ $n }} ngày</option>@endforeach</select>
            <select name="kind" class="ad-field" onchange="this.form.submit()" aria-label="Loại tài nguyên"><option value="">Mọi loại</option>@foreach ($kinds as $k => $l)<option value="{{ $k }}" @selected($kind === $k)>{{ $l }}</option>@endforeach</select>
            <a class="ad-btn ad-btn--sm ad-btn--ghost" href="{{ route('admin.resources', ['from' => $from->copy()->addDays($span)->toDateString(), 'days' => $span, 'kind' => $kind]) }}" aria-label="Khoảng sau"><i class="fa fa-chevron-right"></i></a>
        </form>
    @endif
</div>

@if (!$ready)
    <div class="ad-card"><div class="ad-card__body">Cần chạy <code>php artisan migrate</code> để tạo bảng lịch tài nguyên.</div></div>
@else
    <div class="ad-kpis">
        <div class="ad-kpi"><div class="ad-kpi__ic ad-kpi__ic--teal"><i class="fa fa-calendar"></i></div><div><div class="ad-kpi__v">{{ $kpi['deps'] }}</div><div class="ad-kpi__l">Lịch khởi hành trong khoảng</div></div></div>
        <div class="ad-kpi"><div class="ad-kpi__ic ad-kpi__ic--{{ $kpi['noTransport'] ? 'red' : 'green' }}"><i class="fa fa-bus"></i></div><div><div class="ad-kpi__v">{{ $kpi['noTransport'] }}</div><div class="ad-kpi__l">Đoàn có khách, chưa có xe</div></div></div>
        <div class="ad-kpi"><div class="ad-kpi__ic ad-kpi__ic--{{ $kpi['noGuide'] ? 'sand' : 'green' }}"><i class="fa fa-user"></i></div><div><div class="ad-kpi__v">{{ $kpi['noGuide'] }}</div><div class="ad-kpi__l">Đoàn có khách, chưa có HDV</div></div></div>
        <div class="ad-kpi"><div class="ad-kpi__ic ad-kpi__ic--{{ $kpi['short'] ? 'red' : 'green' }}"><i class="fa fa-exclamation-triangle"></i></div><div><div class="ad-kpi__v">{{ $kpi['short'] }}</div><div class="ad-kpi__l">Đoàn xe thiếu chỗ</div></div></div>
    </div>

    {{-- ===== Bảng lịch: tài nguyên × ngày ===== --}}
    <section class="ad-card rs-card">
        <div class="ad-card__head"><h2>Lịch sử dụng</h2>
            <p class="rs-legend"><span class="rs-sw rs-sw--free"></span>Trống <span class="rs-sw rs-sw--part"></span>Còn chỗ <span class="rs-sw rs-sw--full"></span>Kín <span class="rs-sw rs-sw--over"></span>Vượt (dữ liệu cũ, cần xử lý) · Số trong ô: chỗ đã dùng cao nhất trong ngày / sức chứa. Rê chuột để xem đoàn.</p></div>
        @if ($resources->isEmpty())
            <div class="ad-empty"><i class="fa fa-bus"></i><b>Chưa có tài nguyên</b>Thêm xe hoặc hướng dẫn viên ở khung “Thêm tài nguyên”.</div>
        @else
            <div class="ad-tablewrap rs-gridwrap">
                <table class="rs-grid">
                    <thead><tr><th scope="col" class="rs-grid__res">Tài nguyên</th>
                        @foreach ($days as $d)<th scope="col" class="{{ $d->isWeekend() ? 'is-we' : '' }}{{ $d->isToday() ? ' is-today' : '' }}">{{ $fmtD($d) }}</th>@endforeach
                    </tr></thead>
                    <tbody>
                    @foreach ($resources as $r)
                        <tr class="{{ $r->active ? '' : 'is-off' }}">
                            <th scope="row" class="rs-grid__res"><i class="fa {{ $kindIcon[$r->kind] ?? 'fa-cube' }}" aria-hidden="true"></i> {{ $r->name }}<small>{{ $kinds[$r->kind] ?? $r->kind }} · {{ $r->capacity }} chỗ{{ $r->active ? '' : ' · ngừng dùng' }}</small></th>
                            @foreach ($days as $i => $d)
                                @php $c = $grid[$r->resourceId][$i]; @endphp
                                <td class="rs-cell rs-cell--{{ $c['level'] }}" @if ($c['n']) title="{{ $c['title'] }}" @endif>
                                    @if ($c['used'])<span>{{ $c['used'] }}/{{ $r->capacity }}</span>@endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <div class="ad-grid ad-grid--side rs-main">
        {{-- ===== Lịch khởi hành và tài nguyên đã xếp ===== --}}
        <section class="ad-card">
            <div class="ad-card__head"><h2>Đoàn trong khoảng {{ $from->format('d/m') }} – {{ $from->copy()->addDays($span - 1)->format('d/m') }}</h2></div>
            @if ($deps->isEmpty())
                <div class="ad-empty"><i class="fa fa-calendar-o"></i><b>Không có lịch khởi hành</b>Đổi khoảng ngày ở trên.</div>
            @else
                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <thead><tr><th>Đoàn</th><th>Ngày</th><th class="num">Khách</th><th>Đã xếp</th><th></th></tr></thead>
                        <tbody>
                        @foreach ($deps as $d)
                            <tr id="dep-{{ $d->availabilityId }}" class="{{ $focus === (int) $d->availabilityId ? 'rs-focus' : '' }}">
                                <td>{{ \Illuminate\Support\Str::limit($d->title, 60) }}<span class="ad-sub">Lịch #{{ $d->availabilityId }}{{ $d->status === 'closed' ? ' · ngừng bán' : '' }}</span></td>
                                <td class="nowrap">{{ $fmtD($d->departureDate) }}@if ($d->endDate !== $d->departureDate) → {{ $fmtD($d->endDate) }}@endif</td>
                                <td class="num"><b>{{ $d->pax }}</b><span class="ad-sub">/ {{ $d->totalSlots }} chỗ</span></td>
                                <td>
                                    @forelse ($d->alloc as $a)
                                        <div class="rs-chip rs-chip--{{ $a->kind }}">
                                            <i class="fa {{ $kindIcon[$a->kind] ?? 'fa-cube' }}" aria-hidden="true"></i>
                                            <span><b>{{ $a->name }}</b> · {{ $a->quantity }} chỗ<small>{{ $fmtT($a->startAt) }} – {{ $fmtT($a->endAt) }}</small></span>
                                            <form method="POST" action="{{ route('admin.resources.release', $a->allocationId) }}" class="ad-inline-form">@csrf
                                                <button class="rs-chip__x" type="submit" aria-label="Trả {{ $a->name }}" title="Trả tài nguyên" data-confirm="Trả “{{ $a->name }}” khỏi đoàn này?"><i class="fa fa-times"></i></button></form>
                                        </div>
                                    @empty
                                        <span class="ad-muted">Chưa xếp</span>
                                    @endforelse
                                    @if ($d->pax > 0 && !$d->hasTransport)<span class="ad-badge ad-badge--bad">Chưa có xe</span>@endif
                                    @if ($d->seatsShort)<span class="ad-badge ad-badge--bad">Xe thiếu {{ $d->seatsShort }} chỗ</span>@endif
                                    @if ($d->pax > 0 && !$d->hasGuide)<span class="ad-badge ad-badge--warn">Chưa có HDV</span>@endif
                                </td>
                                <td><button type="button" class="ad-btn ad-btn--sm js-pick" data-id="{{ $d->availabilityId }}"><i class="fa fa-plus"></i> Xếp</button></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <aside>
            {{-- ===== Form xếp ===== --}}
            <section class="ad-card ad-card--hl" id="rs-assign">
                <div class="ad-card__head"><h2>Xếp tài nguyên cho đoàn</h2></div>
                <form class="ad-card__body ad-form" method="POST" action="{{ route('admin.resources.allocate') }}" id="rs-form">
                    @csrf
                    <div class="ad-fld"><label for="rs-dep">Đoàn (lịch khởi hành) <span class="req">*</span></label>
                        <select id="rs-dep" name="availabilityId" required>
                            <option value="">Chọn đoàn</option>
                            @foreach ($deps as $d)
                                <option value="{{ $d->availabilityId }}" data-start="{{ $d->defStart }}" data-end="{{ $d->defEnd }}" data-pax="{{ $d->pax }}"
                                    @selected((int) old('availabilityId', $focus) === (int) $d->availabilityId)>#{{ $d->availabilityId }} · {{ \Carbon\Carbon::parse($d->departureDate)->format('d/m') }} · {{ \Illuminate\Support\Str::limit($d->title, 38) }} ({{ $d->pax }} khách)</option>
                            @endforeach
                        </select></div>
                    <div class="ad-fld"><label for="rs-res">Tài nguyên <span class="req">*</span></label>
                        <select id="rs-res" name="resourceId" required>
                            <option value="">Chọn xe / HDV</option>
                            @foreach ($kinds as $k => $l)
                                @php $list = $activeRes->where('kind', $k); @endphp
                                @if ($list->isNotEmpty())
                                    <optgroup label="{{ $l }}">@foreach ($list as $r)<option value="{{ $r->resourceId }}" data-kind="{{ $r->kind }}" data-cap="{{ $r->capacity }}" @selected((int) old('resourceId') === (int) $r->resourceId)>{{ $r->name }} ({{ $r->capacity }} chỗ)</option>@endforeach</optgroup>
                                @endif
                            @endforeach
                        </select></div>
                    <div class="ad-form ad-form--2">
                        <div class="ad-fld"><label for="rs-start">Bắt đầu <span class="req">*</span></label><input id="rs-start" type="datetime-local" name="startAt" required value="{{ old('startAt') }}"></div>
                        <div class="ad-fld"><label for="rs-end">Kết thúc <span class="req">*</span></label><input id="rs-end" type="datetime-local" name="endAt" required value="{{ old('endAt') }}"></div>
                    </div>
                    <div class="ad-fld"><label for="rs-qty">Số chỗ dùng <span class="req">*</span></label><input id="rs-qty" type="number" name="quantity" min="1" max="10000" required value="{{ old('quantity', 1) }}">
                        <span class="ad-hint" id="rs-qty-hint">Xe: số khách của đoàn. Hướng dẫn viên: 1.</span></div>
                    <div class="ad-fld"><label for="rs-note">Ghi chú</label><input id="rs-note" name="note" maxlength="255" value="{{ old('note') }}" placeholder="Điểm đón, tài xế, số điện thoại..."></div>
                    <button class="ad-btn ad-btn--primary" type="submit"><i class="fa fa-check"></i> Xếp</button>
                </form>
            </section>

            {{-- ===== Danh mục tài nguyên ===== --}}
            <section class="ad-card">
                <div class="ad-card__head"><h2>Thêm tài nguyên</h2></div>
                <form class="ad-card__body ad-form" method="POST" action="{{ route('admin.resources.store') }}">
                    @csrf
                    <div class="ad-fld"><label for="rn-name">Tên / mã <span class="req">*</span></label><input id="rn-name" name="name" maxlength="150" required placeholder="Vd: Xe 29 chỗ 29B-123.45, HDV Nguyễn Lan"></div>
                    <div class="ad-form ad-form--2">
                        <div class="ad-fld"><label for="rn-kind">Loại</label><select id="rn-kind" name="kind">@foreach ($kinds as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                        <div class="ad-fld"><label for="rn-cap">Sức chứa <span class="req">*</span></label><input id="rn-cap" type="number" name="capacity" min="1" max="10000" required placeholder="Số chỗ / 1"></div>
                    </div>
                    <button class="ad-btn" type="submit"><i class="fa fa-plus"></i> Thêm</button>
                </form>
                @if ($resources->isNotEmpty())
                    <ul class="rs-list">
                        @foreach ($resources as $r)
                            <li class="{{ $r->active ? '' : 'is-off' }}"><i class="fa {{ $kindIcon[$r->kind] ?? 'fa-cube' }}" aria-hidden="true"></i><span>{{ $r->name }}<small>{{ $r->capacity }} chỗ</small></span>
                                <form method="POST" action="{{ route('admin.resources.toggle', $r->resourceId) }}" class="ad-inline-form">@csrf<button class="ad-btn ad-btn--sm ad-btn--ghost" type="submit">{{ $r->active ? 'Ngừng dùng' : 'Dùng lại' }}</button></form></li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </aside>
    </div>
@endif
@endsection

@push('styles')
<style>
    .rs-card { margin-bottom: 20px; }
    .rs-legend { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin: 4px 0 0; color: var(--ad-mute); font-size: 12.5px; }
    .rs-sw { display: inline-block; width: 12px; height: 12px; border-radius: 3px; margin-left: 6px; border: 1px solid var(--ad-line); }
    .rs-sw--free, .rs-cell--free { background: #fff; }
    .rs-sw--part, .rs-cell--part { background: var(--ad-primary-l); }
    .rs-sw--full, .rs-cell--full { background: var(--ad-sand-l); }
    .rs-sw--over, .rs-cell--over { background: var(--ad-bad-l); }
    .rs-gridwrap { overflow-x: auto; }
    .rs-grid { border-collapse: separate; border-spacing: 0; width: 100%; font-size: 12.5px; }
    .rs-grid th, .rs-grid td { border-bottom: 1px solid var(--ad-line-2); border-right: 1px solid var(--ad-line-2); padding: 6px 4px; text-align: center; white-space: nowrap; }
    .rs-grid thead th { font-weight: 600; color: var(--ad-mute); background: #fafbfc; position: sticky; top: 0; }
    .rs-grid thead th.is-we { color: var(--ad-warn); }
    .rs-grid thead th.is-today { color: var(--ad-primary-d); box-shadow: inset 0 -2px 0 var(--ad-primary); }
    .rs-grid__res { position: sticky; left: 0; z-index: 1; background: #fff; text-align: left !important; min-width: 190px; font-weight: 600; color: var(--ad-ink); }
    .rs-grid__res small { display: block; font-weight: 400; color: var(--ad-mute); }
    .rs-grid tr.is-off .rs-grid__res { color: var(--ad-faint); }
    .rs-cell { min-width: 52px; font-variant-numeric: tabular-nums; }
    .rs-cell--full span, .rs-cell--over span { font-weight: 700; color: var(--ad-ink); }
    .rs-cell--over span { color: var(--ad-bad); }
    .rs-main { align-items: start; }
    .rs-focus { box-shadow: inset 3px 0 0 var(--ad-primary); background: var(--ad-primary-xl); }
    .rs-chip { display: flex; align-items: center; gap: 8px; padding: 5px 6px 5px 9px; margin: 0 0 5px; border-radius: 9px; background: var(--ad-primary-xl); border: 1px solid var(--ad-primary-l); max-width: 340px; }
    .rs-chip--guide { background: var(--ad-violet-l); border-color: #ddd6fe; }
    .rs-chip span { flex: 1; min-width: 0; line-height: 1.3; }
    .rs-chip small { display: block; color: var(--ad-mute); }
    .rs-chip__x { border: 0; background: transparent; color: var(--ad-mute); width: 26px; height: 26px; border-radius: 6px; cursor: pointer; }
    .rs-chip__x:hover, .rs-chip__x:focus-visible { background: var(--ad-bad-l); color: var(--ad-bad); }
    .rs-list { list-style: none; margin: 0; padding: 0 18px 14px; }
    .rs-list li { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-top: 1px solid var(--ad-line-2); }
    .rs-list li span { flex: 1; }
    .rs-list li small { display: block; color: var(--ad-mute); }
    .rs-list li.is-off span { color: var(--ad-faint); text-decoration: line-through; }
    .rs-pulse { animation: rsPulse 1.2s ease 1; }
    @keyframes rsPulse { 0% { box-shadow: 0 0 0 0 rgba(22, 143, 153, .45); } 100% { box-shadow: 0 0 0 14px rgba(22, 143, 153, 0); } }
    @media (prefers-reduced-motion: reduce) { .rs-pulse { animation: none; } }
</style>
@endpush

@push('scripts')
<script>
(function () {
    var dep = document.getElementById('rs-dep'), res = document.getElementById('rs-res');
    if (!dep || !res) return;
    var start = document.getElementById('rs-start'), end = document.getElementById('rs-end'), qty = document.getElementById('rs-qty'), hint = document.getElementById('rs-qty-hint');
    function opt(sel) { return sel.options[sel.selectedIndex] || null; }
    // gợi ý giá trị mặc định; không ghi đè thứ người dùng đã sửa tay
    function fill(force) {
        var d = opt(dep), r = opt(res);
        if (d && d.value) {
            if (force || !start.value) start.value = d.dataset.start;
            if (force || !end.value) end.value = d.dataset.end;
        }
        if (r && r.value) {
            var pax = d && d.dataset.pax ? +d.dataset.pax : 0, cap = +r.dataset.cap;
            if (r.dataset.kind === 'guide') { qty.value = 1; hint.textContent = 'Hướng dẫn viên: một đoàn dùng 1 suất.'; }
            else { qty.value = Math.max(1, Math.min(pax || 1, cap)); hint.textContent = 'Đoàn có ' + pax + ' khách, xe có ' + cap + ' chỗ.' + (pax > cap ? ' Xe không đủ chỗ cho cả đoàn: xếp thêm xe thứ hai cho số khách còn lại.' : ''); }
        }
    }
    dep.addEventListener('change', function () { fill(true); });
    res.addEventListener('change', function () { fill(false); });
    if (dep.value && !start.value) fill(true);
    document.querySelectorAll('.js-pick').forEach(function (b) {
        b.addEventListener('click', function () {
            dep.value = b.dataset.id;
            fill(true);
            var box = document.getElementById('rs-assign');
            box.scrollIntoView({ behavior: 'smooth', block: 'start' });
            box.classList.remove('rs-pulse'); void box.offsetWidth; box.classList.add('rs-pulse');
            res.focus({ preventScroll: true });
        });
    });
})();
</script>
@endpush
