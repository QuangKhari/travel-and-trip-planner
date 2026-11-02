<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Đăng nhập quản trị · {{ config('site.name', 'Travela') }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" type="image/ico" />
    <link href="{{ asset('admin/vendors/font-awesome/css/font-awesome.min.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/flasher/toastr.min.css') }}" rel="stylesheet">
    <link href="{{ asset('admin/assets/css/admin-app.css') }}?v={{ @filemtime(public_path('admin/assets/css/admin-app.css')) }}" rel="stylesheet">
    <style>
        :root { --p: #0d9488; --pd: #0f766e; --ink: #0f172a; --mute: #64748b; --line: #d6dee8; }
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body { background: #fff !important; font-family: "Be Vietnam Pro", "Segoe UI", Roboto, sans-serif; color: var(--ink); background: #f4f6f9; display: grid; grid-template-columns: minmax(0, 1.1fr) minmax(380px, .9fr); }
        .lg-art { position: relative; overflow: hidden; background: #0f172a url("{{ asset('clients/assets/images/hero/hero-ruong.webp') }}") center / cover no-repeat; }
        .lg-art::after { content: ""; position: absolute; inset: 0; background: linear-gradient(160deg, rgba(15, 23, 42, .25) 0%, rgba(15, 23, 42, .85) 100%); }
        .lg-art__txt { position: absolute; z-index: 1; left: 48px; right: 48px; bottom: 48px; color: #fff; }
        .lg-art__txt b { display: block; font-size: 30px; line-height: 1.25; margin-bottom: 10px; max-width: 520px; }
        .lg-art__txt span { color: #cbd5e1; font-size: 15px; }
        .lg-main { display: flex; align-items: center; justify-content: center; padding: 40px 24px; background: #fff; }
        .lg-box { width: 100%; max-width: 380px; }
        .lg-brand { display: flex; align-items: center; gap: 12px; margin-bottom: 34px; }
        .lg-brand i { width: 44px; height: 44px; border-radius: 12px; display: grid; place-items: center; font-size: 22px; color: #fff; background: linear-gradient(135deg, #2dd4bf, var(--p)); box-shadow: 0 8px 18px rgba(13, 148, 136, .3); }
        .lg-brand b { display: block; font-size: 19px; }
        .lg-brand small { color: var(--mute); }
        h1 { font-size: 24px; margin: 0 0 6px; }
        .lg-sub { color: var(--mute); margin: 0 0 26px; font-size: 14px; }
        label { display: block; font-size: 13px; font-weight: 600; margin: 0 0 6px; }
        .lg-fld { position: relative; margin-bottom: 16px; }
        .lg-fld i.fa { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #94a3b8; }
        .lg-fld input { width: 100%; height: 46px; padding: 0 44px 0 40px; border: 1px solid var(--line); border-radius: 12px; font: inherit; font-size: 15px; color: var(--ink); background: #fff; transition: border-color .15s, box-shadow .15s; }
        .lg-fld input:focus { outline: 0; border-color: var(--p); box-shadow: 0 0 0 4px rgba(13, 148, 136, .13); }
        .lg-eye { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); width: 36px; height: 36px; border: 0; background: none; color: #94a3b8; cursor: pointer; border-radius: 8px; }
        .lg-eye:hover { color: var(--ink); background: #f1f5f9; }
        .lg-caps { display: none; font-size: 12px; color: #b45309; margin: -8px 0 12px; }
        .lg-btn { width: 100%; height: 48px; border: 0; border-radius: 12px; background: var(--p); color: #fff; font: inherit; font-weight: 700; font-size: 15px; cursor: pointer; box-shadow: 0 8px 18px rgba(13, 148, 136, .28); transition: background .15s; }
        .lg-btn:hover { background: var(--pd); }
        .lg-btn:disabled { opacity: .7; cursor: progress; }
        .lg-foot { margin-top: 26px; display: flex; justify-content: space-between; font-size: 13px; }
        .lg-foot a { color: var(--mute); text-decoration: none; }
        .lg-foot a:hover { color: var(--p); }
        @media (max-width: 860px) { body { grid-template-columns: 1fr; } .lg-art { display: none; } }
    </style>
</head>

<body>
    <aside class="lg-art" aria-hidden="true">
        <div class="lg-art__txt">
            <b>Mỗi chuyến đi tốt bắt đầu từ một khâu vận hành gọn gàng.</b>
            <span>Đơn đặt, lịch khởi hành, hoàn tiền và nội dung Khám phá — tất cả ở một nơi.</span>
        </div>
    </aside>
    <main class="lg-main">
        <div class="lg-box">
            <div class="lg-brand"><i class="fa fa-compass"></i><span><b>{{ config('site.name', 'Travela') }}</b><small>Trang quản trị</small></span></div>
            <h1>Đăng nhập</h1>
            <p class="lg-sub">Dành cho quản trị viên, quản lý và nhân viên.</p>
            <form action="{{ route('admin.login-account') }}" method="POST" id="formLoginAdmin" novalidate>
                @csrf
                <label for="username">Tên đăng nhập</label>
                <div class="lg-fld"><i class="fa fa-user"></i><input type="text" name="username" id="username" autocomplete="username" required autofocus maxlength="50"></div>
                <label for="password">Mật khẩu</label>
                <div class="lg-fld"><i class="fa fa-lock"></i><input type="password" name="password" id="password" autocomplete="current-password" required maxlength="72">
                    <button type="button" class="lg-eye" id="lg-eye" aria-label="Hiện mật khẩu"><i class="fa fa-eye"></i></button></div>
                <p class="lg-caps" id="lg-caps"><i class="fa fa-exclamation-triangle"></i> Caps Lock đang bật</p>
                <button class="lg-btn" type="submit" id="lg-submit">Đăng nhập</button>
            </form>
            <div class="lg-foot">
                <a href="{{ url('/') }}"><i class="fa fa-arrow-left"></i> Về trang khách</a>
                <span style="color:#94a3b8">Quên mật khẩu? Liên hệ quản trị viên.</span>
            </div>
        </div>
    </main>

    <script src="{{ asset('admin/vendors/jquery/dist/jquery.min.js') }}"></script>
    <script src="{{ asset('vendor/flasher/toastr.min.js') }}"></script>
    <script>
        (function () {
            var pw = document.getElementById('password'), eye = document.getElementById('lg-eye'), caps = document.getElementById('lg-caps');
            eye.addEventListener('click', function () {
                var show = pw.type === 'password';
                pw.type = show ? 'text' : 'password';
                eye.innerHTML = '<i class="fa fa-eye' + (show ? '-slash' : '') + '"></i>';
                eye.setAttribute('aria-label', show ? 'Ẩn mật khẩu' : 'Hiện mật khẩu');
            });
            pw.addEventListener('keyup', function (e) { caps.style.display = e.getModifierState && e.getModifierState('CapsLock') ? 'block' : 'none'; });
            document.getElementById('formLoginAdmin').addEventListener('submit', function (e) {
                var u = document.getElementById('username');
                if (!u.value.trim() || !pw.value) { e.preventDefault(); (window.toastr ? toastr.error : alert)('Vui lòng nhập tên đăng nhập và mật khẩu.'); return; }
                document.getElementById('lg-submit').disabled = true;
                document.getElementById('lg-submit').textContent = 'Đang đăng nhập…';
            });
        })();
    </script>
</body>

</html>
