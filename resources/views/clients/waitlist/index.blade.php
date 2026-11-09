@include('clients.blocks.header')
@include('clients.support._style')
<main class="sp">
    <div class="sp-wrap">
        <header class="sp-head">
            <h1>Danh sách chờ</h1>
            <p>Khi ngày khởi hành hết chỗ, bạn đăng ký chờ. Có chỗ trống Travela giữ chỗ cho bạn và gửi email mời xác nhận; không tính phí khi chờ.</p>
        </header>
        @if (session('error'))<div class="sp-alert" role="alert">{{ session('error') }}</div>@endif
        @if (session('success'))<div class="sp-alert" style="background:#E6F4EA;color:#1E4620" role="status">{{ session('success') }}</div>@endif
        @if (!$ready)
            <div class="sp-empty">Tính năng chưa được kích hoạt.</div>
        @else
            @if ($pick)
                <section class="sp-card" aria-labelledby="wl-new">
                    <div class="sp-card__h"><h2 id="wl-new">Đăng ký chờ: {{ $pick->title }}</h2></div>
                    <form class="sp-card__b sp-f" method="POST" action="{{ route('waitlist.join') }}">
                        @csrf
                        <input type="hidden" name="availabilityId" value="{{ $pick->availabilityId }}">
                        <p>Khởi hành {{ date('d/m/Y', strtotime($pick->departureDate)) }} · còn {{ max(0, $pick->totalSlots - $pick->bookedSlots - $pick->heldSlots) }} chỗ.</p>
                        <div class="sp-2">
                            <div><label for="wl-a">Người lớn</label><input id="wl-a" type="number" name="numAdults" min="1" max="50" value="{{ old('numAdults', 1) }}" required></div>
                            <div><label for="wl-c">Trẻ em</label><input id="wl-c" type="number" name="numChildren" min="0" max="50" value="{{ old('numChildren', 0) }}" required></div>
                        </div>
                        <button class="sp-btn" type="submit">Đăng ký chờ</button>
                    </form>
                </section>
            @endif
            <section class="sp-card" aria-labelledby="wl-my">
                <div class="sp-card__h"><h2 id="wl-my">Đăng ký của bạn</h2></div>
                @if ($rows->count())
                    <ul class="sp-list">
                        @foreach ($rows as $r)
                            <li style="padding:12px 16px;display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap">
                                <span><b>{{ $r->title }}</b><br><small>Khởi hành {{ date('d/m/Y', strtotime($r->departureDate)) }} · {{ $r->numAdults }} người lớn{{ $r->numChildren ? ', ' . $r->numChildren . ' trẻ em' : '' }}</small></span>
                                <span>
                                    @if ($r->status === 'waiting')<span class="sp-badge sp-badge--open">Đang chờ · thứ {{ $r->position }}</span>
                                    @elseif ($r->status === 'offered')<a class="sp-btn" href="{{ route('waitlist.offer', $r->offerToken) }}">Xác nhận trước {{ date('H:i d/m', strtotime($r->offerExpiresAt)) }}</a>
                                    @else<span class="sp-badge sp-badge--answered">{{ ['converted' => 'Đã đặt tour', 'expired' => 'Hết hiệu lực', 'cancelled' => 'Đã rút'][$r->status] ?? $r->status }}</span>@endif
                                    @if (in_array($r->status, ['waiting', 'offered'], true))
                                        <form method="POST" action="{{ route('waitlist.cancel', $r->waitId) }}" style="display:inline" onsubmit="return confirm('Rút khỏi danh sách chờ?')">@csrf<button class="sp-btn sp-btn--ghost" type="submit">Rút</button></form>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <div class="sp-empty">Bạn chưa đăng ký chờ ngày khởi hành nào. Vào trang tour, chọn ngày đã hết chỗ để đăng ký chờ.</div>
                @endif
            </section>
        @endif
    </div>
</main>
@include('clients.blocks.footer')
