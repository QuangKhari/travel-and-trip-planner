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

                        <p style="margin-bottom: 20px; color: #777; font-size: 14px;">
                            Nhập email đã đăng ký. Chúng tôi sẽ gửi liên kết đặt lại mật khẩu, có hiệu lực trong 60 phút.
                        </p>

                        <form id="forgot-form">
                            @csrf
                            <div class="form-group">
                                <label for="email_forgot"><i class="zmdi zmdi-email"></i></label>
                                <input type="email" name="email" id="email_forgot" placeholder="Email của bạn"/>
                            </div>
                            <div class="invalid-feedback" style="margin-top: -15px" id="validate_email_forgot"></div>
                            <div id="forgot_result" style="margin: 10px 0; color: #2ecc71; font-size: 14px;"></div>

                            <div class="form-group form-button">
                                <input type="submit" id="forgot_btn" class="form-submit" value="Gửi liên kết"/>
                            </div>
                        </form>
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
    const form = document.getElementById('forgot-form');
    const btn = document.getElementById('forgot_btn');

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        document.getElementById('validate_email_forgot').innerText = '';
        document.getElementById('forgot_result').innerText = '';
        btn.disabled = true;

        fetch('{{ route("password.email") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken
            },
            body: JSON.stringify({ email: document.getElementById('email_forgot').value })
        })
        .then(res => res.status === 429
            ? { success: false, message: 'Bạn thử quá nhiều lần. Vui lòng đợi một phút rồi thử lại.' }
            : res.json())
        .then(data => {
            if (data.success) {
                document.getElementById('forgot_result').innerText = data.message;
            } else {
                document.getElementById('validate_email_forgot').innerText = data.message;
            }
        })
        .catch(() => {
            document.getElementById('validate_email_forgot').innerText = 'Có lỗi xảy ra, vui lòng thử lại';
        })
        .finally(() => { btn.disabled = false; });
    });
});
</script>