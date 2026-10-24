@include('admin.blocks.header')
@php $isNew = $id === null; @endphp
<div class="container body">
    <div class="main_container">
        @include('admin.blocks.sidebar')
        <div class="right_col" role="main">
            <div class="page-title">
                <div class="title_left"><h3>{{ $isNew ? 'Thêm' : 'Sửa' }} <small>điểm đến {{ $row->name ?? '' }}</small></h3></div>
                <div class="title_right text-right"><a class="btn btn-default" href="{{ route('admin.destinations') }}">« Danh sách</a></div>
            </div>
            <div class="clearfix"></div>
            @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
            @if (session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
            @if ($errors->any())<div class="alert alert-danger"><ul style="margin:0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

            <div class="x_panel">
                <div class="x_title"><h2>Thông tin</h2><div class="clearfix"></div></div>
                <div class="x_content">
                    <form method="POST" enctype="multipart/form-data" action="{{ $isNew ? route('admin.destinations.store') : route('admin.destinations.update', $id) }}">
                        @csrf
                        @php
                            $f = fn($n, $d = null) => old($n, $row->$n ?? $d);
                        @endphp
                        <div class="row">
                            <div class="col-md-6 form-group"><label>Tên *</label><input class="form-control" name="name" value="{{ $f('name') }}" required></div>
                            <div class="col-md-6 form-group"><label>Slug</label><input class="form-control" name="slug" value="{{ $f('slug') }}" placeholder="để trống sẽ tự sinh"><small class="text-muted">Đổi slug sẽ đổi địa chỉ trang công khai.</small></div>
                            <div class="col-md-6 form-group"><label>Tỉnh/thành *</label><input class="form-control" name="province" value="{{ $f('province') }}" required></div>
                            <div class="col-md-6 form-group"><label>Miền *</label>
                                <select class="form-control" name="domain">
                                    @foreach ($regions as $c => $n)<option value="{{ $c }}" @selected($f('domain') === $c)>{{ $n }}</option>@endforeach
                                </select></div>
                            <div class="col-md-6 form-group"><label>Vĩ độ *</label><input class="form-control" name="latitude" value="{{ $f('latitude') }}" required></div>
                            <div class="col-md-6 form-group"><label>Kinh độ *</label><input class="form-control" name="longitude" value="{{ $f('longitude') }}" required></div>
                        </div>
                        <div class="form-group"><label>Mô tả *</label><textarea class="form-control" name="description" rows="5" required>{{ $f('description') }}</textarea></div>
                        <div class="form-group"><label>Tên bài Wikipedia</label><input class="form-control" name="wikiTitle" value="{{ $f('wikiTitle') }}"></div>
                        <div class="form-group">
                            @foreach (['isActive' => 'Đang hiển thị', 'isMustTry' => 'Nhãn "Phải thử"', 'isFeatured' => 'Nổi bật', 'isHero' => 'Ưu tiên hiện trong hero trang chủ'] as $n => $l)
                                <label style="margin-right:18px"><input type="hidden" name="{{ $n }}" value="0"><input type="checkbox" name="{{ $n }}" value="1" @checked((int) $f($n, 0) === 1)> {{ $l }}</label>
                            @endforeach
                        </div>
                        <div class="form-group">
                            <label>Ảnh bìa</label>
                            @if (!empty($row->coverImage))
                                <p><img src="{{ \App\Support\DiscoverImage::url($row->coverImage) }}" style="max-height:140px;border-radius:4px" alt=""></p>
                                <label><input type="checkbox" name="removeCover" value="1"> Xóa ảnh hiện tại</label>
                            @endif
                            <input type="file" name="cover" accept="image/jpeg,image/png,image/webp" class="form-control">
                        </div>
                        <div class="row">
                            <div class="col-md-6 form-group"><label>Ghi công ảnh (bắt buộc khi tải ảnh)</label><input class="form-control" name="imageCredit" value="{{ $f('imageCredit') }}"></div>
                            <div class="col-md-6 form-group"><label>Trang gốc của ảnh</label><input class="form-control" name="imageSourceUrl" value="{{ $f('imageSourceUrl') }}"></div>
                        </div>
                        <button class="btn btn-primary">{{ $isNew ? 'Thêm điểm đến' : 'Lưu' }}</button>
                    </form>
                </div>
            </div>

            @unless ($isNew)
            <div class="x_panel">
                <div class="x_title"><h2>Mùa đẹp 12 tháng</h2><div class="clearfix"></div></div>
                <div class="x_content">
                    <p class="text-muted">Dải "Đang vào mùa" và bảng "Nên đi tháng nào" đọc từ đây. Để trống mức = chưa có dữ liệu. Lưu tay sẽ không bị lệnh climate:build-months ghi đè.</p>
                    @if ($suggest)<button type="button" class="btn btn-default btn-sm" id="apply-suggest">Điền theo gợi ý của luật khí hậu</button>@endif
                    <form method="POST" action="{{ route('admin.destinations.months', $id) }}" style="margin-top:10px">
                        @csrf
                        <table class="table table-condensed">
                            <thead><tr><th>Tháng</th><th>Mức</th><th>Ghi chú (hiện ở hero)</th><th>Khí hậu</th><th>Gợi ý</th><th>Nguồn</th></tr></thead>
                            <tbody>
                            @for ($m = 1; $m <= 12; $m++)
                                @php $cur = $months[$m] ?? null; $c = $climate[$m] ?? null; $sg = $suggest[$m]['level'] ?? ''; @endphp
                                <tr>
                                    <td>{{ $m }}</td>
                                    <td><select name="level[{{ $m }}]" class="form-control input-sm lvl" data-suggest="{{ $sg }}">
                                        <option value="">(chưa có)</option>
                                        @foreach (['best' => 'Đẹp nhất', 'ok' => 'Ổn', 'avoid' => 'Nên tránh'] as $k => $t)
                                            <option value="{{ $k }}" @selected(($cur->level ?? '') === $k)>{{ $t }}</option>
                                        @endforeach</select></td>
                                    <td><input class="form-control input-sm" name="note[{{ $m }}]" maxlength="160" value="{{ $cur->note ?? '' }}"></td>
                                    <td><small>@if ($c){{ round($c->tMin) }}–{{ round($c->tMax) }}°C · {{ round($c->rainMm) }} mm @endif</small></td>
                                    <td><small>{{ ['best' => 'Đẹp nhất', 'ok' => 'Ổn', 'avoid' => 'Nên tránh'][$sg] ?? '' }}</small></td>
                                    <td><small>{{ $cur->source ?? '' }}</small></td>
                                </tr>
                            @endfor
                            </tbody>
                        </table>
                        <button class="btn btn-primary">Lưu mùa đẹp</button>
                    </form>
                </div>
            </div>

            <div class="x_panel">
                <div class="x_title"><h2>Điểm hợp cảm hứng</h2><div class="clearfix"></div></div>
                <div class="x_content">
                    <form method="POST" action="{{ route('admin.destinations.inspirations', $id) }}">
                        @csrf
                        <div class="row">
                            @foreach ($inspirations as $i)
                                <div class="col-md-3 form-group">
                                    <label>{{ $i->name }} @unless ($i->isActive)<small>(đang tắt)</small>@endunless</label>
                                    <input type="number" min="0" max="100" class="form-control" name="score[{{ $i->inspirationId }}]" value="{{ $scores[$i->inspirationId] ?? '' }}" placeholder="trống = không gắn">
                                </div>
                            @endforeach
                        </div>
                        <button class="btn btn-primary">Lưu điểm cảm hứng</button>
                    </form>
                </div>
            </div>

            <div class="x_panel">
                <div class="x_content">
                    Đang gắn: {{ $counts['tours'] }} tour · {{ $counts['foods'] }} món · {{ $counts['events'] }} lễ hội · {{ $counts['places'] }} địa điểm.
                    <form method="POST" action="{{ route('admin.destinations.destroy', $id) }}" style="display:inline" onsubmit="return confirm('Xóa điểm đến này?')">
                        @csrf <button class="btn btn-link text-danger">Xóa điểm đến</button>
                    </form>
                </div>
            </div>
            @endunless
        </div>
    </div>
</div>
<script>
document.getElementById('apply-suggest')?.addEventListener('click', function () {
    document.querySelectorAll('.lvl').forEach(function (s) { if (s.dataset.suggest) s.value = s.dataset.suggest; });
});
</script>
@include('admin.blocks.footer')
