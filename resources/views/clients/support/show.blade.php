@include('clients.blocks.header')
@include('clients.support._style')
@php $status = \App\Services\SupportTicketService::STATUS; @endphp
<main class="sp">
    <div class="sp-wrap" style="max-width:820px">
        <p style="margin:0 0 10px"><a href="{{ route('support.index') }}" style="color:#168F99">← Tất cả yêu cầu</a></p>
        <header class="sp-head">
            <h1>{{ $t->subject }}</h1>
            <p>{{ $t->code }} · <span class="sp-badge sp-badge--{{ $t->status }}" style="margin:0">{{ $status[$t->status][1] ?? $t->status }}</span>
                @if ($trip) · Chuyến đi: <a href="{{ route('planner.edit', ['id' => $trip->tripId]) }}" style="color:#168F99">{{ $trip->title }}</a>@endif</p>
        </header>
        @if (session('error'))<div class="sp-alert" role="alert">{{ session('error') }}</div>@endif

        <section class="sp-card">
            <div class="sp-card__b sp-thread" aria-live="polite">
                @foreach ($messages as $m)
                    <div class="sp-msg {{ $m->authorType === 'customer' ? 'sp-msg--me' : 'sp-msg--staff' }}">
                        <div class="sp-msg__b">{{ $m->body }}</div>
                        <small>{{ $m->authorType === 'customer' ? 'Bạn' : 'Nhân viên Travela' }} · {{ \Carbon\Carbon::parse($m->createdAt)->format('H:i d/m/Y') }}</small>
                    </div>
                @endforeach
            </div>
            @if ($t->status !== 'closed')
                <form method="POST" action="{{ route('support.reply', ['code' => $t->code]) }}" class="sp-card__b sp-f" style="border-top:1px solid #e3ecef">
                    @csrf
                    <label for="sp-reply">Nhắn thêm</label>
                    <textarea id="sp-reply" name="body" required maxlength="4000" style="min-height:90px" placeholder="Bổ sung thông tin hoặc hỏi tiếp…"></textarea>
                    @error('body')<div class="sp-err">{{ $message }}</div>@enderror
                    <div><button class="sp-btn" type="submit">Gửi</button></div>
                </form>
            @else
                <div class="sp-card__b" style="border-top:1px solid #e3ecef;color:#6b8592">Yêu cầu đã đóng. <a href="{{ route('support.index') }}" style="color:#168F99">Gửi yêu cầu mới</a> nếu bạn cần hỗ trợ thêm.</div>
            @endif
        </section>
    </div>
</main>
@include('clients.blocks.footer')
