@include('clients.blocks.header')

<main class="tv-auth">
    <div class="container">
        <div class="tv-auth__card">
            @include('clients.partials.auth-aside', [
                'asideTitle' => 'Đặt mật khẩu mới',
                'asideText' => 'Chọn một mật khẩu mà bạn chưa dùng ở nơi khác để tài khoản an toàn hơn.',
            ])

            <div class="tv-auth__body">
                <section class="sign-in" style="display:block">
                    <h1 class="tv-auth__title">Đặt lại mật khẩu</h1>

                    @if ($valid)
                        <p class="tv-auth__sub">Nhập mật khẩu mới cho tài khoản của bạn.</p>
                        <form id="reset-form" novalidate>
                            @csrf
                            <input type="hidden" name="token" id="reset_token" value="{{ $token }}">

                            <div class="tv-fieldrow">
                                <label for="password_new">Mật khẩu mới</label>
                                <div class="tv-pass">
                                    <input type="password" name="password" id="password_new" autocomplete="new-password"
                                        placeholder="Mật khẩu mới">
                                    <button type="button" class="tv-pass__eye" data-toggle-pass
                                        aria-label="Hiện/ẩn mật khẩu"><i class="fal fa-eye"></i></button>
                                </div>
                                <div class="invalid-feedback" id="validate_password_new"></div>
                            </div>

                            <div class="tv-fieldrow">
                                <label for="re_password_new">Nhập lại mật khẩu mới</label>
                                <input type="password" name="re_password" id="re_password_new"
                                    autocomplete="new-password" placeholder="Nhập lại mật khẩu mới">
                                <div class="invalid-feedback" id="validate_re_password_new"></div>
                            </div>

                            <button type="submit" id="reset_btn" class="tv-auth__submit">Đổi mật khẩu</button>
                        </form>
                    @else
                        <div class="tv-auth__alert">Liên kết đã hết hạn hoặc không hợp lệ.</div>
                        <a href="{{ route('password.request') }}" class="tv-auth__submit tv-auth__submit--link">Yêu cầu
                            liên kết mới</a>
                    @endif

                    <p class="tv-auth__switch"><a href="{{ route('login') }}"><i class="fal fa-arrow-left"></i> Quay lại
                            đăng nhập</a></p>
                </section>
            </div>
        </div>
    </div>
</main>

@include('clients.blocks.footer')

@if ($valid)
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const csrfToken = document.querySelector('input[name="_token"]').value;
            const e1 = document.getElementById('validate_password_new');
            const e2 = document.getElementById('validate_re_password_new');
            const show = (el, msg) => {
                el.innerText = msg;
                el.style.display = msg ? 'block' : 'none';
            };

            document.getElementById('reset-form').addEventListener('submit', function(e) {
                e.preventDefault();
                show(e1, '');
                show(e2, '');

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
                    .then(res => res.status === 429 ? {
                            success: false,
                            message: 'Bạn thử quá nhiều lần. Vui lòng đợi một phút rồi thử lại.'
                        } :
                        res.json())
                    .then(data => {
                        if (data.success) {
                            alert(data.message);
                            window.location.href = data.redirectUrl;
                        } else {
                            show(e2, data.message);
                        }
                    })
                    .catch(() => show(e2, 'Có lỗi xảy ra, vui lòng thử lại'));
            });
        });
    </script>
@endif
