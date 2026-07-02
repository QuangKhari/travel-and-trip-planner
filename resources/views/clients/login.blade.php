@include('clients.blocks.header')



<div class="login-template">
    <div class="main">

        <!-- Sing in  Form -->
        <section class="sign-in">
            <div class="container">
                <div class="signin-content">
                    <div class="signin-image">
                        <figure><img src="{{ asset('clients/assets/images/login/signin-image.jpg') }}" alt="sing up image"></figure>
                        <a href="javascript:void(0)" class="signup-image-link" id="sign-up">Tạo tài khoản</a>
                    </div>

                    <div class="signin-form">
                        <h2 class="form-title">Đăng nhập</h2>
                        <form action="{{ route('user-login') }}" method="POST" class="login-form" id="login-form">
                            <div class="form-group">
                                <label for="username_login"><i class="zmdi zmdi-account material-icons-name"></i></label>
                                <input type="text" name="username_login" id="username_login" placeholder="Tên người dùng"/>
                            </div>
                            <div class="invalid-feedback" style="margin-top: -15px" id="validate_username"></div>
                            @csrf
                            <div class="form-group">
                                <label for="password_login"><i class="zmdi zmdi-lock"></i></label>
                                <input type="password" name="password_login" id="password_login" placeholder="Mật khẩu"/>
                            </div>
                            <div class="invalid-feedback" style="margin-top:-15px" id="validate_password"></div>

                            <div class="forgot-password-link" style="text-align: right; margin-top: -10px; margin-bottom: 15px;">
                                <a href="{{ route('password.request') }}" style="font-size: 13px; color: #777;">Quên mật khẩu?</a>
                            </div>

                            <div class="form-group form-button">
                                <input type="submit" name="signin" id="signin" class="form-submit" value="Đăng nhập"/>
                            </div>
                        </form>
                        <div class="social-login">
                            <span class="social-label">Or login with</span>
                            <ul class="socials">
                                <li><a href="#"><i class="display-flex-center zmdi zmdi-facebook"></i></a></li>
                                <li><a href="#"><i class="display-flex-center zmdi zmdi-twitter"></i></a></li>
                                <li><a href="#"><i class="display-flex-center zmdi zmdi-google"></i></a></li>
                            </ul>
                        </div>

                        <div class="admin-login-link" style="text-align: center; margin-top: 15px;">
                            <a href="{{ route('admin.login') }}" style="font-size: 13px; color: #777;">Đăng nhập với vai trò quản trị viên</a>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        <!-- Sign up form -->
        <section class="signup">
            <div class="container">
                <div class="signup-content">
                    <div class="signup-form">
                        <h2 class="form-title">Đăng ký</h2>
                        <form action="{{ route('register') }}" method="POST" class="register-form" id="register-form" style="margin-top: 15px">
                            <div class="form-group">
                                <label for="username_register"><i class="zmdi zmdi-account material-icons-name"></i></label>
                                <input type="text" name="username_register" id="username_register" placeholder="Tên người dùng"/>
                            </div>
                            <div class="invalid-feedback" style="margin-top:-15px" id="validate_username_regis"></div>
                            @csrf
                            <div class="form-group">
                                <label for="email_register"><i class="zmdi zmdi-email"></i></label>
                                <input type="email" name="email_register" id="email_register" placeholder="Email của bạn"/>
                            </div>
                            <div class="invalid-feedback" style="margin-top:-15px" id="validate_email_regis"></div>
                            <div class="form-group">
                                <label for="password_register"><i class="zmdi zmdi-lock"></i></label>
                                <input type="password" name="password_register" id="password_register" placeholder="Mật khẩu"/>
                            </div>
                            <div class="invalid-feedback" style="margin-top:-15px" id="validate_password_regis"></div>
                            <div class="form-group">
                                <label for="re-pass"><i class="zmdi zmdi-lock-outline"></i></label>
                                <input type="password" name="re_pass" id="re_pass" placeholder="Xác nhận mật khẩu"/>
                            </div>
                            <div class="invalid-feedback" style="margin-top:-15px" id="validate_repass"></div>
                            <div class="form-group form-button">
                                <input type="submit" name="signup" id="signup" class="form-submit" value="Đăng ký"/>
                            </div>
                        </form>
                    </div>
                    <div class="signup-image">
                        <figure><img src="{{ asset('clients/assets/images/login/signup-image.jpg') }}" alt="sing up image"></figure>
                        <a href="javascript:void(0)" class="signup-image-link" id="sign-in">Tôi đã là thành viên</a>
                    </div>
                </div>
            </div>
        </section>

    </div>
</div>

@include('clients.blocks.footer')