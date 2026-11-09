<!doctype html><html lang="vi"><body style="font-family:Arial,sans-serif;color:#34443E;line-height:1.7;max-width:640px;margin:auto">
<h2>Travela — khiếu nại {{ $c->code }}</h2>
<p>Xin chào {{ $c->fullName }},</p>
@if($event === 'reply')<p>Travela vừa phản hồi khiếu nại của bạn (đơn {{ $c->bookingCode }}):</p><p style="padding:12px 14px;background:#F4F8F6;border-left:4px solid #426E60;white-space:pre-line">{{ $d['body'] }}</p>
@elseif($event === 'resolved')<p>Khiếu nại của bạn đã được <strong>giải quyết</strong>:</p><p style="padding:12px 14px;background:#F4F8F6;border-left:4px solid #426E60;white-space:pre-line">{{ $d['body'] }}</p>
@elseif($event === 'rejected')<p>Sau khi xem xét, Travela chưa thể chấp nhận khiếu nại với lý do:</p><p style="padding:12px 14px;background:#FFF6D6;border-left:4px solid #E9C46A;white-space:pre-line">{{ $d['body'] }}</p><p>Nếu chưa đồng ý, bạn có thể trả lời tại trang khiếu nại để được xem xét lại.</p>
@else<p>Travela đã chi trả bồi hoàn <strong>{{ number_format($d['amount'], 0, ',', '.') }} đ</strong> cho khiếu nại của bạn.</p>@endif
<p><a href="{{ $url }}" style="padding:12px 20px;background:#426E60;color:white;text-decoration:none">Xem khiếu nại</a></p>
<p>Trân trọng,<br>Travela</p>
</body></html>
