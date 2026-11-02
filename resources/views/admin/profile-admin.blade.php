@extends('admin.layout')
@php
    $role = session('adminRole');
    $roleLabel = ['admin' => 'Quản trị viên', 'manager' => 'Quản lý', 'staff' => 'Nhân viên'][$role] ?? $role;
    $perm = [
        ['Xem dashboard, đơn, đánh giá; ẩn đánh giá; trả lời yêu cầu tư vấn', true, true, true],
        ['Xác nhận thanh toán, hoàn tất, hủy đơn, hoàn tiền', false, true, true],
        ['Tour, lịch khởi hành, khuyến mãi, nội dung Khám phá', false, true, true],
        ['Khóa / mở người dùng, xóa đánh giá, xem nhật ký thao tác', false, true, true],
        ['Thêm / xóa nhân viên', false, true, true],
        ['Thêm / xóa quản lý', false, false, true],
    ];
@endphp
@section('content')
    <div class="ad-head">
        <div>
            <h1>{{ $role === 'staff' ? 'Tài khoản của tôi' : 'Tài khoản quản trị' }}</h1>
            <p>Cập nhật hồ sơ, ảnh đại diện và mật khẩu của bạn{{ $role === 'admin' ? '; thêm hoặc xóa quản lý và nhân viên' : ($role === 'manager' ? '; thêm hoặc xóa nhân viên' : '') }}. Vai trò được kiểm tra lại ở mỗi lần thao tác.</p>
        </div>
    </div>

    <div class="ad-grid ad-grid--12">
        <section class="ad-card">
            <div class="ad-card__body" style="text-align:center">
                <div style="position:relative;width:132px;height:132px;margin:6px auto 14px">
                    <img id="avatarAdminPreview" src="{{ \App\Support\Avatar::admin() }}" alt="Ảnh đại diện" style="width:132px;height:132px;border-radius:50%;object-fit:cover;box-shadow:0 0 0 4px #fff,0 0 0 6px var(--ad-primary-l)">
                    <label for="avatarAdmin" id="btn_avatar" action="{{ route('admin.update-avatar') }}" class="ad-btn ad-btn--sm ad-btn--primary ad-btn--icon" title="Đổi ảnh" style="position:absolute;right:4px;bottom:4px;border-radius:50%;width:34px;height:34px"><i class="fa fa-camera"></i></label>
                    <input type="file" name="avatarAdmin" id="avatarAdmin" hidden accept="image/*">
                </div>
                <h2 id="nameAdmin" style="font-size:18px;margin:0">{{ $admin->fullName }}</h2>
                <div class="ad-muted">{{ '@' . $admin->userName }}</div>
                <span class="ad-badge ad-badge--teal" style="margin-top:8px">{{ $roleLabel }}</span>
                <dl class="ad-kv ad-kv--tight" style="text-align:left;margin-top:18px">
                    <dt>Email</dt><dd id="emailAdmin">{{ $admin->email }}</dd>
                    <dt>Địa chỉ</dt><dd id="addressAdmin">{{ $admin->address ?: '—' }}</dd>
                </dl>
            </div>
        </section>
        <section class="ad-card">
            <div class="ad-card__head"><h2>Hồ sơ</h2></div>
            <form action="{{ route('admin.update-admin') }}" id="formProfileAdmin">
                @csrf
                <div class="ad-card__body ad-form ad-form--2">
                    <div class="ad-fld"><label for="fullName">Họ tên <span class="req">*</span></label><input id="fullName" name="fullName" required maxlength="50" value="{{ $admin->fullName }}"></div>
                    <div class="ad-fld"><label for="email">Email <span class="req">*</span></label><input id="email" type="email" name="email" required maxlength="50" value="{{ $admin->email }}"></div>
                    <div class="ad-fld span-all"><label for="address">Địa chỉ <span class="req">*</span></label><input id="address" name="address" required maxlength="255" value="{{ $admin->address }}"></div>
                    <div class="ad-fld"><label for="password">Mật khẩu mới</label><input id="password" type="password" name="password" minlength="8" maxlength="72" autocomplete="new-password" placeholder="Để trống nếu không đổi">
                        <span class="ad-hint">Tối thiểu 8 ký tự.</span></div>
                </div>
                <div class="ad-card__foot"><button type="submit" class="ad-btn ad-btn--primary"><i class="fa fa-check"></i> Lưu hồ sơ</button></div>
            </form>
        </section>
    </div>

    @if ($role === 'admin')
        @include('admin.partials.admin-member-card', ['rows' => $managers, 'role' => 'manager', 'label' => 'Quản lý', 'addRoute' => 'admin.add-manager', 'delRoute' => 'admin.delete-manager'])
    @endif
    @if (in_array($role, ['admin', 'manager'], true))
        @include('admin.partials.admin-member-card', ['rows' => $staffs, 'role' => 'staff', 'label' => 'Nhân viên', 'addRoute' => 'admin.add-staff', 'delRoute' => 'admin.delete-staff'])
    @endif

    <section class="ad-card">
        <div class="ad-card__head"><h2>Phân quyền theo vai trò</h2><small>Đặc tả §12.2</small></div>
        <div class="ad-tablewrap">
            <table class="ad-table">
                <thead><tr><th>Chức năng</th><th style="text-align:center">Nhân viên</th><th style="text-align:center">Quản lý</th><th style="text-align:center">Admin</th></tr></thead>
                <tbody>
                @foreach ($perm as [$label, $s, $m, $a])
                    <tr><td>{{ $label }}</td>
                        @foreach ([$s, $m, $a] as $ok)<td style="text-align:center">{!! $ok ? '<i class="fa fa-check" style="color:var(--ad-ok)"></i>' : '<i class="fa fa-minus" style="color:#cbd5e1"></i>' !!}</td>@endforeach</tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endsection
@push('scripts')
<script>
    document.addEventListener('click', function (e) {
        var b = e.target.closest('.js-del-admin');
        if (!b) return;
        var go = function () {
            var fd = new FormData();
            fd.append('_token', document.querySelector('meta[name="csrf-token"]').content);
            fd.append('adminId', b.dataset.id);
            fetch(b.dataset.url, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (r) { toastr[r.success ? 'success' : 'error'](r.message); if (r.success) b.closest('tr').remove(); })
                .catch(function () { toastr.error('Không kết nối được máy chủ.'); });
        };
        Swal.fire({ title: 'Xóa tài khoản ' + b.dataset.name + '?', text: 'Người này sẽ bị đăng xuất ngay và không vào trang quản trị được nữa.', icon: 'warning',
            showCancelButton: true, confirmButtonText: 'Xóa', cancelButtonText: 'Thôi', confirmButtonColor: '#dc2626', reverseButtons: true })
            .then(function (r) { if (r.isConfirmed) go(); });
    });
</script>
@endpush
