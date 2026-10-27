@include('admin.blocks.header')
<div class="container body">
    <div class="main_container">
        @include('admin.blocks.sidebar')

        <div class="right_col" role="main">
            @include('admin.partials.flash')
            <div class="ad-head">
                <div>
                    <h1>Hộp thư liên hệ</h1>
                    <p>Tin khách gửi từ trang Liên hệ. Tin mới luôn nằm trên cùng; đổi trạng thái và ghi chú nội bộ ngay tại từng tin.</p>
                </div>
            </div>

            <div class="ad-card">
                <div class="ad-toolbar">
                    <form method="GET" class="ad-search">
                        <i class="fa fa-search"></i>
                        <input type="search" name="q" value="{{ $search }}" placeholder="Tên, email, SĐT, nội dung…">
                        @if ($status)<input type="hidden" name="status" value="{{ $status }}">@endif
                    </form>
                    <div class="ad-chips">
                        <a class="ad-chip {{ !$status && $mail !== 'failed' ? 'is-on' : '' }}" href="{{ route('admin.contact') }}">Tất cả <b>{{ $counts->sum() }}</b></a>
                        @foreach ($statuses as $k => $l)
                            <a class="ad-chip {{ $status === $k ? 'is-on' : '' }}" href="{{ route('admin.contact', ['status' => $k]) }}">{{ $l }} <b>{{ $counts[$k] ?? 0 }}</b></a>
                        @endforeach
                        <a class="ad-chip {{ $mail === 'failed' ? 'is-on' : '' }}" href="{{ route('admin.contact', ['mail' => 'failed']) }}" title="Thư báo cho nhân viên chưa gửi được; tin vẫn lưu ở đây"><i class="fa fa-exclamation-triangle"></i> Chưa gửi được mail</a>
                    </div>
                </div>

                @forelse ($rows as $m)
                    <div class="ad-msg {{ $m->status === 'new' ? 'is-new' : '' }}">
                        <div class="ad-msg__top">
                            <b>{{ $m->name }}</b>
                            <span class="ad-note">{{ $m->phone ?: 'Không có SĐT' }} ·
                                <a href="mailto:{{ $m->email }}">{{ $m->email }}</a></span>
                            <span class="ad-badge ad-badge--info">{{ $topics[$m->topic] ?? $m->topic }}</span>
                            @if ($m->bookingCode)<span class="ad-badge ad-badge--mute">Mã đơn {{ $m->bookingCode }}</span>@endif
                            @unless ($m->mailSent)<span class="ad-badge ad-badge--warn" title="Thư báo cho nhân viên chưa gửi được">Chưa gửi mail</span>@endunless
                            <span class="ad-note" style="margin-left:auto">{{ \Illuminate\Support\Carbon::parse($m->createdAt)->format('d/m/Y H:i') }}</span>
                        </div>
                        <div class="ad-msg__body">{{ $m->message }}</div>
                        <form method="POST" action="{{ route('admin.contact.update', $m->messageId) }}" class="ad-msg__form">
                            @csrf
                            <select name="status" class="ad-field">
                                @foreach ($statuses as $k => $l)<option value="{{ $k }}" @selected($m->status === $k)>{{ $l }}</option>@endforeach
                            </select>
                            <input name="adminNote" class="ad-field" maxlength="2000" placeholder="Ghi chú nội bộ (khách không thấy)" value="{{ $m->adminNote }}">
                            <button class="ad-btn ad-btn--primary ad-btn--sm">Lưu</button>
                            <a class="ad-btn ad-btn--sm" href="mailto:{{ $m->email }}?subject={{ rawurlencode('Phản hồi liên hệ từ ' . config('site.name', 'Travela')) }}"><i class="fa fa-reply"></i> Trả lời qua email</a>
                            @if ($m->handledBy)<span class="ad-note">Xử lý: {{ $adminNames[$m->handledBy] ?? '—' }} · {{ \Illuminate\Support\Carbon::parse($m->handledAt)->format('d/m H:i') }}</span>@endif
                        </form>
                        <form method="POST" action="{{ route('admin.contact.destroy', $m->messageId) }}" style="margin-top:8px">
                            @csrf
                            <button class="ad-btn ad-btn--sm ad-btn--danger" data-confirm="Xóa vĩnh viễn tin nhắn này?"><i class="fa fa-trash"></i> Xóa</button>
                        </form>
                    </div>
                @empty
                    <div class="ad-empty"><i class="fa fa-inbox"></i>Chưa có tin nhắn nào{{ $search !== '' ? ' khớp “' . e($search) . '”' : '' }}.</div>
                @endforelse

                @if ($rows->hasPages())
                    <div class="ad-pager"><span class="ad-note">Trang {{ $rows->currentPage() }} / {{ $rows->lastPage() }} · {{ $rows->total() }} tin</span>{{ $rows->links('admin.partials.pager') }}</div>
                @endif
            </div>
        </div>
    </div>
</div>
@include('admin.blocks.footer')
