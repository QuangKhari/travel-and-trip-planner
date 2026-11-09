@extends('admin.layout')
@section('content')
    <div class="ad-head"><div><h1>Kiểm tra khả thi tour</h1>
        <p>Lịch trình hợp lệ về dữ liệu chưa chắc tổ chức được: thiếu / sai số ngày, mốc giờ lệch hoặc quá dày, điểm đóng cửa đúng giờ tham quan, di chuyển không kịp. Dữ liệu không có nguồn xác minh được đánh dấu “kiểm tra thủ công”.</p></div></div>
    <div class="ad-card">
        <div class="ad-toolbar"><form method="GET" action="{{ route('admin.feasibility') }}"><input name="q" value="{{ request('q') }}" placeholder="Tìm theo tên tour"><button class="ad-btn ad-btn--sm" type="submit">Tìm</button></form></div>
        @if ($tours->count())
            <div class="ad-tablewrap"><table class="ad-table"><thead><tr><th>Tour</th><th>Kết quả</th><th>Lỗi</th><th>Cảnh báo</th><th>Thủ công</th><th></th></tr></thead><tbody>
                @foreach ($tours as $t)
                    <tr><td><b>{{ $t->title }}</b><span class="ad-sub">{{ $t->destination }}</span></td>
                        <td>@if ($t->fz)<span class="ad-badge ad-badge--{{ ['ok' => 'ok', 'review' => 'warn', 'blocked' => 'bad'][$t->fz['verdict']] }}">{{ ['ok' => 'Đạt', 'review' => 'Cần xem xét', 'blocked' => 'Có lỗi'][$t->fz['verdict']] }}</span>@else—@endif</td>
                        <td>{{ $t->fz['counts']['error'] ?? '—' }}</td><td>{{ $t->fz['counts']['warn'] ?? '—' }}</td><td>{{ $t->fz['counts']['manual'] ?? '—' }}</td>
                        <td class="num"><a class="ad-btn ad-btn--sm" href="{{ route('admin.feasibility.show', $t->tourId) }}">Chi tiết</a></td></tr>
                @endforeach
            </tbody></table></div>
            @if ($tours->hasPages())<div class="ad-pager">{{ $tours->links('admin.partials.pager') }}</div>@endif
        @else
            <div class="ad-empty"><b>Không có tour</b></div>
        @endif
    </div>
@endsection
