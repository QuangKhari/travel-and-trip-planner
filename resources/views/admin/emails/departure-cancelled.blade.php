<!doctype html><html lang="vi"><body style="font-family:Arial,sans-serif;color:#34443E;line-height:1.7;max-width:640px;margin:auto">
<h2>Travela — khởi hành bị hủy</h2>
<p>Xin chào,</p>
<p>Rất tiếc, chuyến <strong>{{ $tourTitle }}</strong> khởi hành ngày {{ \Carbon\Carbon::parse($departureDate)->format('d/m/Y') }} (đơn <strong>{{ $bookingCode }}</strong>) <strong>bị Travela hủy do không đủ số khách tối thiểu</strong>. Đây không phải lỗi của bạn.</p>
@if($refundAmount !== null && $refundAmount > 0)
<p>Bạn được <strong>hoàn {{ $refundPercent }}% = {{ number_format($refundAmount, 0, ',', '.') }} đ</strong>. Khoản hoàn đang CHỜ XỬ LÝ, chưa được xác nhận đã chuyển đến bạn; Travela sẽ gửi email khi đã hoàn. Bạn cũng theo dõi được trong mục đơn của mình.</p>
@else
<p>Đơn này chưa thanh toán nên không có khoản nào cần hoàn. Chỗ giữ của bạn đã được hủy.</p>
@endif
<p><a href="{{ $url }}" style="padding:12px 20px;background:#426E60;color:white;text-decoration:none">Xem đơn của tôi</a></p>
<p>Bạn có thể xem các tour khác cùng điểm đến trên Travela bất cứ lúc nào. Xin lỗi vì sự bất tiện này.</p>
<p>Trân trọng,<br>Travela</p>
</body></html>
