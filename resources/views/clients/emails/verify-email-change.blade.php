@extends('clients.emails.layout')
@section('title', 'Xác minh email mới')
@section('content')
    <p style="margin:0 0 14px;">Xin chào <b>{{ $user->fullName }}</b>,</p>
    <p style="margin:0 0 14px;">Bạn vừa yêu cầu đổi email tài khoản {{ config('site.name', 'Travel') }} sang:</p>
    <p style="margin:0 0 22px;padding:12px 16px;background:#F3FAF9;border-radius:10px;"><b>{{ $newEmail }}</b></p>
    <p style="margin:0 0 24px;">
        <a href="{{ $url }}"
            style="display:inline-block;background:#FF7A00;color:#ffffff;text-decoration:none;font-weight:700;padding:13px 28px;border-radius:10px;">Xác
            minh email mới</a>
    </p>
    <p style="margin:0 0 6px;font-size:13px;color:#6B7F8F;">Nút không bấm được? Dán liên kết này vào trình duyệt:</p>
    <p style="margin:0 0 10px;font-size:12px;word-break:break-all;"><a href="{{ $url }}"
            style="color:#0E8F8A;">{{ $url }}</a></p>
@endsection
@section('footnote')
    <p style="margin:0;">Liên kết có hiệu lực trong {{ $minutes }} phút và chỉ dùng được một lần. Nếu bạn không thực
        hiện yêu cầu này, hãy bỏ qua email; email hiện tại của tài khoản được giữ nguyên.</p>
@endsection
