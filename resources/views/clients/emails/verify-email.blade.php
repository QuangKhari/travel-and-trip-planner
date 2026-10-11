@extends('clients.emails.layout')
@section('title', 'Kích hoạt tài khoản')
@section('content')
    <p style="margin:0 0 14px;">Xin chào <b>{{ $user->fullName }}</b>,</p>
    <p style="margin:0 0 22px;">Cảm ơn bạn đã đăng ký tài khoản tại {{ config('site.name', 'Travel') }}. Bấm nút bên dưới để
        kích hoạt tài khoản.</p>
    <p style="margin:0 0 24px;">
        <a href="{{ $url }}"
            style="display:inline-block;background:#F4C95D;color:#0F172A;text-decoration:none;font-weight:700;padding:13px 28px;border-radius:10px;">Kích
            hoạt tài khoản</a>
    </p>
    <p style="margin:0 0 6px;font-size:13px;color:#64748B;">Nút không bấm được? Dán liên kết này vào trình duyệt:</p>
    <p style="margin:0 0 10px;font-size:12px;word-break:break-all;"><a href="{{ $url }}"
            style="color:#0D9488;">{{ $url }}</a></p>
@endsection
@section('footnote')
    <p style="margin:0;">Liên kết có hiệu lực trong {{ $minutes }} phút và chỉ dùng được một lần. Nếu bạn không đăng ký
        tài khoản này, hãy bỏ qua email.</p>
@endsection
