@include('clients.blocks.header')
@include('clients.support._style')
<main class="sp">
    <div class="sp-wrap">
        <header class="sp-head"><h1>{{ $c->subject }}</h1>
            <p>{{ $c->code }} · đơn {{ $c->bookingCode }} · {{ $cats[$c->category] ?? $c->category }} · <span class="sp-badge sp-badge--{{ in_array($c->status, ['resolved', 'closed'], true) ? 'answered' : 'open' }}">{{ $statuses[$c->status] ?? $c->status }}</span></p></header>
        @if (session('error'))<div class="sp-alert" role="alert">{{ session('error') }}</div>@endif
        @if (session('success'))<div class="sp-alert" style="background:#E6F4EA;color:#1E4620" role="status">{{ session('success') }}</div>@endif
        @if ($c->resolution)<section class="sp-card"><div class="sp-card__b"><b>Kết quả xử lý:</b> {{ $c->resolution }}</div></section>@endif
        @if ($c->compensationAmount > 0)<section class="sp-card"><div class="sp-card__b"><b>Bồi hoàn:</b> {{ number_format($c->compensationAmount, 0, ',', '.') }} đ — {{ $c->compensationStatus === 'paid' ? 'đã chi trả' . ($c->compensationPaidAt ? ' ngày ' . date('d/m/Y', strtotime($c->compensationPaidAt)) : '') : 'đang chờ Travela chi trả' }}</div></section>@endif
        <section class="sp-card"><div class="sp-thread">
            @foreach ($msgs as $m)
                <div class="sp-msg {{ $m->authorType === 'customer' ? 'sp-msg--me' : 'sp-msg--staff' }}"><div class="sp-msg__b">{!! nl2br(e($m->body)) !!}</div><small>{{ $m->authorType === 'customer' ? 'Bạn' : 'Travela' }} · {{ date('H:i d/m/Y', strtotime($m->createdAt)) }}</small></div>
            @endforeach
        </div></section>
        @if (!in_array($c->status, ['resolved', 'closed'], true))
            <section class="sp-card"><form class="sp-card__b sp-f" method="POST" action="{{ route('complaint.reply', $c->code) }}">@csrf
                <label for="kn-r">Phản hồi / bổ sung</label><textarea id="kn-r" name="body" rows="4" required minlength="3" maxlength="4000"></textarea>
                <button class="sp-btn" type="submit">Gửi</button></form></section>
        @endif
        <p><a href="{{ route('complaint.index') }}">← Khiếu nại của tôi</a></p>
    </div>
</main>
@include('clients.blocks.footer')
