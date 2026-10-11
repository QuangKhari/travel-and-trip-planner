@include('clients.blocks.header')

<main class="tv-auth">
    <div class="container">
        <div class="tv-auth__card">
            @include('clients.partials.auth-aside', [
                'asideTitle' => 'Quên mật khẩu cũng không sao',
                'asideText' => 'Nhập email đã đăng ký, chúng tôi sẽ gửi liên kết để bạn đặt mật khẩu mới.',
            ])

            <div class="tv-auth__body">
                <section class="sign-in" style="display:block">
                    <h1 class="tv-auth__title">Quên mật khẩu</h1>
                    <p class="tv-auth__sub">Liên kết đặt lại mật khẩu có hiệu lực trong 60 phút.</p>

                    <form id="forgot-form" novalidate>
                        @csrf
                        <div class="tv-fieldrow">
                            <label for="email_forgot">Email</label>
                            <input type="email" name="email" id="email_forgot" autocomplete="email"
                                placeholder="ban@example.com">
                            <div class="invalid-feedback" id="validate_email_forgot"></div>
                        </div>
                        <div id="forgot_result" class="tv-auth__ok" role="status"></div>
                        <button type="submit" id="forgot_btn" class="tv-auth__submit">Gửi liên kết</button>
                    </form>

                    <p class="tv-auth__switch"><a href="{{ route('login') }}"><i class="fal fa-arrow-left"></i> Quay lại
                            đăng nhập</a></p>
                </section>
            </div>
        </div>
    </div>
</main>

@include('clients.blocks.footer')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const csrfToken = document.querySelector('input[name="_token"]').value;
        const form = document.getElementById('forgot-form');
        const btn = document.getElementById('forgot_btn');
        const err = document.getElementById('validate_email_forgot');

        form.addEventListener('submit', function(e) {
            e.preventDefault();

            err.innerText = '';
            err.style.display = 'none';
            document.getElementById('forgot_result').innerText = '';
            btn.disabled = true;

            fetch('{{ route('password.email') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({
                        email: document.getElementById('email_forgot').value
                    })
                })
                .then(res => res.status === 429 ?
                    {
                        success: false,
                        message: 'Bạn thử quá nhiều lần. Vui lòng đợi một phút rồi thử lại.'
                    } :
                    res.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('forgot_result').innerText = data.message;
                    } else {
                        err.innerText = data.message;
                        err.style.display = 'block';
                    }
                })
                .catch(() => {
                    err.innerText = 'Có lỗi xảy ra, vui lòng thử lại';
                    err.style.display = 'block';
                })
                .finally(() => {
                    btn.disabled = false;
                });
        });
    });
</script>
