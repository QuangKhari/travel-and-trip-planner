<!doctype html><html lang="vi"><body style="font-family:Arial,sans-serif;color:#34443E;line-height:1.7;max-width:640px;margin:auto">
<h2>Travela — cập nhật chuyến đi</h2>
<p>Xin chào,</p><p>Đơn <strong>{{ $bookingCode }}</strong> — {{ $tourTitle }}.</p>
@if($event === 'proposal')
<p>Travela có một đề xuất điều chỉnh cần bạn xem xét trước <strong>{{ $deadline }}</strong>.</p>
<p><strong>Nội dung:</strong> {{ $description }}</p>
<p><strong>Thay đổi:</strong> {{ $changeSummary }}</p>
<p><strong>Cam kết:</strong> {{ $serviceGuarantee }}</p>
<p><a href="{{ $url }}" style="padding:12px 20px;background:#426E60;color:white;text-decoration:none">Đăng nhập để phản hồi</a></p>
<p>Không phản hồi không được xem là đồng ý. Vui lòng liên hệ Travela nếu cần phương án khác.</p>
@elseif($event === 'manual_review')
<p>Travela đã tiếp nhận đề xuất cần xử lý thủ công với bộ phận điều hành. Booking chưa bị hủy và chưa có khoản hoàn tiền nào tự động phát sinh. Chúng tôi sẽ liên hệ để thực hiện đúng phương án đã thống nhất.</p>
@elseif($event === 'refund_pending')
<p>Travela đã hủy lịch khởi hành và tạo yêu cầu hoàn tiền. Khoản hoàn đang CHỜ XỬ LÝ, chưa được xác nhận đã chuyển đến bạn. Bạn có thể liên hệ hỗ trợ với mã đơn hàng trên để được thông tin tiến độ.</p>
@elseif($event === 'declined_refund')
<p>Vì bạn không đồng ý (hoặc không phản hồi) đề xuất điều chỉnh, Travela đã hủy đơn này. Nếu bạn đã thanh toán, khoản <strong>hoàn 100%</strong> đang CHỜ XỬ LÝ — chưa được xác nhận đã chuyển đến bạn. Bạn sẽ nhận email khi Travela đã hoàn tiền.</p>
@elseif($event === 'applied')
<p>Phương án đã được Admin áp dụng theo xác nhận hợp lệ. Xem lịch khởi hành mới và thông tin cập nhật trong tài khoản: <a href="{{ $url }}">Xem thông tin</a>.</p>
@else
<p>Travela đã ghi nhận ý kiến phản hồi. Xem chi tiết tại <a href="{{ $url }}">trang quản lý đề xuất</a>.</p>
@endif
<p>Trân trọng,<br>Travela</p>
</body></html>
