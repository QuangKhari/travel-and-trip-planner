@include('admin.blocks.header')
@php $v = fn($n, $d = '') => old($n, $row->$n ?? $d); @endphp
<div class="container body"><div class="main_container">
@include('admin.blocks.sidebar')
<div class="right_col" role="main">
    <div class="page-title"><div class="title_left"><h3>{{ $row ? 'Sửa' : 'Thêm' }} bài blog</h3></div>
        <div class="title_right text-right"><a class="btn btn-default" href="{{ route('admin.blog') }}">« Danh sách</a></div></div>
    <div class="clearfix"></div>
    @if (session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
    @if ($errors->any())<div class="alert alert-danger">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
    <div class="x_panel"><div class="x_content">
    <form method="POST" enctype="multipart/form-data" action="{{ $row ? route('admin.blog.update', $row->postId) : route('admin.blog.store') }}">
        @csrf
        <div class="row">
            <div class="col-md-8 form-group"><label>Tiêu đề *</label><input class="form-control" name="title" value="{{ $v('title') }}" required></div>
            <div class="col-md-4 form-group"><label>Slug</label><input class="form-control" name="slug" value="{{ $v('slug') }}" placeholder="tự sinh nếu trống"></div>
            <div class="col-md-4 form-group"><label>Danh mục *</label><select class="form-control" name="category">@foreach ($categories as $c)<option @selected($v('category') === $c)>{{ $c }}</option>@endforeach</select></div>
            <div class="col-md-4 form-group"><label>Ngày đăng *</label><input type="date" class="form-control" name="publishedOn" value="{{ $v('publishedOn', now()->toDateString()) }}" required></div>
            <div class="col-md-4 form-group"><label>Phút đọc *</label><input type="number" class="form-control" name="readMinutes" value="{{ $v('readMinutes', 3) }}" required></div>
        </div>
        <div class="form-group"><label>Tóm tắt</label><textarea class="form-control" name="excerpt" rows="2">{{ $v('excerpt') }}</textarea></div>
        <div class="form-group"><label>Mở bài</label><textarea class="form-control" name="intro" rows="3">{{ $v('intro') }}</textarea></div>
        <div class="form-group"><label>Trích dẫn nổi bật</label><input class="form-control" name="quote" value="{{ $v('quote') }}"></div>
        <div class="form-group"><label>Nội dung * (dòng "## Tiêu đề mục" mở mục mới; các đoạn cách nhau một dòng trống)</label>
            <textarea class="form-control" name="body" rows="18" required>{{ old('body', $text) }}</textarea></div>
        <div class="row">
            <div class="col-md-6 form-group"><label>Ảnh (tải lên)</label><input type="file" class="form-control" name="imageFile" accept="image/jpeg,image/png,image/webp"></div>
            <div class="col-md-6 form-group"><label>hoặc tên tệp có sẵn trong images/blog</label><input class="form-control" name="image" value="{{ $v('image') }}" placeholder="blog1.jpg"></div>
        </div>
        <label style="margin-right:18px"><input type="hidden" name="featured" value="0"><input type="checkbox" name="featured" value="1" @checked((int) $v('featured', 0) === 1)> Nổi bật</label>
        <label><input type="hidden" name="isPublished" value="0"><input type="checkbox" name="isPublished" value="1" @checked((int) $v('isPublished', 0) === 1)> Xuất bản</label>
        <div style="margin-top:12px"><button class="btn btn-primary">Lưu</button></div>
    </form></div></div>
</div></div></div>
@include('admin.blocks.footer')
