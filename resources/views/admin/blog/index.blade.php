@include('admin.blocks.header')
<div class="container body"><div class="main_container">
@include('admin.blocks.sidebar')
<div class="right_col" role="main">
    <div class="page-title"><div class="title_left"><h3>Blog</h3></div>
        <div class="title_right text-right">
            <form method="POST" action="{{ route('admin.blog.import') }}" style="display:inline">@csrf<button class="btn btn-default">Nhập bài mẫu từ config</button></form>
            <a class="btn btn-success" href="{{ route('admin.blog.create') }}">Thêm bài</a>
        </div></div>
    <div class="clearfix"></div>
    @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if (session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
    @if ($usingConfig)<div class="alert alert-info">Chưa có bài nào xuất bản trong DB nên trang công khai đang dùng 7 bài mẫu trong config/blog.php.</div>@endif
    <div class="x_panel"><div class="x_content"><table class="table table-bordered table-striped">
        <thead><tr><th>Tiêu đề</th><th>Danh mục</th><th>Ngày</th><th>Trạng thái</th><th></th></tr></thead>
        <tbody>@forelse ($rows as $r)
            <tr><td>{{ $r->title }} @if ($r->featured)<span class="label label-primary">Nổi bật</span>@endif<br><small class="text-muted">{{ $r->slug }}</small></td>
            <td>{{ $r->category }}</td><td>{{ $r->publishedOn }}</td><td>{{ $r->isPublished ? 'Đã xuất bản' : 'Nháp' }}</td>
            <td><a class="btn btn-xs btn-primary" href="{{ route('admin.blog.edit', $r->postId) }}">Sửa</a>
            <form method="POST" action="{{ route('admin.blog.destroy', $r->postId) }}" style="display:inline" onsubmit="return confirm('Xóa bài?')">@csrf<button class="btn btn-xs btn-danger">Xóa</button></form></td></tr>
        @empty<tr><td colspan="5" class="text-center">Chưa có bài.</td></tr>@endforelse</tbody></table></div></div>
</div></div></div>
@include('admin.blocks.footer')
