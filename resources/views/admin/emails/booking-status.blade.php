@extends('clients.emails.layout')
@section('title', 'Cập nhật đơn ' . $b->bookingCode)
@section('content')
    @php $money = fn($v) => number_format((float) $v, 0, ',', '.') . ' đ'; @endphp
    <p style="margin:0 0 4px;font-size:13px;color:#64748B;">Mã đơn</p>
    <p style="margin:0 0 18px;font-size:20px;font-weight:700;">{{ $b->bookingCode }}</p>
    <p style="margin:0 0 14px;">Xin chào <b>{{ $b->fullName }}</b>,</p>
    @switch($event)
        @case('created')
            <p style="margin:0 0 14px;">Travela đã tạo đơn đặt tour <b>{{ $b->title }}</b> cho bạn ({{ (int) $b->numAdults + (int) $b->numChildren }} khách, tổng {{ $money($b->totalPrice) }}). Vui lòng thanh toán tại văn phòng{{ !empty($b->holdExpiresAt) ? ' trước ' . date('H:i d/m/Y', strtotime($b->holdExpiresAt)) : '' }} để giữ chỗ.</p>
            @break
        @case('confirmed')
            <p style="margin:0 0 14px;">Đơn đặt tour <b>{{ $b->title }}</b> của bạn đã được <b style="color:#0D9488">xác nhận</b>. Chúng tôi giữ chỗ cho chuyến khởi hành {{ !empty($b->tripStart ?? $b->departureDate) ? date('d/m/Y', strtotime($b->tripStart ?? $b->departureDate)) : '' }}.</p>
            @break
        @case('paid')
            <p style="margin:0 0 14px;">Travela đã nhận đủ thanh toán <b>{{ $money($b->totalPrice) }}</b> cho đơn <b>{{ $b->title }}</b>. Cảm ơn bạn!</p>
            @break
        @case('cancelled')
            <p style="margin:0 0 14px;">Đơn đặt tour <b>{{ $b->title }}</b> đã được hủy theo yêu cầu.</p>
            @if ($refund && $refund->amount > 0)
                <p style="margin:0 0 14px;">Số tiền được hoàn: <b>{{ $money($refund->amount) }}</b> ({{ $refund->percent }}% số đã thanh toán). Travela sẽ chuyển lại và báo cho bạn khi hoàn tất.</p>
            @endif
            @break
        @case('reminder')
            <p style="margin:0 0 14px;">Chuyến <b>{{ $b->title }}</b> của bạn khởi hành <b>{{ ($days ?? 0) <= 1 ? 'ngày mai' : 'sau ' . $days . ' ngày' }}</b>, ngày {{ date('d/m/Y', strtotime($b->tripStart ?? $b->departureDate ?? $b->startDate)) }}.</p>
            <ul style="margin:0 0 14px;padding-left:18px;">
                <li>Số khách: {{ (int) $b->numAdults }} người lớn{{ (int) $b->numChildren ? ', ' . (int) $b->numChildren . ' trẻ em' : '' }}</li>
                <li>Mang theo giấy tờ tùy thân của từng người và mã đơn <b>{{ $b->bookingCode }}</b>.</li>
                @if (($b->paymentStatus ?? 'y') !== 'y')
                    <li>Đơn <b>chưa thanh toán đủ</b> — vui lòng hoàn tất trước ngày khởi hành.</li>
                @endif
            </ul>
            @break
        @case('refunded')
            <p style="margin:0 0 14px;">Travela đã hoàn <b>{{ $money($refund->amount) }}</b> cho đơn đã hủy <b>{{ $b->title }}</b>{{ $refund->method ? ' qua ' . mb_strtolower($refund->method) : '' }}{{ $refund->reference ? ' (mã giao dịch ' . $refund->reference . ')' : '' }}.</p>
            @break
    @endswitch
@endsection
@section('footnote')
    <p style="margin:0;">Cần hỗ trợ? Hãy trả lời email này hoặc liên hệ văn phòng {{ config('site.name', 'Travela') }}.</p>
@endsection
