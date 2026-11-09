<!doctype html><html lang="vi"><body style="font-family:Arial,sans-serif;color:#34443E;line-height:1.7;max-width:640px;margin:auto">
<h2>Travela — thông báo về chuyến đi của bạn</h2>
<p>Xin chào,</p>
<p>Về đơn <strong>{{ $bookingCode }}</strong> — {{ $tourTitle }}@if(!empty($departureDate)), khởi hành ngày {{ \Carbon\Carbon::parse($departureDate)->format('d/m/Y') }}@endif:</p>
<p style="padding:12px 14px;background:#F4F8F6;border-left:4px solid #426E60;white-space:pre-line">{{ $body }}</p>
<p>Travela sẽ liên hệ với bạn nếu cần bạn xác nhận lựa chọn thay thế hoặc khi có thay đổi về quyền lợi, thanh toán. Bạn có thể xem đơn tại đây:</p>
<p><a href="{{ $url }}" style="padding:12px 20px;background:#426E60;color:white;text-decoration:none">Xem đơn của tôi</a></p>
<p>Trân trọng,<br>Travela</p>
</body></html>
