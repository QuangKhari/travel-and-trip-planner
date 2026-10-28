@include('admin.blocks.header')
<div class="container body">
    <div class="main_container">
        @include('admin.blocks.sidebar')
        <div class="right_col" role="main">
            <div class="page-title">
                <div class="title_left">
                    <h3>Cảm <small>hứng</small></h3>
                </div>
            </div>
            <div class="clearfix"></div>

            @if (session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif
            @if (session('error'))
                <div class="alert alert-danger">{{ session('error') }}</div>
            @endif
            @if ($errors->any())
                <div class="alert alert-danger">
                    @foreach ($errors->all() as $e)
                        <div>{{ $e }}</div>
                    @endforeach
                </div>
            @endif

            <details class="x_panel" style="padding:0" @if ($edit || $errors->any()) open @endif>
                <summary style="cursor:pointer;padding:10px 15px">
                    <b>{{ $edit ? 'Sửa nhóm: ' . $edit->name : 'Thêm nhóm cảm hứng mới' }}</b>
                    @unless ($edit)
                        <small class="text-muted">(hiếm khi cần: nhóm mới sẽ chưa có điểm đến nào cho đến khi bạn gắn ở
                            trang điểm đến)</small>
                    @endunless
                </summary>
                <div class="x_content">
                    @php
                        $v = fn($n, $d = '') => old($n, $edit->$n ?? $d);
                        $icons = [
                            'fa-water' => 'Sóng nước',
                            'fa-umbrella-beach' => 'Bãi biển',
                            'fa-leaf' => 'Lá cây',
                            'fa-tree' => 'Cây',
                            'fa-mountain' => 'Núi',
                            'fa-spa' => 'Thư giãn',
                            'fa-landmark' => 'Di tích',
                            'fa-utensils' => 'Ẩm thực',
                            'fa-seedling' => 'Đồng quê',
                            'fa-camera' => 'Chụp ảnh',
                            'fa-heart' => 'Cặp đôi',
                            'fa-users' => 'Gia đình, nhóm',
                            'fa-sun' => 'Nắng',
                            'fa-compass' => 'Khám phá',
                            'fa-star' => 'Nổi bật',
                            'fa-gem' => 'Đặc biệt',
                        ];
                        $curIcon = $v('icon', 'fa-water');
                        if (!isset($icons[$curIcon])) {
                            $icons = [$curIcon => 'Hiện tại'] + $icons;
                        }
                    @endphp
                    <form method="POST" enctype="multipart/form-data"
                        action="{{ $edit ? route('admin.inspirations.update', $edit->inspirationId) : route('admin.inspirations.store') }}">
                        @csrf
                        <input type="hidden" name="slug" value="{{ $edit ? $edit->slug : '' }}">
                        <div class="row">
                            <div class="col-md-6 form-group"><label>Tên nhóm *</label><input class="form-control"
                                    name="name" value="{{ $v('name') }}" maxlength="80" required><small
                                    class="text-muted">Chữ lớn trên thẻ. Ví dụ: Trốn ra biển.</small></div>
                            <div class="col-md-6 form-group"><label>Biểu tượng *</label>
                                <select class="form-control" name="icon">
                                    @foreach ($icons as $cls => $lbl)
                                        <option value="{{ $cls }}" @selected($curIcon === $cls)>
                                            {{ $lbl }} ({{ $cls }})</option>
                                    @endforeach
                                </select>
                                <small class="text-muted">Hình nhỏ cạnh tên nhóm ở trang khách.</small>
                            </div>
                            <div class="col-md-6 form-group"><label>Dòng phụ *</label><input class="form-control"
                                    name="tagline" value="{{ $v('tagline') }}" maxlength="160" required><small
                                    class="text-muted">Chữ nhỏ dưới tên, trên thẻ trang chủ. Ví dụ: Biển · đảo · hoàng
                                    hôn.</small></div>
                            <div class="col-md-6 form-group"><label>Câu giới thiệu *</label><input class="form-control"
                                    name="headline" value="{{ $v('headline') }}" maxlength="200" required><small
                                    class="text-muted">Một câu ở đầu trang /explore của nhóm này.</small></div>
                            <div class="col-md-4 form-group"><label>Thứ tự *</label><input type="number" min="0"
                                    max="255" class="form-control" name="displayOrder"
                                    value="{{ $v('displayOrder', 0) }}" required><small class="text-muted">Số nhỏ hiện
                                    trước.</small></div>
                            <div class="col-md-4 form-group"><label>Ảnh bìa</label><input type="file"
                                    class="form-control" name="cover" accept="image/jpeg,image/png,image/webp"><small
                                    class="text-muted">{{ $edit && $edit->coverImage ? 'Đã có ảnh; chọn file mới để thay.' : 'Nên có, nếu thiếu thẻ sẽ không có ảnh.' }}</small>
                            </div>
                            <div class="col-md-4 form-group"><label>&nbsp;</label><br><label><input type="hidden"
                                        name="isActive" value="0"><input type="checkbox" name="isActive"
                                        value="1" @checked((int) $v('isActive', 1) === 1)> Đang hiển thị</label></div>
                        </div>
                        <button class="btn btn-primary">{{ $edit ? 'Lưu' : 'Thêm' }}</button>
                        @if ($edit)
                            <a class="btn btn-default" href="{{ route('admin.inspirations') }}">Hủy</a>
                        @endif
                    </form>
                </div>
            </details>

            <div class="x_panel">
                <div class="x_content">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>Thứ tự</th>
                                <th>Tên</th>
                                <th>Điểm đến (cao nhất trước)</th>
                                <th>Ảnh</th>
                                <th>Trạng thái</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $r)
                                <tr>
                                    <td>{{ $r->displayOrder }}</td>
                                    <td><i class="fa {{ $r->icon }}"></i> {{ $r->name }}<br><small
                                            class="text-muted">{{ $r->tagline }}</small></td>
                                    <td>{{ $r->destCount }}@if (!empty($destNames[$r->inspirationId]))
                                            <br><small
                                                class="text-muted">{{ implode(', ', $destNames[$r->inspirationId]) }}</small>
                                        @endif
                                    </td>
                                    <td>{{ $r->coverImage ? 'có' : 'thiếu' }}</td>
                                    <td>{{ $r->isActive ? 'Hiển thị' : 'Tắt' }}</td>
                                    <td><a class="btn btn-xs btn-primary"
                                            href="{{ route('admin.inspirations', ['edit' => $r->inspirationId]) }}">Sửa</a>
                                        <form method="POST"
                                            action="{{ route('admin.inspirations.destroy', $r->inspirationId) }}"
                                            style="display:inline" onsubmit="return confirm('Xóa cảm hứng này?')">
                                            @csrf<button class="btn btn-xs btn-danger">Xóa</button></form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@include('admin.blocks.footer')
