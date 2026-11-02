{{-- Danh sách + form thêm tài khoản quản trị theo vai trò. Biến: $rows, $role (manager|staff), $label, $addRoute, $delRoute --}}
<section class="ad-card">
    <div class="ad-card__head"><h2>{{ $label }} <small>· {{ count($rows) }}</small></h2>
        <button type="button" class="ad-btn ad-btn--sm ad-btn--primary" data-toggle="collapse" data-target="#add-{{ $role }}" aria-expanded="false"><i class="fa fa-plus"></i> Thêm</button></div>
    <form action="{{ route($addRoute) }}" method="POST" class="collapse ad-card__body" id="add-{{ $role }}" style="background:#fbfcfd;border-bottom:1px solid var(--ad-line)">
        @csrf
        <div class="ad-form ad-form--3">
            <div class="ad-fld"><label>Tên đăng nhập <span class="req">*</span></label><input name="userName" required maxlength="50" autocomplete="off"></div>
            <div class="ad-fld"><label>Mật khẩu <span class="req">*</span></label><input type="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password"></div>
            <div class="ad-fld"><label>Email <span class="req">*</span></label><input type="email" name="email" required maxlength="50"></div>
            <div class="ad-fld"><label>Họ tên <span class="req">*</span></label><input name="fullName" required maxlength="50"></div>
            <div class="ad-fld"><label>Địa chỉ <span class="req">*</span></label><input name="address" required maxlength="255"></div>
            <div class="ad-fld" style="justify-content:flex-end"><button class="ad-btn ad-btn--primary" type="submit">Tạo tài khoản</button></div>
        </div>
    </form>
    @if (count($rows))
        <div class="ad-tablewrap">
            <table class="ad-table">
                <thead><tr><th>Tài khoản</th><th>Email</th><th>Địa chỉ</th><th>Tạo ngày</th><th class="num"></th></tr></thead>
                <tbody>
                @foreach ($rows as $m)
                    <tr>
                        <td><div class="ad-person" style="min-width:0"><span class="ad-avatar">{{ mb_strtoupper(mb_substr($m->fullName ?: $m->userName, 0, 1)) }}</span><span><b>{{ $m->fullName }}</b><small>{{ '@' . $m->userName }}</small></span></div></td>
                        <td>{{ $m->email }}</td>
                        <td>{{ $m->address ?: '—' }}</td>
                        <td class="nowrap">{{ !empty($m->createdDate) ? date('d/m/Y', strtotime($m->createdDate)) : '—' }}</td>
                        <td class="num"><button type="button" class="ad-btn ad-btn--sm ad-btn--danger js-del-admin" data-id="{{ $m->adminId }}" data-url="{{ route($delRoute) }}" data-name="{{ $m->userName }}"><i class="fa fa-trash-o"></i> Xóa</button></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="ad-empty" style="padding:28px"><i class="fa fa-user"></i>Chưa có {{ mb_strtolower($label) }}.</div>
    @endif
</section>
