<!doctype html><html lang="vi"><body style="font-family:Arial,sans-serif;color:#34443E;line-height:1.7;max-width:640px;margin:auto">
<h2>{{ $heading }}</h2>
<p>Xin chào {{ $name }}, đây là bản tin cho chuyến <strong>{{ $trip->title }}</strong>.</p>
@if ($today && $today['first'])
<p><strong>Hôm nay</strong>: {{ $today['count'] }} điểm, {{ $today['start'] }}–{{ $today['end'] }}.
@if ($today['from']) Xuất phát từ {{ $today['from'] }}@if ($today['startLeg']) (≈ {{ $today['startLeg']['min'] }} phút tới điểm đầu)@endif. @endif
Điểm đầu tiên: {{ $today['first']['name'] }} lúc {{ $today['first']['start'] }}.
@if ($today['leave'])<br><strong>Rời đi trước {{ $today['leave'] }}</strong> để kịp chuyến về.@endif
@if ($today['arrive'])<br>Bắt đầu chơi được từ khoảng {{ $today['arrive'] }} (sau giờ tới nơi).@endif
</p>
@endif
@if ($weather)
<p><strong>Thời tiết</strong> (Open-Meteo)</p>
<ul>@foreach ($weather as $w)<li>Ngày {{ $w['day'] }} ({{ \Carbon\Carbon::parse($w['date'])->format('d/m') }}): {{ $w['label'] }}@if ($w['tMax'] !== null), {{ round($w['tMin']) }}–{{ round($w['tMax']) }}°C @endif @if ($w['rain'] !== null), mưa tới {{ $w['rain'] }}%@endif</li>@endforeach</ul>
@endif
@if ($alerts)
<p><strong>Cần xem</strong></p>
<ul>@foreach ($alerts as $a)<li>{{ $a['title'] }}@if ($a['day']) · ngày {{ $a['day'] }}@endif</li>@endforeach</ul>
@endif
@if ($todo)
<p><strong>Đặt chỗ còn thiếu</strong></p>
<ul>@foreach ($todo as $t)<li>{{ $t }}</li>@endforeach</ul>
@endif
@if (!$weather && !$alerts && !$todo && !($today['first'] ?? null))
<p>Mọi thứ đều ổn, chưa có gì cần làm thêm.</p>
@endif
<p><a href="{{ $url }}" style="padding:12px 20px;background:#426E60;color:white;text-decoration:none">Mở chuyến đi</a></p>
<p>Trân trọng,<br>Travela</p>
</body></html>
