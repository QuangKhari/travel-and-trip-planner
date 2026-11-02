@extends('admin.layout')
@php
    use App\Services\SupportTicketService as S;
    $tabs = ['active' => 'Cần xử lý', 'open' => 'Chờ nhận', 'in_progress' => 'Đang xử lý', 'answered' => 'Đã trả lời', 'closed' => 'Đã đóng', 'all' => 'Tất cả'];
    $cnt = fn($k) => $k === 'active' ? (int) ($counts['open'] ?? 0) + (int) ($counts['in_progress'] ?? 0) : ($k === 'all' ? (int) collect($counts)->sum() : (int) ($counts[$k] ?? 0));
@endphp
@section('content')
    <div class="ad-head">
        <div>
            <h1>Yêu cầu tư vấn</h1>
            <p>Khách nhờ nhân viên xem giúp chuyến đi, chi phí hay chọn tour. Nhận xử lý để khách biết đã có người phụ trách; câu trả lời được gửi email cho khách và hiện ở trang “Hỗ trợ tư vấn” của họ.</p>
        </div>
    </div>
    <div class="ad-card">
        <form class="ad-toolbar" method="GET" action="{{ route('admin.support') }}">
            <div class="ad-chips">
                @foreach ($tabs as $k => $l)
                    <a class="ad-chip {{ $status === $k ? 'is-on' : '' }}" href="{{ route('admin.support', ['status' => $k] + request()->only('mine', 'q')) }}">{{ $l }} <b>{{ $cnt($k) }}</b></a>
                @endforeach
            </div>
            <span class="ad-grow"></span>
            <input type="hidden" name="status" value="{{ $status }}">
            <label class="ad-check"><input type="checkbox" name="mine" value="1" @checked(request('mine')) onchange="this.form.submit()"> Của tôi</label>
            <div class="ad-search" style="max-width:260px"><i class="fa fa-search"></i><input type="search" name="q" value="{{ request('q') }}" placeholder="Mã, tiêu đề, tên khách"></div>
        </form>
        @if ($rows->count())
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead><tr><th>Yêu cầu</th><th>Khách</th><th>Chuyến đi</th><th>Phụ trách</th><th>Cập nhật</th><th>Trạng thái</th></tr></thead>
                    <tbody>
                    @foreach ($rows as $t)
                        <tr data-href="{{ route('admin.support.show', $t->ticketId) }}" class="{{ $t->status === 'open' ? 'is-hl' : '' }}">
                            <td style="min-width:240px"><a href="{{ route('admin.support.show', $t->ticketId) }}"><b>{{ $t->subject }}</b></a>
                                <span class="ad-sub">{{ $t->code }} · {{ S::TOPICS[$t->topic] ?? $t->topic }}</span></td>
                            <td><div class="ad-person" style="min-width:0"><img src="{{ \App\Support\Avatar::url($t->avatar ?? null) }}" alt=""><span><b>{{ $t->fullName }}</b><small>{{ $t->email }}</small></span></div></td>
                            <td>{{ $t->tripTitle ? \Illuminate\Support\Str::limit($t->tripTitle, 32) : '—' }}</td>
                            <td>{{ $t->assignee ?? '—' }}</td>
                            <td class="nowrap">{{ \Carbon\Carbon::parse($t->lastMessageAt ?? $t->createdAt)->diffForHumans() }}</td>
                            <td><span class="ad-badge ad-badge--{{ S::STATUS[$t->status][0] }}">{{ S::STATUS[$t->status][1] }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($rows->hasPages())<div class="ad-pager">{{ $rows->links('admin.partials.pager') }}</div>@endif
        @else
            <div class="ad-empty"><i class="fa fa-life-ring"></i><b>Không có yêu cầu nào</b>Khách gửi yêu cầu từ trang chuyến đi (menu “⋮” → “Nhờ nhân viên tư vấn”) hoặc trang Hỗ trợ tư vấn.</div>
        @endif
    </div>
@endsection
