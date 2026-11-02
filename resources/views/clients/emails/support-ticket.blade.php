@extends('clients.emails.layout')
@section('title', 'Yêu cầu tư vấn ' . $ticket->code)
@section('content')
    <p style="margin:0 0 14px;">Xin chào <b>{{ $name }}</b>,</p>
    @if ($event === 'created')
        <p style="margin:0 0 14px;">Travela đã nhận yêu cầu tư vấn <b>{{ $ticket->code }}</b> – “{{ $ticket->subject }}”. Nhân viên sẽ phản hồi sớm nhất có thể (thường trong giờ hành chính).</p>
    @else
        <p style="margin:0 0 10px;">Nhân viên Travela vừa trả lời yêu cầu <b>{{ $ticket->code }}</b>:</p>
        <div style="margin:0 0 16px;padding:14px 16px;background:#F0FDFA;border:1px solid #99F6E4;border-radius:10px;white-space:pre-wrap;">{{ $body }}</div>
    @endif
    <p style="margin:0 0 18px;"><a href="{{ route('support.show', ['code' => $ticket->code]) }}" style="display:inline-block;padding:10px 18px;background:#0D9488;color:#fff;border-radius:8px;text-decoration:none;font-weight:700;">Xem và trả lời</a></p>
@endsection
@section('footnote')
    <p style="margin:0;">Bạn nhận email này vì đã gửi yêu cầu tư vấn trên {{ config('site.name', 'Travela') }}.</p>
@endsection
