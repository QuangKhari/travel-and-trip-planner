@extends('admin.layout')
@php $money = fn($v) => number_format((float) $v, 0, ',', '.'); $canPay = in_array(session('adminRole'), ['admin', 'manager'], true); @endphp
@section('content')
    <div class="ad-head">
        <div>
            <a class="ad-back" href="{{ route('admin.booking') }}"><i class="fa fa-arrow-left"></i> Đơn đặt tour</a>
            <h1>Đặt tour hộ khách</h1>
            <p>Dùng khi khách đến văn phòng hoặc gọi điện. Đơn đi qua đúng luật như khách tự đặt: giữ chỗ theo ngày khởi hành, giá do máy chủ tính, mã giảm giá kiểm đủ điều kiện. Khách có tài khoản sẽ thấy đơn trong “Tour đã đặt”.</p>
        </div>
    </div>
    <form method="POST" action="{{ route('admin.booking.store') }}" class="ad-grid ad-grid--side" id="bc-form">
        @csrf
        <input type="hidden" name="requestToken" value="{{ old('requestToken', (string) \Illuminate\Support\Str::uuid()) }}">
        <div>
            <section class="ad-card">
                <div class="ad-card__head"><h2>1. Khách hàng</h2></div>
                <div class="ad-card__body ad-form ad-form--2">
                    <div class="ad-fld span-all"><label for="bc-user">Tài khoản khách <span class="req">*</span></label>
                        <select id="bc-user" name="userId" required>
                            <option value="">— Chọn tài khoản —</option>
                            @foreach ($users as $u)
                                <option value="{{ $u->userId }}" data-name="{{ $u->fullName }}" data-email="{{ $u->email }}" data-tel="{{ preg_replace('/\D+/', '', (string) $u->phoneNumber) }}" data-address="{{ $u->address }}"
                                    @selected((int) old('userId', $user->userId ?? 0) === (int) $u->userId)>{{ $u->fullName }} · {{ $u->email }}{{ $u->phoneNumber ? ' · ' . $u->phoneNumber : '' }}</option>
                            @endforeach
                        </select>
                        <span class="ad-hint">Khách chưa có tài khoản: nhờ khách đăng ký trên website (1 phút) để nhận email và tự theo dõi đơn.</span></div>
                    <div class="ad-fld"><label for="bc-name">Họ tên người đi <span class="req">*</span></label><input id="bc-name" name="fullName" required maxlength="255" value="{{ old('fullName', $user->fullName ?? '') }}"></div>
                    <div class="ad-fld"><label for="bc-tel">Điện thoại <span class="req">*</span></label><input id="bc-tel" name="tel" required inputmode="numeric" value="{{ old('tel', preg_replace('/\D+/', '', (string) ($user->phoneNumber ?? ''))) }}"></div>
                    <div class="ad-fld"><label for="bc-email">Email nhận hóa đơn <span class="req">*</span></label><input id="bc-email" type="email" name="email" required maxlength="50" value="{{ old('email', $user->email ?? '') }}"></div>
                    <div class="ad-fld"><label for="bc-address">Địa chỉ <span class="req">*</span></label><input id="bc-address" name="address" required maxlength="255" value="{{ old('address', $user->address ?? '') }}"></div>
                </div>
            </section>
            <section class="ad-card">
                <div class="ad-card__head"><h2>2. Tour và ngày khởi hành</h2></div>
                <div class="ad-card__body ad-form ad-form--2">
                    <div class="ad-fld span-all"><label for="bc-tour">Tour <span class="req">*</span></label>
                        <select id="bc-tour" name="tourId" required data-url="{{ route('admin.booking.departures') }}">
                            <option value="">— Chọn tour đang bán —</option>
                            @foreach ($tours as $t)<option value="{{ $t->tourId }}" data-a="{{ (int) $t->priceAdult }}" data-c="{{ (int) $t->priceChild }}" @selected((int) old('tourId') === (int) $t->tourId)>{{ $t->title }} · {{ $money($t->priceAdult) }} đ</option>@endforeach
                        </select></div>
                    <div class="ad-fld span-all"><label for="bc-dep">Ngày khởi hành <span class="req">*</span></label>
                        <select id="bc-dep" name="availabilityId" required data-old="{{ old('availabilityId') }}"><option value="">Chọn tour trước</option></select></div>
                    <div class="ad-fld"><label for="bc-a">Người lớn</label><input id="bc-a" type="number" name="numAdults" min="1" max="50" value="{{ old('numAdults', 1) }}" required></div>
                    <div class="ad-fld"><label for="bc-c">Trẻ em (dưới 12 tuổi)</label><input id="bc-c" type="number" name="numChildren" min="0" max="50" value="{{ old('numChildren', 0) }}" required></div>
                    <div class="ad-fld span-all"><label for="bc-coupon">Mã giảm giá</label><input id="bc-coupon" name="couponCode" maxlength="50" value="{{ old('couponCode') }}" style="text-transform:uppercase"></div>
                </div>
            </section>
        </div>
        <aside>
            <section class="ad-card ad-card--hl">
                <div class="ad-card__head"><h2>Tạm tính</h2></div>
                <div class="ad-card__body">
                    <div style="font-size:26px;font-weight:700;color:var(--ad-ink)" id="bc-total">0 đ</div>
                    <p class="ad-note" id="bc-note">Giá cuối cùng (gồm mã giảm giá) do máy chủ tính khi tạo đơn.</p>
                    @if ($canPay)
                        <label class="ad-check" style="margin:12px 0"><input type="checkbox" name="paidNow" value="1" @checked(old('paidNow'))> Khách đã thanh toán tại quầy (xác nhận đơn ngay)</label>
                    @endif
                    <button class="ad-btn ad-btn--primary" style="width:100%" type="submit"><i class="fa fa-check"></i> Tạo đơn</button>
                </div>
            </section>
        </aside>
    </form>
