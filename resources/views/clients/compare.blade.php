@include('clients.blocks.header')
@php $money = fn($v) => number_format((float) $v, 0, ',', '.') . 'đ'; $n = max(1, count($rows)); @endphp
<style>
    .cmp { padding: 130px 0 70px; background: #f6faf9; }
    .cmp-wrap { max-width: 1180px; margin: 0 auto; padding: 0 16px; }
    .cmp h1 { font-size: 28px; color: #0f2f3a; margin: 0 0 6px; }
    .cmp-lead { color: #557080; margin: 0 0 22px; }
    .cmp-scroll { overflow-x: auto; }
    .cmp-grid { display: grid; grid-template-columns: 170px repeat({{ $n }}, minmax(230px, 1fr)); min-width: {{ 170 + $n * 240 }}px; background: #fff; border: 1px solid #e3ecef; border-radius: 18px; overflow: hidden; }
    .cmp-grid > div { padding: 14px 16px; border-bottom: 1px solid #eef3f5; }
    .cmp-k { background: #f8fbfb; color: #557080; font-weight: 600; font-size: 13.5px; }
    .cmp-head img { width: 100%; aspect-ratio: 16/10; object-fit: cover; border-radius: 12px; background: #e2e8f0; }
    .cmp-head h2 { font-size: 16px; margin: 10px 0 4px; line-height: 1.35; } .cmp-head h2 a { color: #0f2f3a; }
    .cmp-best { display: inline-block; margin-top: 4px; padding: 2px 9px; border-radius: 99px; background: #dcfce7; color: #166534; font-size: 12px; font-weight: 700; }
    .cmp-price { font-size: 20px; font-weight: 700; color: #168F99; }
    .cmp ul { margin: 0; padding-left: 18px; font-size: 14px; } .cmp li { margin: 2px 0; }
    .cmp-deps span { display: inline-block; margin: 2px 4px 2px 0; padding: 3px 9px; border-radius: 8px; background: #f0fdfa; border: 1px solid #ccebe7; font-size: 13px; }
    .cmp-act { display: flex; gap: 8px; flex-wrap: wrap; }
    .cmp-btn { display: inline-flex; padding: 9px 16px; border-radius: 10px; background: #168F99; color: #fff; font-weight: 700; text-decoration: none; }
    .cmp-btn:hover { background: #0f766e; color: #fff; } .cmp-btn--ghost { background: #fff; color: #0f2f3a; border: 1px solid #cfdde2; }
    .cmp-empty { background: #fff; border-radius: 18px; padding: 40px; text-align: center; color: #557080; }
</style>
<main class="cmp">
    <div class="cmp-wrap">
        <h1>So sánh tour</h1>
        <p class="cmp-lead">Đặt các tour cạnh nhau để chọn nhanh. Bấm “So sánh” trên thẻ tour để thêm hoặc bỏ (tối đa 3).</p>
        @if (count($rows) < 2)
            <div class="cmp-empty">Hãy chọn ít nhất 2 tour để so sánh. <a href="{{ route('tours') }}">Xem danh sách tour</a></div>
        @else
            <div class="cmp-scroll"><div class="cmp-grid">
                <div class="cmp-k"></div>
                @foreach ($rows as $t)
                    <div class="cmp-head"><img src="{{ \App\Support\TourImage::url($t->cover, 800) }}" alt="">
                        <h2><a href="{{ route('tour-detail', ['id' => $t->tourId]) }}">{{ $t->title }}</a></h2>
                        <small style="color:#6b8592">{{ $t->destination }}</small></div>
                @endforeach
                <div class="cmp-k">Giá người lớn</div>
                @foreach ($rows as $t)<div><span class="cmp-price">{{ $money($t->priceAdult) }}</span>@if ((float) $t->priceAdult === (float) $cheapest)<br><span class="cmp-best">Rẻ nhất</span>@endif</div>@endforeach
                <div class="cmp-k">Giá trẻ em</div>
                @foreach ($rows as $t)<div>{{ $money($t->priceChild) }}</div>@endforeach
                <div class="cmp-k">Thời lượng</div>
                @foreach ($rows as $t)<div>{{ $t->time }}<br><small style="color:#6b8592">≈ {{ $money($t->perDay) }} / ngày</small></div>@endforeach
                <div class="cmp-k">Ngày còn chỗ</div>
                @foreach ($rows as $t)<div class="cmp-deps">@forelse ($t->departures as $d)<span>{{ \Carbon\Carbon::parse($d->departureDate)->format('d/m') }} · còn {{ $d->remaining }}</span>@empty<small>Hết chỗ</small>@endforelse</div>@endforeach
                <div class="cmp-k">Đánh giá</div>
                @foreach ($rows as $t)<div>@if ($t->rating)<b>{{ $t->rating }}</b>/5 · {{ $t->ratingCount }} đánh giá @if ($t->rating == $best && count($rows) > 1)<br><span class="cmp-best">Điểm cao nhất</span>@endif @else<small style="color:#6b8592">Chưa có</small>@endif</div>@endforeach
                <div class="cmp-k">Bao gồm</div>
                @foreach ($rows as $t)<div>@if (count($t->inc))<ul>@foreach ($t->inc as $x)<li>{{ $x }}</li>@endforeach</ul>@else<small style="color:#6b8592">Chưa cập nhật</small>@endif</div>@endforeach
                <div class="cmp-k">Không bao gồm</div>
                @foreach ($rows as $t)<div>@if (count($t->exc))<ul>@foreach ($t->exc as $x)<li>{{ $x }}</li>@endforeach</ul>@else<small style="color:#6b8592">Chưa cập nhật</small>@endif</div>@endforeach
                <div class="cmp-k">Lộ trình</div>
                @foreach ($rows as $t)<div><ul>@foreach ($t->timeline as $i => $x)<li><b>Ngày {{ $i + 1 }}:</b> {{ $x }}</li>@endforeach</ul></div>@endforeach
                <div class="cmp-k"></div>
                @foreach ($rows as $t)<div class="cmp-act"><a class="cmp-btn" href="{{ route('tour-detail', ['id' => $t->tourId]) }}">Xem & đặt</a><button type="button" class="cmp-btn cmp-btn--ghost" data-cmp="{{ $t->tourId }}" data-cmp-title="{{ $t->title }}" onclick="var u=new URL(location.href);u.searchParams.set('ids',(u.searchParams.get('ids')||'').split(',').filter(function(x){return x!=='{{ $t->tourId }}'}).join(','));setTimeout(function(){location.href=u},120)">Bỏ khỏi so sánh</button></div>@endforeach
            </div></div>
        @endif
    </div>
</main>
@include('clients.blocks.footer')
