@include('clients.blocks.header')

<div class="login-template">
    <div class="main">

        <section class="sign-in">
            <div class="container">
                <div class="signin-content">
                    <div class="signin-image">
                        <figure><img src="{{ asset('clients/assets/images/login/signin-image.jpg') }}"
                                alt="reset password image"></figure>
                        <a href="{{ route('login') }}" class="signup-image-link">Quay lại đăng nhập</a>
                    </div>

                    <div class="signin-form">
                        <h2 class="form-title">Đặt lại mật khẩu</h2>

                        @if ($valid)
                            <form id="reset-form">
                                @csrf
                                <input type="hidden" name="token" id="reset_token" value="{{ $token }}">

                                <div class="form-group">
                                    <label for="password_new"><i class="zmdi zmdi-lock"></i></label>
                                    <input type="password" name="password" id="password_new" placeholder="Mật khẩu mới"
                                        autocomplete="new-password" />
                                </div>
                                <div class="invalid-feedback" style="margin-top: -15px" id="validate_password_new">
                                </div>

                                <div class="form-group">
                                    <label for="re_password_new"><i class="zmdi zmdi-lock-outline"></i></label>
                                    <input type="password" name="re_password" id="re_password_new"
                                        placeholder="Xác nhận mật khẩu mới" autocomplete="new-password" />
                                </div>
                                <div class="invalid-feedback" style="margin-top: -15px" id="validate_re_password_new">
                                </div>

                                <div class="form-group form-button">
                                    <input type="submit" id="reset_btn" class="form-submit" value="Đổi mật khẩu" />
                                </div>
                            </form>
                        @else
                            <p style="margin-bottom: 20px; color: #e74c3c; font-size: 14px;">
                                Liên kết đã hết hạn hoặc không hợp lệ.
                            </p>
                            <a href="{{ route('password.request') }}" class="signup-image-link">Yêu cầu liên kết mới</a>
                        @endif
                    </div>
                </div>
            </div>
        </section>

    </div>
</div>

@include('clients.blocks.footer')

@if ($valid)
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const csrfToken = document.querySelector('input[name="_token"]').value;

            document.getElementById('reset-form').addEventListener('submit', function(e) {
                e.preventDefault();

                document.getElementById('validate_password_new').innerText = '';
                document.getElementById('validate_re_password_new').innerText = '';

                const payload = {
                    token: document.getElementById('reset_token').value,
                    password: document.getElementById('password_new').value,
                    re_password: document.getElementById('re_password_new').value
                };

                fetch('{{ route('password.update') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken
                        },
                        body: JSON.stringify(payload)
                    })
                    .then(res => res.status === 429 ?
                        {
                            success: false,
                            message: 'Bạn thử quá nhiều lần. Vui lòng đợi một phút rồi thử lại.'
                        } :
                        res.json())
                    .then(data => {
                        if (data.success) {
                            alert(data.message);
                            window.location.href = data.redirectUrl;
                        } else {
                            document.getElementById('validate_re_password_new').innerText = data
                            .message;
                        }
                    })
                    .catch(() => {
                        document.getElementById('validate_re_password_new').innerText =
                            'Có lỗi xảy ra, vui lòng thử lại';
                    });
            });
        });
    </script>
@endif
