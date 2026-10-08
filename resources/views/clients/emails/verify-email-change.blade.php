<p>Xin chào {{ $user->fullName }},</p>

<p>Bạn vừa yêu cầu thay đổi email tài khoản Travela.</p>

<p>
    Email mới của bạn:
    <strong>{{ $newEmail }}</strong>
</p>

<p>
    Vui lòng bấm vào liên kết bên dưới để xác minh email mới:
</p>

<p>
    <a href="{{ $url }}">Xác minh email mới</a>
</p>

<p>
    Liên kết có hiệu lực trong {{ $minutes }} phút và chỉ sử dụng được một lần.
</p>

<p>
    Nếu bạn không thực hiện yêu cầu này, hãy bỏ qua email này.
    Email hiện tại của tài khoản vẫn được giữ nguyên.
</p>
