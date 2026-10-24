@include('admin.blocks.header')
<div class="container body"><div class="main_container">
@include('admin.blocks.sidebar')
<div class="right_col" role="main">
    <div class="page-title"><div class="title_left"><h3>Danh mục <small>cẩm nang</small></h3></div></div>
    <div class="clearfix"></div>
    @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if (session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
    @if ($errors->any())<div class="alert alert-danger">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
    <div class="x_panel"><div class="x_content">
        <form method="POST" action="{{ route('admin.guide-categories.store') }}" class="form-inline" style="margin-bottom:16px">
            @csrf
            <input class="form-control" name="name" placeholder="Tên danh mục" required>
            <input class="form-control" name="slug" placeholder="slug (tự sinh nếu trống)">
            <input class="form-control" type="number" name="displayOrder" placeholder="Thứ tự" value="0" style="width:90px">
            <button class="btn btn-success">Thêm</button>
        </form>
        <table class="table table-bordered">
            <thead><tr><th>Tên</th><th>Slug</th><th>Thứ tự</th><th>Số bài</th><th></th></tr></thead>
            <tbody>@foreach ($rows as $r)
                <tr>
                    <form method="POST" action="{{ route('admin.guide-categories.update', $r->categoryId) }}">@csrf
                    <td><input class="form-control input-sm" name="name" value="{{ $r->name }}"></td>
                    <td><input class="form-control input-sm" name="slug" value="{{ $r->slug }}"></td>
                    <td><input class="form-control input-sm" type="number" name="displayOrder" value="{{ $r->displayOrder }}" style="width:80px"></td>
                    <td>{{ $r->guideCount }}</td>
                    <td><button class="btn btn-xs btn-primary">Lưu</button></form>
                    <form method="POST" action="{{ route('admin.guide-categories.destroy', $r->categoryId) }}" style="display:inline" onsubmit="return confirm('Xóa danh mục?')">@csrf<button class="btn btn-xs btn-danger">Xóa</button></form></td>
                </tr>
            @endforeach</tbody>
        </table>
    </div></div>
</div></div></div>
@include('admin.blocks.footer')
