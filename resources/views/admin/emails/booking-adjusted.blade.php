<!doctype html><html lang="vi"><body style="font-family:Arial,sans-serif;color:#34443E;line-height:1.7;max-width:640px;margin:auto">
<h2>Travela — thay đổi số khách</h2>
<p>Xin chào {{ $b->fullName }},</p>
@php $m = fn ($v) => number_format((float) $v, 0, ',', '.') . ' đ'; @endphp
@if($event === 'adjusted')
<p>Đơn <strong>{{ $b->bookingCode }}</strong> — {{ $b->title }} đã được điều chỉnh số khách:
   {{ $r['people']['old'][0] }} người lớn{{ $r['people']['old'][1] ? ' + ' . $r['people']['old'][1] . ' trẻ em' : '' }} → <strong>{{ $r['people']['new'][0] }} người lớn{{ $r['people']['new'][1] ? ' + ' . $r['people']['new'][1] . ' trẻ em' : '' }}</strong>.</p>
<p>Tổng tiền đơn: {{ $m($r['oldTotal']) }} → <strong>{{ $m($r['newTotal']) }}</strong> (tính theo giá bạn đã đồng ý lúc đặt).</p>
@if(($r['moneyStatus'] ?? '') === 'pending_collect')
<p>Bạn cần thanh toán thêm <strong>{{ $m($r['settleAmount']) }}</strong>. Travela sẽ liên hệ hoặc bạn thanh toán tại văn phòng trước ngày khởi hành.</p>
@elseif(($r['moneyStatus'] ?? '') === 'pending_refund')
<p>Phần dư được hoàn <strong>{{ $m($r['settleAmount']) }}</strong> ({{ $r['refundPercent'] }}% theo chính sách hủy). Khoản hoàn đang CHỜ XỬ LÝ; Travela sẽ email khi đã chuyển tiền.</p>
@endif
@elseif($event === 'collected')
<p>Travela đã ghi nhận bạn thanh toán thêm <strong>{{ $m($r['settleAmount']) }}</strong> cho đơn {{ $b->bookingCode }}. Cảm ơn bạn.</p>
@else
<p>Travela đã hoàn <strong>{{ $m($r['settleAmount']) }}</strong> cho đơn {{ $b->bookingCode }} (phần dư sau khi giảm số khách).</p>
@endif
<p>Trân trọng,<br>Travela</p>
</body></html>
