@include('clients.blocks.header')

<div class="login-template">
    <div class="main">

        <!-- Forgot Password Form -->
        <section class="sign-in">
            <div class="container">
                <div class="signin-content">
                    <div class="signin-image">
                        <figure><img src="{{ asset('clients/assets/images/login/signin-image.jpg') }}" alt="forgot password image"></figure>
                        <a href="{{ route('login') }}" class="signup-image-link">Quay lại đăng nhập</a>
                    </div>

                    <div class="signin-form">
                        <h2 class="form-title">Quên mật khẩu</h2>

                        <!-- BƯỚC 1: Xác thực tài khoản -->
                        <div id="step-verify">
                            <p style="margin-bottom: 20px; color: #777; font-size: 14px;">
                                Nhập tên tài khoản và email đã đăng ký để xác thực.
                            </p>

                            <form id="verify-form">
                                @csrf
                                <div class="form-group">
                                    <label for="username_forgot"><i class="zmdi zmdi-account material-icons-name"></i></label>
                                    <input type="text" name="username" id="username_forgot" placeholder="Tên tài khoản"/>
                                </div>
                                <div class="invalid-feedback" style="margin-top: -15px" id="validate_username_forgot"></div>

                                <div class="form-group">
                                    <label for="email_forgot"><i class="zmdi zmdi-email"></i></label>
                                    <input type="email" name="email" id="email_forgot" placeholder="Email của bạn"/>
                                </div>
                                <div class="invalid-feedback" style="margin-top: -15px" id="validate_email_forgot"></div>

                                <div class="form-group form-button">
                                    <input type="submit" id="verify_btn" class="form-submit" value="Xác thực"/>
                                </div>
                            </form>
                        </div>

                        <!-- BƯỚC 2: Đổi mật khẩu mới (ẩn cho tới khi xác thực đúng) -->
                        <div id="step-reset" style="display:none;">
                            <p style="margin-bottom: 20px; color: #2ecc71; font-size: 14px;">
                                Xác thực thành công. Vui lòng nhập mật khẩu mới.
                            </p>

                            <form id="reset-form">
                                @csrf
                                <input type="hidden" name="username" id="username_confirmed">
                                <input type="hidden" name="email" id="email_confirmed">

                                <div class="form-group">
                                    <label for="password_new"><i class="zmdi zmdi-lock"></i></label>
                                    <input type="password" name="password" id="password_new" placeholder="Mật khẩu mới"/>
                                </div>
                                <div class="invalid-feedback" style="margin-top: -15px" id="validate_password_new"></div>

                                <div class="form-group">
                                    <label for="re_password_new"><i class="zmdi zmdi-lock-outline"></i></label>
                                    <input type="password" name="re_password" id="re_password_new" placeholder="Xác nhận mật khẩu mới"/>
                                </div>
                                <div class="invalid-feedback" style="margin-top: -15px" id="validate_re_password_new"></div>

                                <div class="form-group form-button">
                                    <input type="submit" id="reset_btn" class="form-submit" value="Đổi mật khẩu"/>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </section>

    </div>
</div>

@include('clients.blocks.footer')

<script>
document.addEventListener('DOMContentLoaded', function () {
    const csrfToken = document.querySelector('input[name="_token"]').value;

    // BƯỚC 1: xác thực username + email
    document.getElementById('verify-form').addEventListener('submit', function (e) {
        e.preventDefault();

        document.getElementById('validate_username_forgot').innerText = '';
        document.getElementById('validate_email_forgot').innerText = '';

        const username = document.getElementById('username_forgot').value;
        const email = document.getElementById('email_forgot').value;

        fetch('{{ route("password.verify") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken
            },
            body: JSON.stringify({ username: username, email: email })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                document.getElementById('username_confirmed').value = username;
                document.getElementById('email_confirmed').value = email;
                document.getElementById('step-verify').style.display = 'none';
                document.getElementById('step-reset').style.display = 'block';
            } else {
                document.getElementById('validate_username_forgot').innerText = data.message;
            }
        })
        .catch(() => {
            document.getElementById('validate_username_forgot').innerText = 'Có lỗi xảy ra, vui lòng thử lại';
        });
    });

    // BƯỚC 2: đổi mật khẩu mới
    document.getElementById('reset-form').addEventListener('submit', function (e) {
        e.preventDefault();

        document.getElementById('validate_password_new').innerText = '';
        document.getElementById('validate_re_password_new').innerText = '';

        const payload = {
            username: document.getElementById('username_confirmed').value,
            email: document.getElementById('email_confirmed').value,
            password: document.getElementById('password_new').value,
            re_password: document.getElementById('re_password_new').value
        };

        fetch('{{ route("password.update") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken
            },
            body: JSON.stringify(payload)
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                window.location.href = data.redirectUrl;
            } else {
                document.getElementById('validate_re_password_new').innerText = data.message;
            }
        })
        .catch(() => {
            document.getElementById('validate_re_password_new').innerText = 'Có lỗi xảy ra, vui lòng thử lại';
        });
    });
});
</script>