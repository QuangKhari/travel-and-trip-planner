@include('admin.blocks.header')
<div class="container body"><div class="main_container">
@include('admin.blocks.sidebar')
<div class="right_col" role="main">
    <div class="page-title"><div class="title_left"><h3>Tổng quan <small>nội dung</small></h3></div></div>
    <div class="clearfix"></div>
    @if ($noDestTours)<div class="alert alert-warning">{{ $noDestTours }} tour chưa gắn điểm đến (không hiện ở lọc theo cảm hứng và bản đồ). <a href="{{ route('admin.tours') }}">Mở danh sách tour</a></div>@endif
    <div class="x_panel"><div class="x_title"><h2>Số bài theo trạng thái</h2><div class="clearfix"></div></div><div class="x_content">
        <table class="table"><thead><tr><th>Loại</th><th>Nháp</th><th>Đã xem</th><th>Đã xuất bản</th></tr></thead><tbody>
        @foreach ($byStatus as $k => $r)<tr><td><a href="{{ route('admin.content', $k) }}">{{ $r['label'] }}</a></td><td>{{ $r['draft'] ?? 0 }}</td><td>{{ $r['reviewed'] ?? 0 }}</td><td>{{ $r['published'] ?? 0 }}</td></tr>@endforeach
        </tbody></table></div></div>
    <div class="x_panel"><div class="x_title"><h2>Độ phủ theo điểm đến (chỉ tính đã xuất bản)</h2><div class="clearfix"></div></div><div class="x_content">
        <table class="table table-condensed table-striped"><thead><tr><th>Điểm đến</th><th>Món</th><th>Lễ hội</th><th>Địa điểm</th><th>Mùa đẹp</th><th>Tour</th></tr></thead><tbody>
        @foreach ($coverage as $c)<tr>
            <td><a href="{{ route('admin.destinations.edit', $c->destinationId) }}">{{ $c->name }}</a></td>
            @foreach (['foods', 'events', 'places'] as $k)<td class="{{ $c->$k == 0 ? 'text-danger' : '' }}">{{ $c->$k }}</td>@endforeach
            <td class="{{ $c->months < 12 ? 'text-danger' : '' }}">{{ $c->months }}/12</td><td class="{{ $c->tours == 0 ? 'text-danger' : '' }}">{{ $c->tours }}</td></tr>@endforeach
        </tbody></table></div></div>
    <div class="x_panel"><div class="x_title"><h2>Cẩm nang quá {{ $staleDays }} ngày chưa cập nhật</h2><div class="clearfix"></div></div><div class="x_content">
        @forelse ($stale as $g)<div><a href="{{ route('admin.content.edit', ['guides', $g->guideId]) }}">{{ $g->title }}</a> <small class="text-muted">{{ $g->updatedOn }}</small></div>@empty<span class="text-success">Không có bài quá hạn.</span>@endforelse
    </div></div>
</div></div></div>
@include('admin.blocks.footer')
