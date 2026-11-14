<!doctype html><html lang="vi"><body style="font-family:Arial,sans-serif;color:#34443E;line-height:1.7;max-width:640px;margin:auto">
<h2>Travela báo giá tour riêng cho nhóm bạn</h2>
<p>Xin chào {{ $name }},</p>
<p>Dựa trên lịch bạn đã lên, Travela báo giá trọn gói:</p>
<p style="font-size:20px"><strong>{{ number_format($q['pricePerPerson'], 0, ',', '.') }}đ / người</strong> · tổng {{ number_format($q['totalPrice'], 0, ',', '.') }}đ cho {{ $q['groupSize'] }} người</p>
@if (!empty($q['includes']))<p><strong>Bao gồm:</strong> {{ $q['includes'] }}</p>@endif
@if (!empty($q['message']))<p>{{ $q['message'] }}</p>@endif
<p>Giữ giá tới ngày {{ \Carbon\Carbon::parse($q['validUntil'])->format('d/m/Y') }}.</p>
<p><a href="{{ $url }}" style="padding:12px 20px;background:#426E60;color:white;text-decoration:none">Xem và trả lời báo giá</a></p>
<p>Trân trọng,<br>Travela</p>
</body></html>
