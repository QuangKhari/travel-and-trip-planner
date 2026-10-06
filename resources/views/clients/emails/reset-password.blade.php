<p>Xin chào {{ $user->fullName }},</p>

<p>Chúng tôi nhận được yêu cầu đặt lại mật khẩu cho tài khoản <strong>{{ $user->username }}</strong> trên Travela.</p>

<p><a href="{{ $url }}">Bấm vào đây để đặt lại mật khẩu</a></p>

<p>Liên kết có hiệu lực trong {{ $minutes }} phút và chỉ dùng được một lần. Nếu bạn không yêu cầu, hãy bỏ qua email
    này; mật khẩu của bạn không thay đổi.</p>
