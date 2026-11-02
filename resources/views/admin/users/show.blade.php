@extends('admin.layout')
@php
    use App\Http\Controllers\admin\BookingManagementController as BM;
    $money = fn($v) => number_format((float) $v, 0, ',', '.') . ' đ';
    $state = $u->status === 'b' ? ['bad', 'Bị chặn'] : ($u->status === 'd' ? ['mute', 'Đã xóa'] : ($u->isActive === 'y' ? ['ok', 'Đang hoạt động'] : ['warn', 'Chưa kích hoạt email']));
    $markLabel = ['saved' => 'Đã lưu', 'visited' => 'Đã đến', 'tried' => 'Món đã thử', 'attended' => 'Lễ hội đã dự'];
    $tStatus = \App\Services\SupportTicketService::STATUS;
@endphp
@section('content')
    <div class="ad-head">
        <div>
            <a class="ad-back" href="{{ route('admin.users') }}"><i class="fa fa-arrow-left"></i> Người dùng</a>
            <div class="ad-person" style="margin-top:6px"><img src="{{ \App\Support\Avatar::url($u->avatar) }}" alt="" style="width:56px;height:56px">
                <span><h1 style="margin:0">{{ $u->fullName }}</h1><small>{{ '@' . $u->username }} · đăng ký {{ date('d/m/Y', strtotime($u->createdDate)) }}</small></span></div>
        </div>
        <div class="ad-actions" id="u-actions" data-id="{{ $u->userId }}" data-url-status="{{ route('admin.status-user') }}" data-url-active="{{ route('admin.active-user') }}">
            <span class="ad-badge ad-badge--{{ $state[0] }}">{{ $state[1] }}</span>
            @if ($u->isActive !== 'y' && !$u->status)<button type="button" class="ad-btn js-u" data-act="active">Kích hoạt</button>@endif
            @if ($u->status === null)
                <button type="button" class="ad-btn ad-btn--danger js-u" data-act="b">Chặn tài khoản</button>
            @else
                <button type="button" class="ad-btn ad-btn--primary js-u" data-act="active-status">Mở khóa / khôi phục</button>
            @endif
            <a class="ad-btn ad-btn--primary" href="{{ route('admin.booking.create', ['user' => $u->userId]) }}"><i class="fa fa-plus"></i> Đặt tour hộ</a>
        </div>
    </div>

    <div class="ad-kpis">
        <div class="ad-kpi"><div class="ad-kpi__ic ad-kpi__ic--blue"><i class="fa fa-ticket"></i></div><div><div class="ad-kpi__l">Đơn đặt tour</div><div class="ad-kpi__v">{{ count($bookings) }}</div><div class="ad-kpi__h">{{ $bookings->whereIn('bookingStatus', ['y', 'f'])->count() }} đã xác nhận / hoàn tất</div></div></div>
        <div class="ad-kpi"><div class="ad-kpi__ic ad-kpi__ic--teal"><i class="fa fa-money"></i></div><div><div class="ad-kpi__l">Đã chi (thuần)</div><div class="ad-kpi__v">{{ number_format($spent / 1e6, 1, ',', '.') }} <small>tr đ</small></div><div class="ad-kpi__h">Sau khi trừ hoàn tiền</div></div></div>
        <div class="ad-kpi"><div class="ad-kpi__ic ad-kpi__ic--violet"><i class="fa fa-map-o"></i></div><div><div class="ad-kpi__l">Chuyến đi Planner</div><div class="ad-kpi__v">{{ count($trips) }}</div><div class="ad-kpi__h">{{ $aiRuns }} lần dùng La bàn AI · {{ $asks }} câu hỏi đáp</div></div></div>
        <div class="ad-kpi"><div class="ad-kpi__ic ad-kpi__ic--sand"><i class="fa fa-star"></i></div><div><div class="ad-kpi__l">Đánh giá / tư vấn</div><div class="ad-kpi__v">{{ count($reviews) }} / {{ count($tickets) }}</div><div class="ad-kpi__h">{{ collect($marks)->sum() }} lượt đánh dấu Khám phá</div></div></div>
    </div>

    <div class="ad-grid ad-grid--21">
        <div>
            <section class="ad-card">
                <div class="ad-card__head"><h2>Đơn đặt tour</h2></div>
                @if (count($bookings))
                    <div class="ad-tablewrap"><table class="ad-table">
                        <thead><tr><th>Mã đơn</th><th>Tour · ngày đi</th><th class="num">Tổng</th><th>Trạng thái</th></tr></thead>
                        <tbody>
                        @foreach ($bookings as $b)
                            <tr data-href="{{ route('admin.booking-detail', ['id' => $b->bookingId]) }}" class="{{ $b->bookingStatus === 'c' ? 'is-muted' : '' }}">
                                <td class="nowrap"><a href="{{ route('admin.booking-detail', ['id' => $b->bookingId]) }}">{{ $b->bookingCode }}</a><span class="ad-sub">{{ date('d/m/Y', strtotime($b->bookingDate)) }}</span></td>
                                <td>{{ \Illuminate\Support\Str::limit($b->title, 40) }}<span class="ad-sub">{{ date('d/m/Y', strtotime($b->departureDate ?: $b->startDate)) }} · {{ $b->numAdults + $b->numChildren }} khách</span></td>
                                <td class="num nowrap">{{ $money($b->totalPrice) }}</td>
                                <td class="nowrap"><span class="ad-badge ad-badge--{{ BM::STATUS[$b->bookingStatus][0] ?? 'mute' }}">{{ BM::STATUS[$b->bookingStatus][1] ?? $b->bookingStatus }}</span><br><span class="ad-badge ad-badge--{{ BM::PAY[$b->paymentStatus][0] ?? 'mute' }}">{{ BM::PAY[$b->paymentStatus][1] ?? $b->paymentStatus }}</span><span class="ad-sub">{{ BM::METHOD[$b->paymentMethod] ?? $b->paymentMethod }}</span></td>
                            </tr>
                        @endforeach
                        </tbody></table></div>
                @else<div class="ad-empty"><i class="fa fa-ticket"></i>Khách chưa đặt tour nào.</div>@endif
            </section>

            <section class="ad-card">
                <div class="ad-card__head"><h2>Chuyến đi (Trip Planner)</h2></div>
                @if (count($trips))
                    <ul class="ad-todo">
                        @foreach ($trips as $t)
                            <li><a href="{{ route('admin.trips.show', $t->tripId) }}"><span class="ad-todo__t">{{ $t->title }}
                                <small>{{ $t->destName ?? 'Nhiều điểm' }}{{ $t->startDate ? ' · ' . date('d/m/Y', strtotime($t->startDate)) : '' }}{{ $t->days ? ' · ' . $t->days . ' ngày' : '' }}
                                    · {{ (int) $t->userId === (int) $u->userId ? 'chủ chuyến' : 'được mời (' . ($memberOf[$t->tripId] ?? '') . ')' }}{{ $t->shareToken ? ' · đang chia sẻ link' : '' }}</small></span><i class="fa fa-angle-right"></i></a></li>
                        @endforeach
                    </ul>
                @else<div class="ad-empty"><i class="fa fa-map-o"></i>Chưa lập chuyến đi nào.</div>@endif
            </section>

            <section class="ad-card">
                <div class="ad-card__head"><h2>Đánh giá</h2></div>
                @forelse ($reviews as $r)
                    <div class="ad-msg {{ $r->isHidden ? 'is-muted' : '' }}"><div class="ad-msg__top"><span class="ad-stars">{!! str_repeat('★', $r->rating) . '<span class="off">' . str_repeat('★', 5 - $r->rating) . '</span>' !!}</span>
                        <b>{{ $r->title }}</b><span class="ad-muted">{{ date('d/m/Y', strtotime($r->timestamp)) }}</span>@if ($r->isHidden)<span class="ad-badge ad-badge--mute">đang ẩn</span>@endif
                        <a class="ad-btn ad-btn--sm" style="margin-left:auto" href="{{ route('admin.reviews.show', $r->reviewId) }}">Mở</a></div>
                        @if ($r->comment)<div class="ad-note" style="margin-top:6px;color:var(--ad-text)">{{ \Illuminate\Support\Str::limit($r->comment, 200) }}</div>@endif</div>
                @empty<div class="ad-empty" style="padding:24px"><i class="fa fa-star-o"></i>Chưa có đánh giá.</div>@endforelse
            </section>
        </div>

        <aside>
            <section class="ad-card">
                <div class="ad-card__head"><h2>Thông tin</h2></div>
                <div class="ad-card__body"><dl class="ad-kv ad-kv--tight">
                    <dt>Email</dt><dd>{{ $u->email }}</dd>
                    <dt>Điện thoại</dt><dd>{{ $u->phoneNumber ?: '—' }}</dd>
                    <dt>Địa chỉ</dt><dd>{{ $u->address ?: '—' }}</dd>
                    <dt>Cập nhật</dt><dd>{{ $u->updatedDate ? date('d/m/Y H:i', strtotime($u->updatedDate)) : '—' }}</dd>
                    <dt>Mã giảm giá</dt><dd>{{ $coupons->count() ? $coupons->pluck('code')->implode(', ') : 'Chưa dùng' }}</dd>
                </dl></div>
            </section>
            <section class="ad-card">
                <div class="ad-card__head"><h2>Yêu cầu tư vấn</h2></div>
                @if (count($tickets))
                    <ul class="ad-todo">@foreach ($tickets as $t)<li><a href="{{ route('admin.support.show', $t->ticketId) }}"><span class="ad-todo__t">{{ $t->subject }}<small>{{ $t->code }} · {{ $tStatus[$t->status][1] ?? $t->status }}</small></span><i class="fa fa-angle-right"></i></a></li>@endforeach</ul>
                @else<div class="ad-card__body ad-note">Chưa có.</div>@endif
            </section>
            <section class="ad-card">
                <div class="ad-card__head"><h2>Tin liên hệ</h2></div>
                @if (count($contacts))
                    <ul class="ad-todo">@foreach ($contacts as $c)<li><a href="{{ route('admin.contact') }}"><span class="ad-todo__t">{{ \Illuminate\Support\Str::limit($c->message, 60) }}<small>{{ date('d/m/Y', strtotime($c->createdAt)) }} · {{ $c->status }}</small></span><i class="fa fa-angle-right"></i></a></li>@endforeach</ul>
                @else<div class="ad-card__body ad-note">Chưa có.</div>@endif
            </section>
            <section class="ad-card">
                <div class="ad-card__head"><h2>Khám phá & nhật ký</h2></div>
                <div class="ad-card__body">
                    <dl class="ad-kv ad-kv--tight">@foreach ($markLabel as $k => $l)<dt>{{ $l }}</dt><dd>{{ (int) ($marks[$k] ?? 0) }}</dd>@endforeach</dl>
                    @if (count($audit))
                        <ul class="ad-timeline" style="margin-top:14px">@foreach ($audit as $a)<li><div class="ad-timeline__t">{{ \App\Support\AuditLog::label($a->action) }}</div><div class="ad-timeline__m">{{ $a->actorName }} · {{ date('H:i d/m/Y', strtotime($a->createdAt)) }}</div></li>@endforeach</ul>
                    @endif
                </div>
            </section>
        </aside>
    </div>
