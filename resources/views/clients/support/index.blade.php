@include('clients.blocks.header')
@include('clients.support._style')
@php
    $topics = \App\Services\SupportTicketService::TOPICS;
    $status = \App\Services\SupportTicketService::STATUS;
@endphp
<main class="sp">
    <div class="sp-wrap">
        <header class="sp-head">
            <h1>Hỗ trợ tư vấn</h1>
            <p>Cần người thật xem giúp lịch trình, ước lượng chi phí hay chọn tour phù hợp? Gửi yêu cầu, nhân viên Travela sẽ trả lời qua email và ngay tại trang này.</p>
        </header>
        @if (session('error'))<div class="sp-alert" role="alert">{{ session('error') }}</div>@endif

        <div class="sp-grid">
            <section class="sp-card" id="tao-yeu-cau" aria-labelledby="sp-new">
                <div class="sp-card__h"><h2 id="sp-new">Gửi yêu cầu mới</h2></div>
                <form class="sp-card__b sp-f" method="POST" action="{{ route('support.store') }}">
                    @csrf
                    <div class="sp-2">
                        <div>
                            <label for="sp-trip">Chuyến đi liên quan</label>
                            <select id="sp-trip" name="tripId">
                                <option value="">— Không gắn chuyến đi —</option>
                                @foreach ($trips as $tr)
                                    <option value="{{ $tr->tripId }}" @selected((int) old('tripId', $preTrip) === (int) $tr->tripId)>{{ $tr->title }}{{ $tr->startDate ? ' · ' . date('d/m', strtotime($tr->startDate)) : '' }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="sp-topic">Chủ đề</label>
                            <select id="sp-topic" name="topic">
                                @foreach ($topics as $k => $v)<option value="{{ $k }}" @selected(old('topic', $preTrip ? 'itinerary' : 'general') === $k)>{{ $v }}</option>@endforeach
                            </select>
                        </div>
                    </div>
                    <div>
                        <label for="sp-subject">Tiêu đề</label>
                        <input id="sp-subject" name="subject" maxlength="160" required value="{{ old('subject') }}" placeholder="Vd: Nhờ góp ý lịch trình Đà Lạt 3 ngày cho gia đình 4 người">
                        @error('subject')<div class="sp-err">{{ $message }}</div>@enderror
                    </div>
                    <div>
                        <label for="sp-body">Bạn cần tư vấn điều gì?</label>
                        <textarea id="sp-body" name="body" maxlength="4000" required placeholder="Mô tả ngắn: số người, ngân sách, điều bạn còn phân vân…">{{ old('body') }}</textarea>
                        @error('body')<div class="sp-err">{{ $message }}</div>@enderror
                    </div>
                    <div>
                        <label for="sp-phone">Số điện thoại (nếu muốn được gọi lại)</label>
                        <input id="sp-phone" name="phone" inputmode="numeric" value="{{ old('phone') }}" placeholder="0901234567">
                        @error('phone')<div class="sp-err">{{ $message }}</div>@enderror
                    </div>
                    <div><button class="sp-btn" type="submit"><i class="fas fa-paper-plane" aria-hidden="true"></i> Gửi yêu cầu</button></div>
                </form>
            </section>

            <section class="sp-card" aria-labelledby="sp-mine">
                <div class="sp-card__h"><h2 id="sp-mine">Yêu cầu của bạn</h2><small>{{ count($tickets) }} yêu cầu</small></div>
                @if (count($tickets))
                    <ul class="sp-list">
                        @foreach ($tickets as $tk)
                            <li><a href="{{ route('support.show', ['code' => $tk->code]) }}">
                                @if ($tk->customerUnread)<span class="sp-dot" title="Có trả lời mới"></span>@endif
                                <span style="min-width:0"><b>{{ $tk->subject }}</b>
                                    <small>{{ $tk->code }} · {{ \Carbon\Carbon::parse($tk->lastMessageAt ?? $tk->createdAt)->format('H:i d/m/Y') }}{{ $tk->tripTitle ? ' · ' . $tk->tripTitle : '' }}</small></span>
                                <span class="sp-badge sp-badge--{{ $tk->status }}">{{ $status[$tk->status][1] ?? $tk->status }}</span>
                            </a></li>
                        @endforeach
                    </ul>
                @else
                    <div class="sp-empty">Bạn chưa gửi yêu cầu tư vấn nào.</div>
                @endif
            </section>
        </div>
    </div>
</main>
@include('clients.blocks.footer')
