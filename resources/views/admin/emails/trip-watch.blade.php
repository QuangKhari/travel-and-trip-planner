<!doctype html><html lang="vi"><body style="font-family:Arial,sans-serif;color:#34443E;line-height:1.7;max-width:640px;margin:auto">
<h2>Travela — chuyến của bạn có thay đổi</h2>
<p>Xin chào {{ $name }},</p>
<p>Travela vừa kiểm tra lại chuyến <strong>{{ $title }}</strong> (khởi hành {{ \Carbon\Carbon::parse($start)->format('d/m/Y') }}) với dự báo thời tiết, giờ mở cửa và cảnh báo điểm đến mới nhất:</p>
<ul>
@foreach ($alerts as $a)
<li style="margin-bottom:8px"><strong style="color:{{ $a['tone'] === 'bad' ? '#B42318' : '#8A5A00' }}">{{ !empty($a['changed']) ? 'Thay đổi: ' : '' }}{{ $a['title'] }}</strong>@if ($a['day']) · ngày {{ $a['day'] }}@endif<br><span style="font-size:14px">{{ $a['text'] }}</span></li>
@endforeach
</ul>
@if ($resolved)
<p style="font-size:14px;color:#2E6B4F">Đã ổn: @foreach ($resolved as $r){{ $r['title'] }}@if (!$loop->last), @endif @endforeach</p>
@endif
<p><a href="{{ $url }}" style="padding:12px 20px;background:#426E60;color:white;text-decoration:none">Xem cách xử lý</a></p>
<p style="font-size:13px;color:#667">Mỗi cách xử lý được Travela kiểm tra lại cả lịch (giờ mở cửa, đường đi, giờ bay) trước khi đề xuất; lịch chỉ đổi khi bạn bấm Áp dụng.</p>
<p>Trân trọng,<br>Travela</p>
</body></html>
