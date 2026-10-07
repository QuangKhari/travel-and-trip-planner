<!doctype html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Hóa đơn Travela</title>
</head>
<body>
    <h2>Hóa đơn đặt tour Travela</h2>
    <p>Khách hàng: {{ $invoice_booking->fullName }}</p>
    <p>Email: {{ $invoice_booking->email }}</p>
    <p>Tour: {{ $invoice_booking->title }}</p>
    <p>Mã đơn: {{ $invoice_booking->bookingCode }}</p>
    <p>Tổng tiền: {{ number_format($invoice_booking->totalPrice) }} VNĐ</p>
    <p>Phương thức: {{ $invoice_booking->paymentMethod }}</p>
    <p>Mã giao dịch: {{ $invoice_booking->transactionId }}</p>
</body>
</html>