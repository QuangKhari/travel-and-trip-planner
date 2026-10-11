@extends('clients.emails.layout')
@section('title', 'Hóa đơn đặt tour')
@section('content')
    <p style="margin:0 0 4px;font-size:13px;color:#64748B;">Mã đơn</p>
    <p style="margin:0 0 18px;font-size:20px;font-weight:700;">{{ $invoice_booking->bookingCode }}</p>

    <p style="margin:0 0 14px;">Xin chào <b>{{ $invoice_booking->fullName }}</b>, cảm ơn bạn đã đặt tour cùng
        {{ config('site.name', 'Travel') }}. Thông tin đơn của bạn:</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
        style="border-collapse:collapse;font-size:14px;margin-bottom:8px;">
        <tr>
            <td style="padding:10px 0;border-bottom:1px solid #E2E8F0;color:#64748B;width:42%;">Tour</td>
            <td style="padding:10px 0;border-bottom:1px solid #E2E8F0;font-weight:700;">{{ $invoice_booking->title }}</td>
        </tr>
        <tr>
            <td style="padding:10px 0;border-bottom:1px solid #E2E8F0;color:#64748B;">Khởi hành</td>
            <td style="padding:10px 0;border-bottom:1px solid #E2E8F0;">
                {{ date('d/m/Y', strtotime($invoice_booking->startDate)) }}</td>
        </tr>
        <tr>
            <td style="padding:10px 0;border-bottom:1px solid #E2E8F0;color:#64748B;">Kết thúc</td>
            <td style="padding:10px 0;border-bottom:1px solid #E2E8F0;">
                {{ date('d/m/Y', strtotime($invoice_booking->endDate)) }}</td>
        </tr>
        <tr>
            <td style="padding:10px 0;border-bottom:1px solid #E2E8F0;color:#64748B;">Người lớn</td>
            <td style="padding:10px 0;border-bottom:1px solid #E2E8F0;">{{ $invoice_booking->numAdults }} ×
                {{ number_format($invoice_booking->priceAdult, 0, ',', '.') }} VNĐ</td>
        </tr>
        <tr>
            <td style="padding:10px 0;border-bottom:1px solid #E2E8F0;color:#64748B;">Trẻ em</td>
            <td style="padding:10px 0;border-bottom:1px solid #E2E8F0;">{{ $invoice_booking->numChildren }} ×
                {{ number_format($invoice_booking->priceChild, 0, ',', '.') }} VNĐ</td>
        </tr>
        <tr>
            <td style="padding:12px 0;border-bottom:1px solid #E2E8F0;font-weight:700;">Tổng thanh toán</td>
            <td style="padding:12px 0;border-bottom:1px solid #E2E8F0;font-weight:700;font-size:17px;color:#0D9488;">
                {{ number_format($invoice_booking->totalPrice, 0, ',', '.') }} VNĐ</td>
        </tr>
        <tr>
            <td style="padding:10px 0;border-bottom:1px solid #E2E8F0;color:#64748B;">Hình thức</td>
            <td style="padding:10px 0;border-bottom:1px solid #E2E8F0;">{{ $invoice_booking->transactionId }}</td>
        </tr>
        <tr>
            <td style="padding:10px 0;color:#64748B;">Trạng thái</td>
            <td style="padding:10px 0;font-weight:700;">
                {{ $invoice_booking->paymentStatus === 'y' ? 'Đã thanh toán' : 'Chưa thanh toán' }}</td>
        </tr>
    </table>
@endsection
@section('footnote')
    <p style="margin:0;">Cần hỗ trợ? Hãy trả lời email này hoặc liên hệ văn phòng {{ config('site.name', 'Travel') }}.</p>
@endsection
