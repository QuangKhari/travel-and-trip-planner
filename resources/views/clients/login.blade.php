@include('clients.blocks.header')

<main class="tv-auth px-auth ux-wc-auth" id="main">
    <div class="container">
        <div class="tv-auth__card px-auth__card">
            <div class="tv-auth__body px-auth__body">
                <a href="{{ route('home') }}" class="px-auth__brand">
                    <span>{{ config('site.name') }}</span>
                    <small>Khám phá thêm. Sống trọn từng chuyến đi.</small>
                </a>

                {{-- Hai liên kết điều hướng giữa 2 trang (không phải tab): dùng aria-current thay vì role="tab" --}}
                <nav class="px-tabsw" aria-label="Đăng nhập hoặc đăng ký">
                    <a href="{{ route('register.form') }}">Đăng ký</a>
                    <a href="{{ route('login') }}" aria-current="page" class="is-on">Đăng nhập</a>
                </nav>

                {{-- ĐĂNG NHẬP (jQuery cũ bật/tắt .sign-in / .signup — giữ nguyên tên lớp và id) --}}
                <section class="sign-in">
                    <h1 class="tv-auth__title">Chào mừng bạn quay lại</h1>
                    <p class="tv-auth__sub">Đăng nhập để xem tour đã đặt và tiếp tục hành trình.</p>

                    <form action="{{ route('user-login') }}" method="POST" class="login-form" id="lgForm"
                        novalidate>
                        @csrf
                        <p class="ux-formerr" id="lgErrForm" role="alert" hidden></p>

                        <div class="tv-fieldrow">
                            <label for="lgUser">Tên đăng nhập hoặc email</label>
                            <input type="text" name="username_login" id="lgUser" autocomplete="username"
                                placeholder="Tên đăng nhập hoặc email" autocapitalize="none" spellcheck="false"
                                autofocus aria-describedby="lgErrUser">
                            <div class="invalid-feedback" id="lgErrUser"></div>
                        </div>

                        <div class="tv-fieldrow">
                            <label for="lgPass">Mật khẩu</label>
                            <div class="tv-pass">
                                <input type="password" name="password_login" id="lgPass"
                                    autocomplete="current-password" placeholder="Nhập mật khẩu"
                                    aria-describedby="lgCaps lgErrPass">
                                <button type="button" class="tv-pass__eye" data-ux-pass="#lgPass"
                                    aria-label="Hiện mật khẩu" aria-pressed="false"><i class="fal fa-eye"
                                        aria-hidden="true"></i></button>
                            </div>
                            <p class="ux-caps" id="lgCaps" role="status" hidden><i
                                    class="fas fa-exclamation-triangle" aria-hidden="true"></i> Caps Lock đang bật</p>
                            <div class="invalid-feedback" id="lgErrPass"></div>
                        </div>

                        <p class="px-auth__forgot"><a href="{{ route('password.request') }}">Quên mật khẩu?</a></p>

                        <button type="submit" name="signin" id="lgSubmit" class="tv-auth__submit"><span>Đăng
                                nhập</span></button>
                    </form>

                    <p class="tv-auth__switch">Chưa có tài khoản? <a href="{{ route('register.form') }}">Tạo tài
                            khoản</a></p>
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
