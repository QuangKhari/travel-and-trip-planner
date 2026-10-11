@include('clients.blocks.header')

<main class="tv-auth tv-auth--split tv-auth--register">
    <div class="container">
        <div class="tv-auth__card">
            @include('clients.partials.auth-aside')

            <div class="tv-auth__body">
                <div class="tv-auth__eyebrow"><i class="fal fa-magic"></i> YOUR NEXT ESCAPE</div>
                <section class="signup">
                    <h1 class="tv-auth__title">Tạo tài khoản</h1>
                    <p class="tv-auth__sub">Chỉ mất một phút để bắt đầu lưu lại những chuyến đi của bạn.</p>

                    <form action="{{ route('register') }}" method="POST" class="register-form" id="register-form"
                        novalidate>
                        @csrf
                        <div class="tv-fieldrow">
                            <label for="username_register">Tên đăng nhập</label>
                            <input type="text" name="username_register" id="username_register"
                                autocomplete="username" placeholder="Ví dụ: nguyenvana">
                            <div class="invalid-feedback" id="validate_username_regis"></div>
                        </div>

                        <div class="tv-fieldrow">
                            <label for="email_register">Email</label>
                            <input type="email" name="email_register" id="email_register" autocomplete="email"
                                placeholder="ban@example.com">
                            <div class="invalid-feedback" id="validate_email_regis"></div>
                        </div>

                        <div class="tv-auth__row2">
                            <div class="tv-fieldrow">
                                <label for="password_register">Mật khẩu</label>
                                <div class="tv-pass">
                                    <input type="password" name="password_register" id="password_register"
                                        autocomplete="new-password" placeholder="Tạo mật khẩu">
                                    <button type="button" class="tv-pass__eye" data-toggle-pass
                                        aria-label="Hiện/ẩn mật khẩu">
                                        <i class="fal fa-eye"></i>
                                    </button>
                                </div>
                                <div class="invalid-feedback" id="validate_password_regis"></div>
                            </div>
                            <div class="tv-fieldrow">
                                <label for="re_pass">Nhập lại mật khẩu</label>
                                <input type="password" name="re_pass" id="re_pass" autocomplete="new-password"
                                    placeholder="Nhập lại mật khẩu">
                                <div class="invalid-feedback" id="validate_repass"></div>
                            </div>
                        </div>

                        <button type="submit" name="signup" id="signup" class="tv-auth__submit">
                            <span>Tạo tài khoản</span><i class="fal fa-arrow-right"></i>
                        </button>
                    </form>

                    <p class="tv-auth__switch">Đã có tài khoản?
                        <a href="{{ route('login') }}">Đăng nhập</a>
                    </p>

                    <button type="button" class="tv-auth__resend-link" id="resend-verification-link">
                        Chưa nhận được email kích hoạt?
                    </button>
                    <div id="resend-verification-form" class="tv-resend" style="display:none">
                        <input type="email" id="resend_verification_email" placeholder="Nhập email đã đăng ký">
                        <button type="button" id="resend-verification-button" class="tv-btn-solid">
                            Gửi lại email kích hoạt
                        </button>
                    </div>
                </section>
            </div>
        </div>
    </div>
</main>

<script>
    window.emailVerificationResendUrl = @json(route('email.verification.resend'));
</script>

@include('clients.blocks.footer')
