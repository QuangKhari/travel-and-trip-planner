<!doctype html><html lang="vi"><body style="font-family:Arial,sans-serif;color:#34443E;line-height:1.7;max-width:640px;margin:auto">
<h2>Travela — danh sách chờ</h2>
<p>Xin chào {{ $name }},</p>
@if($event === 'offer')
<p>Đã có chỗ cho <strong>{{ $people }} khách</strong> trên chuyến <strong>{{ $tourTitle }}</strong>, khởi hành {{ \Carbon\Carbon::parse($departureDate)->format('d/m/Y') }}.</p>
<p>Travela đang <strong>giữ chỗ cho bạn đến {{ \Carbon\Carbon::parse($expires)->format('H:i d/m/Y') }}</strong> (khoảng {{ $hours }} giờ). Sau thời hạn này chỗ sẽ được mời cho người kế tiếp.</p>
<p><a href="{{ $url }}" style="padding:12px 20px;background:#426E60;color:white;text-decoration:none">Xác nhận đặt tour</a></p>
@else
<p>Chuyến <strong>{{ $tourTitle }}</strong> khởi hành {{ $departureDate ? \Carbon\Carbon::parse($departureDate)->format('d/m/Y') : '' }} mà bạn đang chờ không còn mở nhận khách. Bạn không bị tính phí nào.</p>
<p><a href="{{ $url }}" style="padding:12px 20px;background:#426E60;color:white;text-decoration:none">Xem các lựa chọn khác</a></p>
@endif
<p>Trân trọng,<br>Travela</p>
</body></html>
