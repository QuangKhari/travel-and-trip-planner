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

    @if ($tfa)
        {{-- Đăng nhập hai lớp (Đợt C mục 18): mật khẩu + mã 6 số đổi mỗi 30 giây trên điện thoại --}}
        <section class="ad-card" id="bao-mat">
            <div class="ad-card__head"><h2><i class="fa fa-shield"></i> Đăng nhập hai lớp</h2>
                @if ($tfa['on'])<span class="ad-badge ad-badge--teal">Đang bật từ {{ date('d/m/Y', strtotime($tfa['since'])) }}</span>
                @elseif ($tfa['required'])<span class="ad-badge ad-badge--bad">Bắt buộc với vai trò của bạn</span>
                @else<span class="ad-badge ad-badge--mute">Chưa bật</span>@endif
            </div>
            <div class="ad-card__body">
                @if (!empty($tfa['codes']))
                    <div class="ad-alert" style="margin-bottom:14px;background:#fff7e6;border:1px solid #f3d19e;padding:12px 14px;border-radius:10px">
                        <b>Mã dự phòng — chỉ hiện một lần.</b> Mỗi mã dùng được một lần khi không có điện thoại. Chép hoặc in ra, cất nơi an toàn.
                        <pre id="tfaCodes" style="margin:10px 0 6px;padding:10px 12px;background:#fff;border:1px dashed var(--ad-line);border-radius:8px;font-size:15px;line-height:1.7;columns:2;max-width:360px">{{ implode("\n", $tfa['codes']) }}</pre>
                        <button type="button" class="ad-btn ad-btn--sm" onclick="navigator.clipboard && navigator.clipboard.writeText(document.getElementById('tfaCodes').textContent).then(function(){toastr.success('Đã chép mã dự phòng')})"><i class="fa fa-copy"></i> Chép</button>
                    </div>
                @endif
                @if ($tfa['on'])
                    <p style="margin-top:0">Mỗi lần đăng nhập, sau mật khẩu bạn nhập mã 6 số trong ứng dụng xác thực. Còn <b>{{ $tfa['left'] }}</b> mã dự phòng.</p>
                    <div class="ad-form ad-form--2" style="align-items:end">
                        <form action="{{ route('admin.2fa.codes') }}" method="POST" class="ad-fld">@csrf
                            <label for="tfaCodeNew">Tạo bộ mã dự phòng mới</label>
                            <div style="display:flex;gap:8px"><input id="tfaCodeNew" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="Mã 6 số hiện tại" maxlength="20" required>
                                <button class="ad-btn" type="submit">Tạo mới</button></div>
                        </form>
                        @unless ($tfa['required'])
                            <form action="{{ route('admin.2fa.disable') }}" method="POST" class="ad-fld">@csrf
                                <label for="tfaCodeOff">Tắt đăng nhập hai lớp</label>
                                <div style="display:flex;gap:8px"><input id="tfaCodeOff" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="Mã 6 số hoặc mã dự phòng" maxlength="20" required>
                                    <button class="ad-btn ad-btn--danger" type="submit">Tắt</button></div>
                            </form>
                        @endunless
                    </div>
                @else
                    <div style="display:flex;gap:22px;flex-wrap:wrap;align-items:flex-start">
                        <div id="tfaQr" data-uri="{{ $tfa['uri'] }}" style="width:180px;height:180px;border:1px solid var(--ad-line);border-radius:10px;padding:8px;background:#fff" aria-label="Mã QR để thêm tài khoản vào ứng dụng xác thực"></div>
                        <ol style="flex:1;min-width:260px;margin:0;padding-left:18px;line-height:1.7">
                            <li>Mở ứng dụng xác thực trên điện thoại (Google Authenticator, Microsoft Authenticator…), chọn thêm tài khoản và quét mã QR.</li>
                            <li>Không quét được thì nhập khóa: <code style="user-select:all;word-break:break-all">{{ trim(chunk_split($tfa['secret'], 4, ' ')) }}</code></li>
                            <li>Nhập mã 6 số đang hiện để xác nhận:
                                <form action="{{ route('admin.2fa.enable') }}" method="POST" style="display:flex;gap:8px;margin-top:6px;max-width:340px">@csrf
                                    <input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" placeholder="123456" required aria-label="Mã 6 số" style="flex:1">
                                    <button class="ad-btn ad-btn--primary" type="submit"><i class="fa fa-check"></i> Bật</button>
                                </form></li>
                        </ol>
                    </div>
                @endif
            </div>
        </section>
    @endif

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
@if ($tfa && !$tfa['on'])
<script src="{{ asset('admin/vendors/qrcode/qrcode.js') }}"></script>
<script>
    (function () {
        var box = document.getElementById('tfaQr');
        if (!box || !window.qrcode) return;
        var qr = qrcode(0, 'M'); qr.addData(box.dataset.uri); qr.make();
        box.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
        var svg = box.querySelector('svg'); if (svg) { svg.style.width = '100%'; svg.style.height = '100%'; }
    })();
</script>
@endif
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
