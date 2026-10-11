@include('clients.blocks.header')

<main class="tv-auth">
    <div class="container">
        <div class="tv-auth__card">
            @include('clients.partials.auth-aside', [
                'asideTitle' => 'Chào mừng bạn trở lại',
                'asideText' =>
                    'Đăng nhập để quản lý tour đã đặt, theo dõi hành trình và nhận thông tin xác nhận nhanh hơn.',
            ])

            <div class="tv-auth__body">
                {{-- ĐĂNG NHẬP (jQuery cũ bật/tắt .sign-in / .signup — giữ nguyên tên lớp và id) --}}
                <section class="sign-in">
                    <h1 class="tv-auth__title">Đăng nhập</h1>
                    <p class="tv-auth__sub">Chào mừng bạn quay lại.</p>

                    <form action="{{ route('user-login') }}" method="POST" class="login-form" id="login-form" novalidate>
                        @csrf
                        <div class="tv-fieldrow">
                            <label for="username_login">Tên đăng nhập</label>
                            <input type="text" name="username_login" id="username_login" autocomplete="username"
                                placeholder="Nhập tên đăng nhập">
                            <div class="invalid-feedback" id="validate_username"></div>
                        </div>

                        <div class="tv-fieldrow">
                            <div class="tv-fieldrow__top">
                                <label for="password_login">Mật khẩu</label>
                                <a href="{{ route('password.request') }}">Quên mật khẩu?</a>
                            </div>
                            <div class="tv-pass">
                                <input type="password" name="password_login" id="password_login"
                                    autocomplete="current-password" placeholder="Nhập mật khẩu">
                                <button type="button" class="tv-pass__eye" data-toggle-pass
                                    aria-label="Hiện/ẩn mật khẩu"><i class="fal fa-eye"></i></button>
                            </div>
                            <div class="invalid-feedback" id="validate_password"></div>
                        </div>

                        <button type="submit" name="signin" id="signin" class="tv-auth__submit">Đăng nhập</button>
                    </form>

                    <p class="tv-auth__switch">Chưa có tài khoản? <a href="{{ route('register.form') }}">Tạo tài
                            khoản</a></p>
                    <p class="tv-auth__admin"><a href="{{ route('admin.login') }}">Đăng nhập dành cho quản trị viên</a>
                    </p>
                </section>

            </div>
        </div>
    </div>
</main>

@include('clients.blocks.footer')
