@extends('admin.layout')
@php
    $wd = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];
    $fmt = fn($d) => $wd[\Carbon\Carbon::parse($d)->dayOfWeek] . ', ' . \Carbon\Carbon::parse($d)->format('d/m/Y');
    $avg = ($up && $up->total > 0) ? round(($up->booked + $up->held) / $up->total * 100) : null;
@endphp
@section('content')
    <div class="ad-head">
        <div>
            @if ($tour)<a class="ad-back" href="{{ route('admin.departures') }}"><i class="fa fa-arrow-left"></i> Mọi ngày khởi hành</a>@endif
            <h1>{{ $tour ? $tour->title : 'Lịch khởi hành' }}</h1>
            <a class="ad-btn ad-btn--sm" href="{{ route('admin.departures.grouping') }}"><i class="fa fa-users"></i> Quản lý ghép đoàn</a>
            <p>Mỗi tour có thể mở bán nhiều ngày khởi hành, mỗi ngày có số chỗ riêng. Chỗ <b>đã bán</b> là của đơn đã xác nhận / hoàn tất, chỗ <b>đang giữ</b> là của đơn chờ xác nhận. Ngày đã có đơn thì chỉ được ngừng bán, không xóa.</p>
        </div>
        <form class="ad-actions" method="GET" action="{{ route('admin.departures') }}">
            <select name="tour" class="ad-field" onchange="this.form.submit()" aria-label="Lọc theo tour" style="max-width:300px">
                <option value="">Tất cả tour</option>
                @foreach ($tours as $t)<option value="{{ $t->tourId }}" @selected($tour && $tour->tourId === $t->tourId)>{{ \Illuminate\Support\Str::limit($t->title, 48) }}{{ $t->availability ? '' : ' (đang ẩn)' }}</option>@endforeach
            </select>
            <select name="when" class="ad-field" onchange="this.form.submit()" aria-label="Thời gian">
                <option value="upcoming" @selected($when === 'upcoming')>Sắp khởi hành</option>
                <option value="past" @selected($when === 'past')>Đã khởi hành</option>
                <option value="all" @selected($when === 'all')>Tất cả</option>
            </select>
        </form>
    </div>

    @unless ($tour)
        <div class="ad-kpis">
            <div class="ad-kpi"><div class="ad-kpi__ic ad-kpi__ic--violet"><i class="fa fa-pie-chart"></i></div>
                <div><div class="ad-kpi__l">Lấp đầy trung bình (sắp tới)</div><div class="ad-kpi__v">{{ $avg !== null ? $avg . '%' : '—' }}</div>
                    <div class="ad-kpi__h">{{ (int) ($up->booked ?? 0) }} đã bán · {{ (int) ($up->held ?? 0) }} đang giữ / {{ (int) ($up->total ?? 0) }} chỗ</div></div></div>
            <div class="ad-kpi"><div class="ad-kpi__ic ad-kpi__ic--teal"><i class="fa fa-calendar"></i></div>
                <div><div class="ad-kpi__l">Ngày đang mở bán</div><div class="ad-kpi__v">{{ (int) ($up->n ?? 0) }}</div><div class="ad-kpi__h">{{ $soon }} ngày khởi hành trong 14 ngày tới</div></div></div>
            <div class="ad-kpi"><div class="ad-kpi__ic ad-kpi__ic--red"><i class="fa fa-users"></i></div>
                <div><div class="ad-kpi__l">Ngày đã kín chỗ</div><div class="ad-kpi__v">{{ $full }}</div><div class="ad-kpi__h">Cân nhắc mở thêm ngày</div></div></div>
        </div>
    @endunless

    <div class="ad-grid {{ $tour ? 'ad-grid--side' : '' }}">
        <section class="ad-card">
            <div class="ad-card__head">
                <h2>{{ ['upcoming' => 'Sắp khởi hành', 'past' => 'Đã khởi hành', 'all' => 'Mọi ngày'][$when] }} <small>· {{ $rows->total() }} ngày</small></h2>
                @if ($tour)<a class="ad-btn ad-btn--sm" href="{{ route('tour-detail', ['id' => $tour->tourId]) }}" target="_blank" rel="noopener"><i class="fa fa-external-link"></i> Xem trang tour</a>@endif
            </div>
            @if ($rows->count())
                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <thead><tr><th>Ngày đi → về</th>@unless ($tour)<th>Tour</th>@endunless<th style="min-width:180px">Chỗ</th><th class="num">Còn</th><th>Trạng thái</th><th class="num">Thao tác</th></tr></thead>
                        <tbody>
                        @foreach ($rows as $r)
                            <tr class="{{ $r->isPast || $r->status === 'closed' ? 'is-muted' : '' }}">
                                <td class="nowrap"><b style="color:var(--ad-ink)">{{ $fmt($r->departureDate) }}</b><span class="ad-sub">về {{ \Carbon\Carbon::parse($r->endDate)->format('d/m/Y') }}{{ $r->note ? ' · ' . $r->note : '' }}</span></td>
                                @unless ($tour)<td><a href="{{ route('admin.departures', ['tour' => $r->tourId]) }}">{{ \Illuminate\Support\Str::limit($r->title, 42) }}</a>{!! $r->availability ? '' : ' <span class="ad-tag">ẩn</span>' !!}</td>@endunless
                                <td>
                                    <div class="ad-meter" style="margin:0">
                                        <div class="ad-meter__top"><span class="ad-muted">{{ $r->bookedSlots }} bán · {{ $r->heldSlots }} giữ</span><b>{{ $r->bookedSlots + $r->heldSlots }}/{{ $r->totalSlots }}</b></div>
                                        <div class="ad-split"><i style="width:{{ $r->totalSlots ? $r->bookedSlots / $r->totalSlots * 100 : 0 }}%;background:var(--ad-primary)"></i><i style="width:{{ $r->totalSlots ? $r->heldSlots / $r->totalSlots * 100 : 0 }}%;background:var(--ad-sand)"></i></div>
                                    </div>
                                </td>
                                <td class="num"><b class="{{ $r->remaining === 0 ? '' : '' }}" style="color:{{ $r->remaining === 0 ? 'var(--ad-bad)' : 'var(--ad-ink)' }}">{{ $r->remaining }}</b></td>
                                <td>
                                    @if ($r->isPast)<span class="ad-badge ad-badge--mute">Đã khởi hành</span>
                                    @elseif ($r->status === 'closed')<span class="ad-badge ad-badge--warn">Ngừng bán</span>
                                    @elseif ($r->remaining === 0)<span class="ad-badge ad-badge--bad">Kín chỗ</span>
                                    @else<span class="ad-badge ad-badge--ok">Đang bán</span>@endif
                                    @if ($r->bookings)<span class="ad-sub">{{ $r->bookings }} đơn</span>@endif
                                </td>
                                <td>
                                    <div class="ad-rowact">
                                        <button type="button" class="ad-btn ad-btn--sm" data-toggle="modal" data-target="#dep-edit"
                                            data-id="{{ $r->availabilityId }}" data-date="{{ $r->departureDate }}" data-slots="{{ $r->totalSlots }}" data-min="{{ max(1, $r->bookedSlots + $r->heldSlots) }}"
                                            data-note="{{ $r->note }}" data-lock="{{ $r->bookings ? 1 : 0 }}" data-title="{{ $fmt($r->departureDate) }}">Sửa</button>
                                        @if ($r->bookings && $r->endDate >= now()->toDateString())
                                            <form method="POST" action="{{ route('admin.guide.create', $r->availabilityId) }}" class="ad-inline-form" data-guide>@csrf<input type="hidden" name="guideName">
                                                <button class="ad-btn ad-btn--sm" title="Tạo đường dẫn cho hướng dẫn viên: danh sách khách, điểm danh, lịch theo giờ, báo sự cố"><i class="fa fa-link"></i> Link HDV</button></form>
                                        @endif
                                        @unless ($r->isPast)
                                            <a class="ad-btn ad-btn--sm" href="{{ route('admin.resources', ['from' => $r->departureDate, 'availabilityId' => $r->availabilityId]) }}#dep-{{ $r->availabilityId }}" title="Xếp xe và hướng dẫn viên cho đoàn này"><i class="fa fa-bus"></i> Xe · HDV</a>
                                            <form method="POST" action="{{ route('admin.departures.toggle', $r->availabilityId) }}" class="ad-inline-form">@csrf
                                                <button class="ad-btn ad-btn--sm" data-confirm="{{ $r->status === 'open' ? 'Ngừng nhận đặt cho ngày ' . $fmt($r->departureDate) . '? Đơn đã đặt vẫn giữ nguyên.' : 'Mở bán lại ngày ' . $fmt($r->departureDate) . '?' }}">{{ $r->status === 'open' ? 'Ngừng bán' : 'Mở bán' }}</button></form>
                                        @endunless
                                        @if (!$r->bookings)
                                            <form method="POST" action="{{ route('admin.departures.destroy', $r->availabilityId) }}" class="ad-inline-form">@csrf
                                                <button class="ad-btn ad-btn--sm ad-btn--ghost ad-btn--icon" title="Xóa ngày này" aria-label="Xóa ngày {{ $fmt($r->departureDate) }}" data-confirm="Xóa ngày khởi hành {{ $fmt($r->departureDate) }}?"><i class="fa fa-trash-o" style="color:var(--ad-bad)"></i></button></form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($rows->hasPages())<div class="ad-pager">{{ $rows->links('admin.partials.pager') }}</div>@endif
            @else
                <div class="ad-empty"><i class="fa fa-calendar-o"></i><b>Chưa có ngày khởi hành</b>{{ $tour ? 'Thêm ngày đầu tiên ở khung bên cạnh.' : 'Chọn một tour để thêm ngày khởi hành.' }}</div>
            @endif
        </section>

        @if ($tour)
            <aside>
                <section class="ad-card ad-card--hl">
                    <div class="ad-card__head"><h2>Thêm ngày khởi hành</h2></div>
                    <form class="ad-card__body ad-form" method="POST" action="{{ route('admin.departures.store') }}">
                        @csrf
                        <input type="hidden" name="tourId" value="{{ $tour->tourId }}">
                        <div class="ad-fld"><label for="dep-date">Ngày khởi hành <span class="req">*</span></label>
                            <input id="dep-date" type="date" name="departureDate" required min="{{ now()->addDay()->toDateString() }}" value="{{ old('departureDate') }}">
                            <span class="ad-hint">Tour {{ \App\Services\DepartureService::durationDays($tour) }} ngày, ngày về tự tính.</span></div>
                        <div class="ad-fld"><label for="dep-slots">Số chỗ <span class="req">*</span></label>
                            <input id="dep-slots" type="number" name="totalSlots" min="1" max="500" required value="{{ old('totalSlots', 25) }}"></div>
                        <div class="ad-form ad-form--2">
                            <div class="ad-fld"><label for="dep-repeat">Lặp thêm</label>
                                <select id="dep-repeat" name="repeat">@for ($i = 0; $i <= 8; $i++)<option value="{{ $i }}">{{ $i ? $i . ' lần' : 'Không lặp' }}</option>@endfor</select></div>
                            <div class="ad-fld"><label for="dep-every">Mỗi</label>
                                <select id="dep-every" name="every"><option value="7">7 ngày</option><option value="14">14 ngày</option><option value="30">30 ngày</option></select></div>
                        </div>
                        <div class="ad-fld"><label for="dep-note">Ghi chú nội bộ</label><input id="dep-note" name="note" maxlength="255" placeholder="Vd: Dịp lễ 2/9, hướng dẫn viên A"></div>
                        <button class="ad-btn ad-btn--primary" type="submit"><i class="fa fa-plus"></i> Thêm</button>
                    </form>
                </section>
                <section class="ad-card">
                    <div class="ad-card__body">
                        <dl class="ad-kv ad-kv--tight">
                            <dt>Trạng thái tour</dt><dd>{!! $tour->availability ? '<span class="ad-badge ad-badge--ok">Đang hiện</span>' : '<span class="ad-badge ad-badge--mute">Đang ẩn</span>' !!}</dd>
                            <dt>Khách đang thấy</dt><dd>{{ $tour->startDate ? $fmt($tour->startDate) : '—' }}</dd>
                        </dl>
                        <p class="ad-note" style="margin:10px 0 0">Trang danh sách tour hiện ngày khởi hành gần nhất còn chỗ. Ở trang chi tiết, khách chọn được mọi ngày đang bán.</p>
                        <a class="ad-btn ad-btn--sm" style="margin-top:12px" href="{{ route('admin.tours') }}"><i class="fa fa-suitcase"></i> Danh sách tour</a>
                    </div>
                </section>
            </aside>
        @endif
    </div>

    <div class="modal fade" id="dep-edit" tabindex="-1" role="dialog" aria-labelledby="dep-edit-t" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <form class="modal-content" method="POST" id="dep-edit-form" data-base="{{ url('admin/departures') }}">
                @csrf
                <div class="modal-header"><h5 class="modal-title" id="dep-edit-t">Sửa ngày khởi hành</h5><button type="button" class="close" data-dismiss="modal" aria-label="Đóng">&times;</button></div>
                <div class="modal-body ad-form">
                    <div class="ad-fld"><label for="de-date">Ngày khởi hành</label><input id="de-date" type="date" name="departureDate">
                        <span class="ad-hint" id="de-lock"></span></div>
                    <div class="ad-fld"><label for="de-slots">Tổng số chỗ</label><input id="de-slots" type="number" name="totalSlots" min="1" max="500" required>
                        <span class="ad-hint" id="de-min"></span></div>
                    <div class="ad-fld"><label for="de-note">Ghi chú nội bộ</label><input id="de-note" name="note" maxlength="255"></div>
                </div>
                <div class="modal-footer"><button type="button" class="ad-btn" data-dismiss="modal">Thôi</button><button class="ad-btn ad-btn--primary" type="submit">Lưu</button></div>
            </form>
        </div>
    </div>
@endsection
@push('scripts')
<script>
    $('#dep-edit').on('show.bs.modal', function (e) {
        var d = e.relatedTarget.dataset, f = document.getElementById('dep-edit-form');
        f.action = f.dataset.base + '/' + d.id;
        document.getElementById('dep-edit-t').textContent = 'Sửa ngày ' + d.title;
        var date = document.getElementById('de-date'), slots = document.getElementById('de-slots');
        date.value = d.date; date.readOnly = d.lock === '1';
        document.getElementById('de-lock').textContent = d.lock === '1' ? 'Đã có đơn đặt nên không đổi ngày được.' : 'Chưa có đơn, có thể dời ngày.';
        slots.value = d.slots; slots.min = d.min;
        document.getElementById('de-min').textContent = 'Không nhỏ hơn ' + d.min + ' (số chỗ đã bán + đang giữ).';
        document.getElementById('de-note').value = d.note || '';
    });
    document.querySelectorAll('form[data-guide]').forEach(function (f) {
        f.addEventListener('submit', function (e) {
            var n = prompt('Tên hướng dẫn viên (để trống cũng được):', '');
            if (n === null) { e.preventDefault(); return; }
            f.querySelector('[name=guideName]').value = n;
        });
    });
</script>
@endpush
