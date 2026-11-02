@extends('admin.layout')
@php
    use App\Services\SupportTicketService as S;
    $me = (int) session('adminId');
    $money = fn($v) => number_format((float) $v, 0, ',', '.') . ' đ';
@endphp
@section('content')
    <div class="ad-head">
        <div>
            <a class="ad-back" href="{{ route('admin.support') }}"><i class="fa fa-arrow-left"></i> Yêu cầu tư vấn</a>
            <h1>{{ $t->subject }}</h1>
            <p><span class="ad-badge ad-badge--{{ S::STATUS[$t->status][0] }}">{{ S::STATUS[$t->status][1] }}</span>
                <span class="ad-muted">· {{ $t->code }} · {{ S::TOPICS[$t->topic] ?? $t->topic }} · gửi {{ \Carbon\Carbon::parse($t->createdAt)->format('H:i d/m/Y') }}</span></p>
        </div>
        <div class="ad-actions">
            @if ($t->status !== 'closed' && (int) $t->assignedTo !== $me)
                <form method="POST" action="{{ route('admin.support.status', $t->ticketId) }}">@csrf<input type="hidden" name="action" value="take">
                    <button class="ad-btn ad-btn--dark"><i class="fa fa-hand-paper-o"></i> {{ $t->assignedTo ? 'Nhận lại từ ' . $t->assignee : 'Nhận xử lý' }}</button></form>
            @endif
            @if ($t->status !== 'closed')
                <form method="POST" action="{{ route('admin.support.status', $t->ticketId) }}">@csrf<input type="hidden" name="action" value="close">
                    <button class="ad-btn" data-confirm="Đóng yêu cầu {{ $t->code }}? Khách không nhắn thêm được nữa.">Đóng yêu cầu</button></form>
            @else
                <form method="POST" action="{{ route('admin.support.status', $t->ticketId) }}">@csrf<input type="hidden" name="action" value="reopen">
                    <button class="ad-btn">Mở lại</button></form>
            @endif
        </div>
    </div>

    <div class="ad-grid ad-grid--side">
        <section class="ad-card">
            <div class="ad-card__head"><h2>Trao đổi</h2><small>{{ count($messages) }} tin</small></div>
            <div class="ad-card__body">
                <ul class="ad-timeline">
                    @foreach ($messages as $m)
                        <li style="--c:{{ $m->authorType === 'staff' ? 'var(--ad-primary)' : '#f59e0b' }}">
                            <div class="ad-timeline__t">{{ $m->authorType === 'staff' ? ($m->staffName ?? 'Nhân viên') . ' (Travela)' : $t->fullName }}</div>
                            <div class="ad-timeline__m">{{ \Carbon\Carbon::parse($m->createdAt)->format('H:i d/m/Y') }}</div>
                            <div class="ad-bubble {{ $m->authorType === 'staff' ? 'ad-bubble--staff' : '' }}">{{ $m->body }}</div>
                        </li>
                    @endforeach
                </ul>
            </div>
            @if ($t->status !== 'closed')
                <form method="POST" action="{{ route('admin.support.reply', $t->ticketId) }}" class="ad-card__foot" style="display:block">
                    @csrf
                    <div class="ad-fld">
                        <label for="sp-body">Trả lời khách</label>
                        <textarea id="sp-body" name="body" required maxlength="4000" rows="5" placeholder="Chào anh/chị {{ $t->fullName }}, …">{{ old('body') }}</textarea>
                        <span class="ad-hint">Khách nhận email kèm nội dung này và đọc được ở trang “Hỗ trợ tư vấn”.</span>
                    </div>
                    <div style="display:flex;gap:10px;justify-content:flex-end;align-items:center;margin-top:12px;flex-wrap:wrap">
                        <label class="ad-check"><input type="checkbox" name="close" value="1"> Trả lời và đóng yêu cầu</label>
                        <button class="ad-btn ad-btn--primary" type="submit"><i class="fa fa-paper-plane"></i> Gửi trả lời</button>
                    </div>
                </form>
            @endif
        </section>

        <aside>
            <section class="ad-card">
                <div class="ad-card__head"><h2>Khách hàng</h2></div>
                <div class="ad-card__body">
                    <div class="ad-person" style="margin-bottom:12px">@if (in_array(session('adminRole'), ['admin', 'manager'], true))<a href="{{ route('admin.users.show', $t->userId) }}" class="ad-btn ad-btn--sm" style="order:3;margin-left:auto">Hồ sơ</a>@endif<img src="{{ \App\Support\Avatar::url($t->avatar ?? null) }}" alt=""><span><b>{{ $t->fullName }}</b><small>{{ $t->email }}</small></span></div>
                    <dl class="ad-kv ad-kv--tight">
                        <dt>Gọi lại</dt><dd>{{ $t->contactPhone ?: ($t->phoneNumber ?: '—') }}</dd>
                        <dt>Đơn đang có</dt><dd>{{ $bookings }} đơn</dd>
                        <dt>Phụ trách</dt><dd>{{ $t->assignee ?? 'Chưa ai nhận' }}</dd>
                    </dl>
                </div>
            </section>
            <section class="ad-card">
                <div class="ad-card__head"><h2>Chuyến đi</h2></div>
                <div class="ad-card__body">
                    @if ($t->tripId && $t->tripTitle)
                        <dl class="ad-kv ad-kv--tight">
                            <dt>Tên</dt><dd><a href="{{ route('admin.trips.show', $t->tripId) }}"><b>{{ $t->tripTitle }}</b></a></dd>
                            <dt>Điểm đến</dt><dd>{{ $dest ?? '—' }}</dd>
                            <dt>Ngày đi</dt><dd>{{ $t->tripStart ? \Carbon\Carbon::parse($t->tripStart)->format('d/m/Y') : 'chưa chọn' }}{{ $t->tripDays ? ' · ' . $t->tripDays . ' ngày' : '' }}</dd>
                            <dt>Số người</dt><dd>{{ $t->groupSize ?? '—' }}</dd>
                            <dt>Ngân sách</dt><dd>{{ $t->budget ? $money($t->budget) : '—' }}</dd>
                            <dt>Số mục</dt><dd>{{ $items }} địa điểm / hoạt động</dd>
                        </dl>
                        <p class="ad-note" style="margin:12px 0 0">Chuyến đi là dữ liệu riêng của khách; nhân viên chỉ xem để tư vấn, mọi thay đổi do khách tự thực hiện.</p>
                    @else
                        <p class="ad-note" style="margin:0">Yêu cầu không gắn với chuyến đi nào.</p>
                    @endif
                </div>
            </section>
        </aside>
    </div>
@endsection
