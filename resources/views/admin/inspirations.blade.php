@include('admin.blocks.header')
<div class="container body"><div class="main_container">
@include('admin.blocks.sidebar')
<div class="right_col" role="main">
    <div class="page-title"><div class="title_left"><h3>Cảm <small>hứng</small></h3></div></div>
    <div class="clearfix"></div>
    <p class="text-muted">Cảm hứng là các nhóm gợi ý ở trang chủ và trang Khám phá. Bộ nhóm này gần như cố định: trang này chỉ dùng để đổi tên, câu giới thiệu, ảnh, thứ tự hoặc tắt một nhóm.
        <b>Việc gắn điểm đến vào nhóm làm ở trang sửa từng điểm đến</b> (mục "Thuộc nhóm cảm hứng nào?"), không làm ở đây.</p>

    @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if (session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
    @if ($errors->any())<div class="alert alert-danger">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif

    <details class="x_panel" style="padding:0" @if ($edit || $errors->any()) open @endif><summary style="cursor:pointer;padding:10px 15px"><b>{{ $edit ? 'Sửa: ' . $edit->name : 'Thêm nhóm cảm hứng mới' }}</b>@unless ($edit) <small class="text-muted">(hiếm khi cần)</small>@endunless</summary>
    <div class="x_content">
        @php $v = fn($n, $d = '') => old($n, $edit->$n ?? $d); @endphp
        <form method="POST" enctype="multipart/form-data" action="{{ $edit ? route('admin.inspirations.update', $edit->inspirationId) : route('admin.inspirations.store') }}">
            @csrf
            <div class="row">
                <div class="col-md-4 form-group"><label>Tên *</label><input class="form-control" name="name" value="{{ $v('name') }}" required></div>
                <div class="col-md-4 form-group"><label>Slug</label><input class="form-control" name="slug" value="{{ $v('slug') }}"></div>
                <div class="col-md-4 form-group"><label>Icon Font Awesome *</label><input class="form-control" name="icon" value="{{ $v('icon', 'fa-water') }}" required></div>
                <div class="col-md-6 form-group"><label>Dòng phụ *</label><input class="form-control" name="tagline" value="{{ $v('tagline') }}" required></div>
                <div class="col-md-6 form-group"><label>Câu mở đầu ở /explore *</label><input class="form-control" name="headline" value="{{ $v('headline') }}" required></div>
                <div class="col-md-4 form-group"><label>Thứ tự *</label><input type="number" class="form-control" name="displayOrder" value="{{ $v('displayOrder', 0) }}" required></div>
                <div class="col-md-4 form-group"><label>Ảnh bìa</label><input type="file" class="form-control" name="cover" accept="image/jpeg,image/png,image/webp"></div>
                <div class="col-md-4 form-group"><label>&nbsp;</label><br><label><input type="hidden" name="isActive" value="0"><input type="checkbox" name="isActive" value="1" @checked((int) $v('isActive', 1) === 1)> Đang hiển thị</label></div>
            </div>
            <button class="btn btn-primary">{{ $edit ? 'Lưu' : 'Thêm' }}</button>
            @if ($edit)<a class="btn btn-default" href="{{ route('admin.inspirations') }}">Hủy</a>@endif
        </form>
    </div></details>

    <div class="x_panel"><div class="x_content"><table class="table table-bordered table-striped">
        <thead><tr><th>#</th><th>Tên</th><th>Slug</th><th>Điểm đến</th><th>Ảnh</th><th>Trạng thái</th><th></th></tr></thead>
        <tbody>@foreach ($rows as $r)
            <tr><td>{{ $r->displayOrder }}</td><td><i class="fa {{ $r->icon }}"></i> {{ $r->name }}<br><small class="text-muted">{{ $r->tagline }}</small></td><td>{{ $r->slug }}</td>
            <td>{{ $r->destCount }}@if (!empty($destNames[$r->inspirationId]))<br><small class="text-muted">{{ implode(', ', $destNames[$r->inspirationId]) }}</small>@endif</td><td>{{ $r->coverImage ? 'có' : 'thiếu' }}</td><td>{{ $r->isActive ? 'Hiển thị' : 'Tắt' }}</td>
            <td><a class="btn btn-xs btn-primary" href="{{ route('admin.inspirations', ['edit' => $r->inspirationId]) }}">Sửa</a>
            <form method="POST" action="{{ route('admin.inspirations.destroy', $r->inspirationId) }}" style="display:inline" onsubmit="return confirm('Xóa cảm hứng này?')">@csrf<button class="btn btn-xs btn-danger">Xóa</button></form></td></tr>
        @endforeach</tbody></table></div></div>
</div></div></div>
@include('admin.blocks.footer')
