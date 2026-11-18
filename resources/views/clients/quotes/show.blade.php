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
                        <form method="POST" action="{{ route('quotes.decide', $q->quoteId) }}" style="display:flex;gap:8px">@csrf<input type="hidden" name="_idem" value="{{ 'qd-' . \Illuminate\Support\Str::uuid() }}">
                            <button class="theme-btn" name="decision" value="accept">Nhận báo giá</button>
                            <button class="theme-btn style-two" name="decision" value="decline">Từ chối</button></form>
                    @else
                        <p><b>{{ $q->status === 'accepted' ? 'Bạn đã nhận báo giá. Nhân viên Travela sẽ liên hệ để chốt dịch vụ và hướng dẫn thanh toán.' : 'Bạn đã từ chối báo giá này.' }}</b></p>
                    @endif
                @endif
            </div>
        </section>
    </div>
    @if ($chat)
        <section class="sp-card" id="thread" style="margin-top:16px">
            <div class="sp-card__h"><h2>Trao đổi với Travela</h2></div>
            <div class="sp-card__b">
                <p class="tp-muted" style="margin-top:0">Hỏi thêm, nhờ đổi ngày, bớt điểm, đổi khách sạn… Nhân viên trả lời và gửi báo giá mới ngay tại đây.</p>
                <ol class="qt-thread">
                    @foreach ($thread as $m)
                        <li class="qt-msg qt-msg--{{ $m->sender }}"><b>{{ $m->sender === 'customer' ? 'Bạn' : $m->who }}</b><span>{{ $m->body }}</span><time>{{ \Carbon\Carbon::parse($m->createdAt)->format('H:i d/m') }}</time></li>
                    @endforeach
                </ol>
                @if (in_array($q->status, ['open', 'quoted', 'accepted'], true))
                    <form method="POST" action="{{ route('quotes.message', $q->quoteId) }}" class="qt-form">@csrf
                        <label for="qtBody" class="sr-only">Tin nhắn</label>
                        <textarea id="qtBody" name="body" rows="3" maxlength="1000" required placeholder="Ví dụ: Ngày 2 bỏ Langbiang được không? Đổi sang khách sạn gần chợ."></textarea>
                        <button class="theme-btn">Gửi</button>
                    </form>
                @endif
            </div>
        </section>
        <style>
            .qt-thread { list-style: none; margin: 0 0 12px; padding: 0; display: grid; gap: 8px; }
            .qt-msg { max-width: 80%; padding: 8px 12px; border-radius: 14px; background: #f2f7f6; display: grid; gap: 2px; }
            .qt-msg b { font-size: 12px; color: #35575c; } .qt-msg time { font-size: 11px; color: #6b7c7a; }
            .qt-msg--customer { justify-self: end; background: #e6f4f1; }
            .qt-msg--system { justify-self: center; max-width: 100%; background: #fff8e8; text-align: center; font-size: 14px; }
            .qt-form { display: grid; gap: 8px; } .qt-form textarea { width: 100%; border: 1px solid #c9dcd8; border-radius: 10px; padding: 8px; font: inherit; }
            .qt-form .theme-btn { justify-self: end; }
        </style>
    @endif
</div></main>
@include('clients.blocks.footer')