@endsection
@push('scripts')
<script>
(function () {
    var user = document.getElementById('bc-user'), tour = document.getElementById('bc-tour'), dep = document.getElementById('bc-dep');
    var a = document.getElementById('bc-a'), c = document.getElementById('bc-c'), total = document.getElementById('bc-total'), note = document.getElementById('bc-note');
    var left = 0;
    user.addEventListener('change', function () {
        var o = user.selectedOptions[0]; if (!o || !o.value) return;
        ['name', 'email', 'tel', 'address'].forEach(function (k) { var el = document.getElementById('bc-' + k); if (el && !el.value) el.value = o.dataset[k] || ''; });
    });
    function calc() {
        var o = tour.selectedOptions[0];
        var sum = o && o.value ? (+o.dataset.a * (+a.value || 0) + +o.dataset.c * (+c.value || 0)) : 0;
        total.textContent = sum.toLocaleString('vi-VN') + ' đ';
        var g = (+a.value || 0) + (+c.value || 0);
        note.textContent = left && g > left ? 'Ngày này chỉ còn ' + left + ' chỗ.' : 'Giá cuối cùng (gồm mã giảm giá) do máy chủ tính khi tạo đơn.';
        note.style.color = left && g > left ? 'var(--ad-bad)' : '';
    }
    function loadDeps() {
        dep.innerHTML = '<option value="">Đang tải…</option>';
        if (!tour.value) { dep.innerHTML = '<option value="">Chọn tour trước</option>'; return calc(); }
        fetch(tour.dataset.url + '?tour=' + tour.value, { headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).then(function (list) {
            dep.innerHTML = list.length ? '' : '<option value="">Tour không còn ngày nào còn chỗ</option>';
            list.forEach(function (d) { var op = new Option(d.date + ' → ' + d.end + ' · còn ' + d.left + ' chỗ', d.id); op.dataset.left = d.left; if (String(d.id) === dep.dataset.old) op.selected = true; dep.add(op); });
            left = dep.selectedOptions[0] ? +dep.selectedOptions[0].dataset.left || 0 : 0; calc();
        });
    }
    dep.addEventListener('change', function () { left = +(dep.selectedOptions[0] || {}).dataset?.left || 0; calc(); });
    tour.addEventListener('change', loadDeps); a.addEventListener('input', calc); c.addEventListener('input', calc);
    if (tour.value) loadDeps();
})();
</script>
@endpush
