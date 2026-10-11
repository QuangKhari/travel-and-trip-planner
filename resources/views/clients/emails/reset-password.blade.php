@extends('clients.emails.layout')
@section('title', 'Đặt lại mật khẩu')
@section('content')
    <p style="margin:0 0 14px;">Xin chào <b>{{ $user->fullName }}</b>,</p>
    <p style="margin:0 0 22px;">Chúng tôi nhận được yêu cầu đặt lại mật khẩu cho tài khoản <b>{{ $user->username }}</b>. Bấm
        nút bên dưới để chọn mật khẩu mới.</p>
    <p style="margin:0 0 24px;">
        <a href="{{ $url }}"
            style="display:inline-block;background:#FF7A00;color:#ffffff;text-decoration:none;font-weight:700;padding:13px 28px;border-radius:10px;">Đặt
            lại mật khẩu</a>
    </p>
    <p style="margin:0 0 6px;font-size:13px;color:#6B7F8F;">Nút không bấm được? Dán liên kết này vào trình duyệt:</p>
    <p style="margin:0 0 10px;font-size:12px;word-break:break-all;"><a href="{{ $url }}"
            style="color:#0E8F8A;">{{ $url }}</a></p>
@endsection
@section('footnote')
    <p style="margin:0;">Liên kết có hiệu lực trong {{ $minutes }} phút và chỉ dùng được một lần. Nếu bạn không yêu cầu,
        hãy bỏ qua email này; mật khẩu của bạn vẫn giữ nguyên.</p>
@endsection
