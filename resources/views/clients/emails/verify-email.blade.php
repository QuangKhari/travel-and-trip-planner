<p>Xin chào {{ $user->fullName }},</p>

<p>Cảm ơn bạn đã đăng ký tài khoản trên Travela.</p>

<p>
    Vui lòng bấm vào liên kết bên dưới để kích hoạt tài khoản:
</p>

<p>
    <a href="{{ $url }}">Kích hoạt tài khoản Travela</a>
</p>

<p>
    Liên kết có hiệu lực trong {{ $minutes }} phút và chỉ sử dụng được một lần.
</p>

<p>
    Nếu bạn không thực hiện đăng ký tài khoản này, hãy bỏ qua email này.
</p>