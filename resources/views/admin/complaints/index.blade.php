@extends('admin.layout')
@section('content')
    <div class="ad-head"><div><h1>Khiếu nại sau tour</h1>
        <p>Khách của đơn đã đi xong gửi khiếu nại trong thời hạn cho phép. Phản hồi, giải quyết hoặc từ chối (phải nêu lý do), đặt bồi hoàn và quy lỗi cho nhà cung cấp để tính vào đánh giá nhà cung cấp.</p></div></div>
    @if (!$ready)
        <div class="ad-card"><div class="ad-empty"><i class="fa fa-database"></i><b>Chưa chạy migration</b>Chạy <code>php artisan migrate</code> (2026_11_30_000001).</div></div>
    @else
        <div class="ad-card">
            <div class="ad-toolbar"><div class="ad-chips">
                @foreach (['active' => 'Đang xử lý', 'resolved' => 'Đã giải quyết', 'rejected' => 'Từ chối', 'closed' => 'Đã đóng', 'all' => 'Tất cả'] as $k => $l)
                    <a class="ad-chip {{ $status === $k ? 'is-on' : '' }}" href="{{ route('admin.complaints', ['status' => $k]) }}">{{ $l }}</a>
                @endforeach
            </div></div>
            @if ($rows->count())
                <div class="ad-tablewrap"><table class="ad-table"><thead><tr><th>Mã</th><th>Khiếu nại</th><th>Đơn / tour</th><th>Bồi hoàn</th><th>Trạng thái</th><th>Gửi lúc</th></tr></thead><tbody>
                    @foreach ($rows as $r)
                        <tr><td class="nowrap"><a href="{{ route('admin.complaints.show', $r->complaintId) }}"><b>{{ $r->code }}</b></a></td>
                            <td>{{ $r->subject }}<span class="ad-sub">{{ $cats[$r->category] ?? $r->category }}</span></td>
                            <td>{{ $r->bookingCode }}<span class="ad-sub">{{ $r->title }}</span></td>
                            <td>@if ($r->compensationAmount > 0){{ number_format($r->compensationAmount, 0, ',', '.') }} đ<span class="ad-sub">{{ $r->compensationStatus === 'paid' ? 'đã chi' : 'chờ chi' }}</span>@else—@endif</td>
                            <td><span class="ad-badge ad-badge--{{ ['open' => 'violet', 'investigating' => 'warn', 'resolved' => 'ok', 'rejected' => 'mute', 'closed' => 'mute'][$r->status] ?? 'mute' }}">{{ $statuses[$r->status] ?? $r->status }}</span></td>
                            <td class="nowrap">{{ date('d/m H:i', strtotime($r->createdAt)) }}</td></tr>
                    @endforeach
                </tbody></table></div>
                @if ($rows->hasPages())<div class="ad-pager">{{ $rows->links('admin.partials.pager') }}</div>@endif
            @else
                <div class="ad-empty"><i class="fa fa-check-circle"></i><b>Không có khiếu nại</b></div>
            @endif
        </div>
    @endif
@endsection
