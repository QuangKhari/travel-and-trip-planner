<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="utf-8">
    <title>Hóa đơn đặt tour</title>
</head>

<body style="font-family: Arial, Helvetica, sans-serif; color:#222; line-height:1.5;">
    <h2 style="margin-bottom:4px;">Travela - Hóa đơn đặt tour</h2>
    <p style="margin-top:0;color:#666;">Mã đơn: <strong>{{ $invoice_booking->bookingCode }}</strong></p>

    <p>Xin chào {{ $invoice_booking->fullName }},</p>
    <p>Cảm ơn bạn đã đặt tour cùng Travela. Dưới đây là thông tin đơn của bạn:</p>

    <table cellpadding="8" cellspacing="0" border="1"
        style="border-collapse:collapse; border-color:#ddd; width:100%; max-width:560px;">
        <tr>
            <td width="40%">Tour</td>
            <td>{{ $invoice_booking->title }}</td>
        </tr>
        <tr>
            <td>Ngày khởi hành</td>
            <td>{{ date('d/m/Y', strtotime($invoice_booking->startDate)) }}</td>
        </tr>
        <tr>
            <td>Ngày kết thúc</td>
            <td>{{ date('d/m/Y', strtotime($invoice_booking->endDate)) }}</td>
        </tr>
        <tr>
            <td>Người lớn</td>
            <td>{{ $invoice_booking->numAdults }} x {{ number_format($invoice_booking->priceAdult, 0, ',', '.') }} VNĐ
            </td>
        </tr>
        <tr>
            <td>Trẻ em</td>
            <td>{{ $invoice_booking->numChildren }} x {{ number_format($invoice_booking->priceChild, 0, ',', '.') }} VNĐ
            </td>
        </tr>
        <tr>
            <td><strong>Tổng thanh toán</strong></td>
            <td><strong>{{ number_format($invoice_booking->totalPrice, 0, ',', '.') }} VNĐ</strong></td>
        </tr>
        <tr>
            <td>Hình thức thanh toán</td>
            <td>{{ $invoice_booking->transactionId }}</td>
        </tr>
        <tr>
            <td>Trạng thái thanh toán</td>
            <td>{{ $invoice_booking->paymentStatus === 'y' ? 'Đã thanh toán' : 'Chưa thanh toán' }}</td>
        </tr>
    </table>

    <p style="margin-top:20px;">Nếu cần hỗ trợ, vui lòng trả lời email này hoặc liên hệ văn phòng Travela.</p>
</body>

</html>
