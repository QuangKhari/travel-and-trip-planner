@include('clients.blocks.header')
@include('clients.support._style')
<main class="sp">
    <div class="sp-wrap">
        <header class="sp-head"><h1>Khiếu nại sau chuyến đi</h1>
            <p>Dịch vụ không đúng mô tả hoặc có sự cố trong chuyến đi? Gửi khiếu nại trong {{ \App\Services\ComplaintService::windowDays() }} ngày sau khi kết thúc chuyến. Travela phản hồi qua email và tại trang này.</p></header>
        @if (session('error'))<div class="sp-alert" role="alert">{{ session('error') }}</div>@endif
        @if (session('success'))<div class="sp-alert" style="background:#E6F4EA;color:#1E4620" role="status">{{ session('success') }}</div>@endif
        @if (!$ready)
            <div class="sp-empty">Tính năng chưa được kích hoạt.</div>
        @else
            @if ($booking)
                <section class="sp-card" aria-labelledby="kn-new"><div class="sp-card__h"><h2 id="kn-new">Khiếu nại đơn {{ $booking->bookingCode }} — {{ $booking->title }}</h2></div>
                    @if ($el['can'])
                        <form class="sp-card__b sp-f" method="POST" action="{{ route('complaint.store') }}">@csrf
                            <input type="hidden" name="bookingId" value="{{ $booking->bookingId }}">
                            @if ($errors->any())<div class="sp-err">{{ $errors->first() }}</div>@endif
                            <div class="sp-2">
                                <div><label for="kn-c">Vấn đề thuộc về</label><select id="kn-c" name="category">@foreach ($cats as $k => $l)<option value="{{ $k }}" @selected(old('category') === $k)>{{ $l }}</option>@endforeach</select></div>
                                <div><label for="kn-m">Số tiền đề nghị bồi hoàn (đ, nếu có)</label><input id="kn-m" type="number" name="claimedAmount" min="0" value="{{ old('claimedAmount') }}"></div>
                            </div>
                            <div><label for="kn-s">Tiêu đề</label><input id="kn-s" name="subject" required minlength="5" maxlength="160" value="{{ old('subject') }}"></div>
                            <div><label for="kn-b">Mô tả chi tiết</label><textarea id="kn-b" name="body" rows="6" required minlength="20" maxlength="4000">{{ old('body') }}</textarea></div>
                            <button class="sp-btn" type="submit">Gửi khiếu nại</button>
                        </form>
                    @else
                        <div class="sp-card__b">{{ $el['why'] }}</div>
                    @endif
                </section>
            @endif
            <section class="sp-card" aria-labelledby="kn-my"><div class="sp-card__h"><h2 id="kn-my">Khiếu nại của bạn</h2></div>
                @if ($rows->count())
                    <ul class="sp-list">
                        @foreach ($rows as $r)
                            <li><a href="{{ route('complaint.show', $r->code) }}" style="display:flex;justify-content:space-between;gap:12px;padding:12px 16px;text-decoration:none;color:inherit">
                                <span>@if ($r->customerUnread)<span class="sp-dot"></span>@endif<b>{{ $r->subject }}</b><br><small>{{ $r->code }} · đơn {{ $r->bookingCode }} · {{ date('d/m/Y', strtotime($r->createdAt)) }}</small></span>
                                <span class="sp-badge sp-badge--{{ in_array($r->status, ['resolved', 'closed'], true) ? 'answered' : 'open' }}">{{ $statuses[$r->status] ?? $r->status }}</span></a></li>
                        @endforeach
                    </ul>
                @else
                    <div class="sp-empty">Bạn chưa gửi khiếu nại nào. Mở “Tour đã đặt” của chuyến đã kết thúc để gửi khiếu nại.</div>
                @endif
            </section>
        @endif
    </div>
</main>
@include('clients.blocks.footer')
