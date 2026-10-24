@include('admin.blocks.header')
<div class="container body">
    <div class="main_container">
        @include('admin.blocks.sidebar')
        <div class="right_col" role="main">
            <div class="page-title">
                <div class="title_left"><h3>Điểm <small>đến</small></h3></div>
                <div class="title_right text-right">
                    <a class="btn btn-success" href="{{ route('admin.destinations.create') }}"><i class="fa fa-plus"></i> Thêm điểm đến</a>
                </div>
            </div>
            <div class="clearfix"></div>
            @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
            @if (session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

            <div class="x_panel"><div class="x_content">
                <form method="GET" class="form-inline" style="margin-bottom:12px">
                    <input type="text" name="q" value="{{ $search }}" class="form-control" placeholder="Tên hoặc tỉnh">
                    <select name="mien" class="form-control">
                        <option value="">Mọi miền</option>
                        @foreach ($regions as $c => $n)<option value="{{ $c }}" @selected($mien === $c)>{{ $n }}</option>@endforeach
                    </select>
                    <select name="filter" class="form-control">
                        <option value="">Mọi tình trạng</option>
                        <option value="inactive" @selected($filter === 'inactive')>Đang tắt</option>
                        <option value="noimage" @selected($filter === 'noimage')>Thiếu ảnh bìa</option>
                        <option value="nomonths" @selected($filter === 'nomonths')>Chưa đủ 12 tháng mùa đẹp</option>
                        <option value="notour" @selected($filter === 'notour')>Chưa có tour</option>
                    </select>
                    <button class="btn btn-default">Lọc</button>
                </form>
                <div class="table-responsive">
                <table class="table table-striped table-bordered">
                    <thead><tr><th>Tên</th><th>Tỉnh</th><th>Miền</th><th>Mùa đẹp</th><th>Tour</th><th>Ảnh</th><th>Trạng thái</th><th></th></tr></thead>
                    <tbody>
                    @forelse ($rows as $r)
                        <tr>
                            <td>{{ $r->name }}
                                @if ($r->isMustTry)<span class="label label-warning">Phải thử</span>@endif
                                @if ($r->isHero)<span class="label label-primary">Hero</span>@endif
                                @if ($r->isFeatured)<span class="label label-info">Nổi bật</span>@endif
                                <br><small class="text-muted">{{ $r->slug }}</small></td>
                            <td>{{ $r->province }}</td>
                            <td>{{ $regions[$r->domain] ?? '' }}</td>
                            <td class="{{ $r->monthCount < 12 ? 'text-danger' : '' }}">{{ $r->monthCount }}/12</td>
                            <td>{{ $r->tourCount }}</td>
                            <td>{!! $r->coverImage ? 'có' : '<span class="text-danger">thiếu</span>' !!}</td>
                            <td>{{ $r->isActive ? 'Hiển thị' : 'Đang tắt' }}</td>
                            <td><a class="btn btn-xs btn-primary" href="{{ route('admin.destinations.edit', $r->destinationId) }}">Sửa</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center">Không có dữ liệu.</td></tr>
                    @endforelse
                    </tbody>
                </table></div>
                {{ $rows->links() }}
            </div></div>
        </div>
    </div>
</div>
@include('admin.blocks.footer')
