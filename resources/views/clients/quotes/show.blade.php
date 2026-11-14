@include('clients.blocks.header')
@include('clients.support._style')
@php $hm = fn($m) => $m === null ? '' : sprintf('%02d:%02d', intdiv($m, 60), $m % 60); @endphp
<main class="sp"><div class="sp-wrap">
    <header class="sp-head"><h1>Báo giá tour riêng #{{ $q->quoteId }}</h1>
        <p><a href="{{ route('planner.edit', ['id' => $q->tripId]) }}">← Về chuyến đi</a> · <a href="{{ route('quotes.index') }}">Mọi báo giá</a></p></header>
    @if (session('success'))<div class="sp-alert" role="status" style="background:#e6f4f1;color:#135D65">{{ session('success') }}</div>@endif
    @if (session('error'))<div class="sp-alert" role="alert">{{ session('error') }}</div>@endif
    <div class="sp-grid">
        <section class="sp-card">
            <div class="sp-card__h"><h2>Lịch bạn gửi: {{ $snap['title'] ?? '' }}</h2></div>
            <div class="sp-card__b">
                @foreach ($snap['plan'] ?? [] as $d)
                    <p style="margin:0 0 4px"><b>Ngày {{ $d['day'] }}</b>@if ($d['date']) · {{ \Carbon\Carbon::parse($d['date'])->format('d/m') }}@endif</p>
                    <ul style="margin:0 0 10px">@forelse ($d['items'] as $i)<li>{{ $i['start'] !== null ? $hm($i['start']) . ' · ' : '' }}{{ $i['name'] }}</li>@empty<li>trống</li>@endforelse</ul>
                @endforeach
                @if ($q->note)<p><b>Bạn ghi thêm:</b> {{ $q->note }}</p>@endif
            </div>
        </section>
        <section class="sp-card">
            <div class="sp-card__h"><h2>{{ $q->status === 'open' ? 'Đang chờ Travela báo giá' : 'Báo giá cho nhóm ' . $q->groupSize . ' người' }}</h2></div>
            <div class="sp-card__b">
                @if ($q->status === 'open')
                    <p>Nhân viên Travela đang xem lịch của bạn. Khi có báo giá, bạn nhận email và thông báo ở chuông của chuyến.</p>
                @else
                    <p style="font-size:22px;margin:0"><b>{{ number_format($q->pricePerPerson, 0, ',', '.') }}đ</b> / người</p>
                    <p>Tổng {{ number_format($q->totalPrice, 0, ',', '.') }}đ · giữ giá tới {{ \Carbon\Carbon::parse($q->validUntil)->format('d/m/Y') }}</p>
                    @if ($q->includes)<p><b>Bao gồm:</b> {{ $q->includes }}</p>@endif
                    @if ($q->excludes)<p><b>Không bao gồm:</b> {{ $q->excludes }}</p>@endif
                    @if ($q->message)<p>{{ $q->message }}</p>@endif
                    @if ($q->status === 'quoted')
                        <form method="POST" action="{{ route('quotes.decide', $q->quoteId) }}" style="display:flex;gap:8px">@csrf
                            <button class="theme-btn" name="decision" value="accept">Nhận báo giá</button>
                            <button class="theme-btn style-two" name="decision" value="decline">Từ chối</button></form>
                    @else
                        <p><b>{{ $q->status === 'accepted' ? 'Bạn đã nhận báo giá. Nhân viên Travela sẽ liên hệ để chốt dịch vụ và hướng dẫn thanh toán.' : 'Bạn đã từ chối báo giá này.' }}</b></p>
                    @endif
                @endif
            </div>
        </section>
    </div>
</div></main>
@include('clients.blocks.footer')