@endsection
@push('scripts')
<script>
    document.addEventListener('click', function (e) {
        var b = e.target.closest('.js-u'); if (!b) return;
        var box = document.getElementById('u-actions'), act = b.dataset.act;
        var url = act === 'active' ? box.dataset.urlActive : box.dataset.urlStatus;
        var data = { userId: box.dataset.id };
        if (act === 'b') data.status = 'b';
        if (act === 'active-status') data.status = 'active';
        var text = { active: 'Kích hoạt tài khoản này (bỏ qua xác thực email)?', b: 'Chặn tài khoản? Khách bị đăng xuất ngay và không đặt tour được.', 'active-status': 'Mở khóa / khôi phục tài khoản này?' }[act];
        Swal.fire({ title: 'Xác nhận', text: text, icon: 'warning', showCancelButton: true, confirmButtonText: 'Đồng ý', cancelButtonText: 'Thôi', confirmButtonColor: act === 'b' ? '#dc2626' : '#0d9488', reverseButtons: true })
            .then(function (r) {
                if (!r.isConfirmed) return;
                var fd = new FormData(); Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
                fd.append('_token', document.querySelector('meta[name="csrf-token"]').content);
                fetch(url, { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (x) { return x.json(); }).then(function (x) { toastr[x.success ? 'success' : 'error'](x.message); if (x.success) setTimeout(function () { location.reload(); }, 600); });
            });
    });
</script>
@endpush
