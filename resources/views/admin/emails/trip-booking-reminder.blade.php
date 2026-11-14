<!doctype html><html lang="vi"><body style="font-family:Arial,sans-serif;color:#34443E;line-height:1.7;max-width:640px;margin:auto">
<h2>Travela — nhắc đặt chỗ</h2>
<p>Xin chào {{ $name }},</p>
<p>Chuyến <strong>{{ $title }}</strong> khởi hành {{ \Carbon\Carbon::parse($start)->format('d/m/Y') }}. Travela không đặt hộ khách sạn hay vé, nên nhắc bạn các việc sau:</p>
@if ($due)
<p><strong>Tới hạn đặt</strong></p>
<ul>@foreach ($due as $x)<li>{{ $x['title'] }} — {{ $x['overdue'] ? 'đã quá hạn' : 'hạn' }} {{ \Carbon\Carbon::parse($x['needBy'])->format('d/m') }}@if (!empty($x['bookUrl'])) · <a href="{{ $x['bookUrl'] }}">mở nơi đặt</a>@endif</li>@endforeach</ul>
@endif
@if ($gaps)
<p><strong>Còn thiếu</strong></p>
<ul>@foreach ($gaps as $n)<li>{{ $n['title'] }}@if (!empty($n['links'][0])) · <a href="{{ $n['links'][0]['url'] }}">tìm trên {{ $n['links'][0]['label'] }}</a>@endif</li>@endforeach</ul>
<p style="font-size:13px;color:#667">Không cần (ví dụ tự đi xe máy)? Bấm “Không cần” ở mục Đặt chỗ cho chuyến để Travela thôi nhắc.</p>
@endif
<p><a href="{{ $url }}" style="padding:12px 20px;background:#426E60;color:white;text-decoration:none">Mở chuyến đi</a></p>
<p>Đặt xong, bạn lưu mã xác nhận vào chuyến để giờ nhận phòng, giờ đi tự vào lịch.</p>
<p>Trân trọng,<br>Travela</p>
</body></html>
