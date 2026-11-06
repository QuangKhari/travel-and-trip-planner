<!doctype html><html lang="vi"><body style="font-family:Arial,sans-serif;color:#34443E;line-height:1.7;max-width:640px;margin:auto">
<h2>Travela — thông tin đoàn khởi hành</h2>
<p>Xin chào,</p>
<p>Đơn <strong>{{ $bookingCode }}</strong> — {{ $tourTitle }}, khởi hành ngày {{ \Carbon\Carbon::parse($departureDate)->format('d/m/Y') }}.</p>
@if($event === 'merged')
<p>Để vận hành chuyến đi hiệu quả, Travela sắp xếp chuyến của bạn chạy <strong>chung đoàn</strong> với một tour khác có cùng ngày khởi hành, cùng điểm đến và thời lượng.</p>
@if($mode === 'titles')
<p>Các ngày của hai tour có cùng tên chương trình. Nếu có điểm khác biệt nhỏ trong nội dung từng ngày, Travela sẽ thông báo trước khi khởi hành.</p>
@else
<p>Lịch trình của hai tour giống nhau hoàn toàn.</p>
@endif
<p><strong>Không có gì thay đổi</strong> về giá, số chỗ, quyền lợi hay thanh toán của đơn bạn đã đặt.</p>
@else
<p>Chuyến của bạn không còn chạy chung đoàn với tour khác. Giá, số chỗ, quyền lợi và thanh toán của đơn không thay đổi.</p>
@endif
<p><a href="{{ $url }}" style="padding:12px 20px;background:#426E60;color:white;text-decoration:none">Xem đơn của tôi</a></p>
<p>Trân trọng,<br>Travela</p>
</body></html>
