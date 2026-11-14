@extends('admin.layout')
@section('content')
    @php $st = ['open' => 'Chờ báo giá', 'quoted' => 'Đã báo giá', 'accepted' => 'Khách nhận', 'declined' => 'Khách từ chối', 'cancelled' => 'Đã hủy', 'all' => 'Tất cả']; @endphp
    <div class="ad-head"><div><h1>Báo giá tour riêng</h1><p>Khách gửi lịch tự lên trong Trip Planner và nhờ báo giá trọn gói cho nhóm. Báo giá xong, khách nhận email và thông báo ở chuông của chuyến.</p></div></div>
    @if (!$ready)
        <div class="ad-card"><div class="ad-empty"><i class="fa fa-database"></i><b>Chưa chạy migration</b></div></div>
    @else
        <nav style="display:flex;gap:6px;margin-bottom:12px">@foreach ($st as $k => $l)<a class="ad-btn ad-btn--sm {{ $status === $k ? 'ad-btn--primary' : 'ad-btn--ghost' }}" href="{{ route('admin.tour-quotes', ['status' => $k]) }}">{{ $l }}@if ($k !== 'all') <span class="ad-badge">{{ $counts[$k] ?? 0 }}</span>@endif</a>@endforeach</nav>
        <section class="ad-card">
            @if ($rows->isEmpty())
                <div class="ad-empty"><i class="fa fa-inbox"></i><b>Không có yêu cầu</b></div>
            @else
                <table class="ad-table"><thead><tr><th>#</th><th>Khách</th><th>Chuyến</th><th>Nhóm / ngân sách</th><th>Trạng thái</th><th></th></tr></thead><tbody>
                @foreach ($rows as $r)
                    <tr><td>{{ $r->quoteId }}<br><small class="ad-muted">{{ \Carbon\Carbon::parse($r->createdAt)->format('d/m H:i') }}</small></td>
                        <td>{{ $r->fullName ?: $r->username }}</td><td>{{ $r->title }}</td>
                        <td>{{ $r->groupSize }} người · {{ $r->budget ? number_format($r->budget, 0, ',', '.') . 'đ' : 'chưa nêu' }}</td>
                        <td>{{ $st[$r->status] ?? $r->status }}@if ($r->pricePerPerson)<br><small>{{ number_format($r->pricePerPerson, 0, ',', '.') }}đ/người</small>@endif</td>
                        <td><a class="ad-btn ad-btn--sm" href="{{ route('admin.tour-quotes.show', $r->quoteId) }}">Mở</a></td></tr>
                @endforeach
                </tbody></table>
            @endif
        </section>
    @endif
@endsection
