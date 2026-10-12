@include('clients.blocks.header')

<main class="tv-auth px-auth">
    <div class="container">
        <div class="tv-auth__card px-auth__card">
            <div class="tv-auth__body px-auth__body">
                <a href="{{ route('home') }}" class="px-auth__brand">
                    <span>{{ config('site.name') }}</span>
                    <small>Khám phá thêm. Sống trọn từng chuyến đi.</small>
                </a>

                <div class="px-tabsw" role="tablist">
                    <a href="{{ route('register.form') }}" role="tab" aria-selected="false">Đăng ký</a>
                    <a href="{{ route('login') }}" role="tab" aria-selected="true" class="is-on">Đăng nhập</a>
                </div>

                {{-- ĐĂNG NHẬP (jQuery cũ bật/tắt .sign-in / .signup — giữ nguyên tên lớp và id) --}}
                <section class="sign-in">
                    <h1 class="tv-auth__title">Chào mừng bạn quay lại</h1>
                    <p class="tv-auth__sub">Đăng nhập để xem tour đã đặt và tiếp tục hành trình.</p>

                    <form action="{{ route('user-login') }}" method="POST" class="login-form" id="login-form"
                        novalidate>
                        @csrf
                        <div class="tv-fieldrow">
                            <label for="username_login">Tên đăng nhập</label>
                            <input type="text" name="username_login" id="username_login" autocomplete="username"
                                placeholder="Nhập tên đăng nhập">
                            <div class="invalid-feedback" id="validate_username"></div>
                        </div>

                        <div class="tv-fieldrow">
                            <label for="password_login">Mật khẩu</label>
                            <div class="tv-pass">
                                <input type="password" name="password_login" id="password_login"
                                    autocomplete="current-password" placeholder="Nhập mật khẩu">
                                <button type="button" class="tv-pass__eye" data-toggle-pass
                                    aria-label="Hiện/ẩn mật khẩu"><i class="fal fa-eye"></i></button>
                            </div>
                            <div class="invalid-feedback" id="validate_password"></div>
                        </div>

                        <p class="px-auth__forgot"><a href="{{ route('password.request') }}">Quên mật khẩu?</a></p>

                        <button type="submit" name="signin" id="signin" class="tv-auth__submit">Bắt đầu hành
                            trình</button>
                    </form>

                    <p class="tv-auth__switch">Chưa có tài khoản? <a href="{{ route('register.form') }}">Tạo tài
                            khoản</a></p>
                    <p class="tv-auth__admin"><a href="{{ route('admin.login') }}">Đăng nhập dành cho quản trị viên</a>
                    </p>
                </section>
            </div>

            @include('clients.partials.auth-aside', [
                'asideTitle' => 'Khám phá thế giới, theo cách của bạn',
                'asideText' => 'Từ biển xanh đến núi rừng, mỗi chuyến đi đều bắt đầu từ một lần đăng nhập.',
            ])
        </div>
    </div>
</main>

@include('clients.blocks.footer')
