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
                            <div class="col-md-6 form-group"><label>Tỉnh/thành *</label><input class="form-control" name="province" value="{{ $f('province') }}" required></div>
                            <div class="col-md-6 form-group"><label>Miền *</label>
                                <select class="form-control" name="domain">
                                    @foreach ($regions as $c => $n)<option value="{{ $c }}" @selected($f('domain') === $c)>{{ $n }}</option>@endforeach
                                </select></div>
                        </div>
                        <div class="form-group"><label>Mô tả *</label><textarea class="form-control" name="description" rows="5" required>{{ $f('description') }}</textarea></div>
                        <div class="well" style="padding:12px 15px;margin-bottom:15px">
                            <b>Vị trí trên bản đồ *</b>
                            <p class="text-muted" style="margin:4px 0 8px">Dùng để đặt điểm đến lên bản đồ, tính khoảng cách cho Planner và lấy thời tiết. Cách nhanh nhất: mở Google Maps, tìm nơi đó, bấm chuột phải vào đúng chỗ rồi bấm dòng số đầu tiên (đã tự chép), hoặc chép link ở thanh địa chỉ. Dán vào ô dưới, hệ thống tự điền hai ô vĩ độ và kinh độ.</p>
                            <input class="form-control" id="map-paste" type="text" autocomplete="off" placeholder="Dán link Google Maps (đầy đủ) hoặc cặp số như 21.0285, 105.8542">
                            <small id="map-paste-msg" class="text-muted" style="display:block;margin-top:4px"></small>
                            <div class="row" style="margin-top:10px">
                                <div class="col-md-6 form-group"><label>Vĩ độ *</label><input class="form-control" id="lat" name="latitude" value="{{ $f('latitude') }}" required></div>
                                <div class="col-md-6 form-group"><label>Kinh độ *</label><input class="form-control" id="lng" name="longitude" value="{{ $f('longitude') }}" required></div>
                            </div>
                            <small class="text-muted">Link rút gọn dạng maps.app.goo.gl không chứa tọa độ: hãy mở link đó trong trình duyệt rồi chép lại link đầy đủ ở thanh địa chỉ. Việt Nam nằm khoảng vĩ độ 8 đến 24, kinh độ 102 đến 110.</small>
                        </div>
                        @if (!$isNew)
                            <p class="text-muted">Địa chỉ trang công khai: <code>/diem-den/{{ $row->slug }}</code> (tự sinh từ tên khi tạo, không cần sửa).</p>
                        @endif
                        <details class="form-group" @if ($errors->has('wikiTitle')) open @endif style="margin-bottom:15px">
                            <summary style="cursor:pointer"><b>Nâng cao</b> <small class="text-muted">(bài viết tự soạn thì bỏ qua)</small></summary>
                            <div class="row" style="margin-top:10px">
                                <div class="col-md-6 form-group"><label>Tên bài Wikipedia</label><input class="form-control" name="wikiTitle" value="{{ $f('wikiTitle') }}"><small class="text-muted">Chỉ dùng khi chạy lệnh nhập dữ liệu từ Wikipedia (kéo mô tả, ảnh về). Bài tự viết thì để trống, không ảnh hưởng gì đến việc xuất bản.</small></div>
                            </div>
                        </details>
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
                <div class="x_title"><h2>Thuộc nhóm cảm hứng nào?</h2><div class="clearfix"></div></div>
                <div class="x_content">
                    <p class="text-muted">Đây là <b>nơi duy nhất</b> để gắn điểm đến vào các nhóm cảm hứng ở trang chủ và /explore. Chấm từ 0 đến 100 mức độ điểm đến này hợp với từng nhóm (điểm cao hiện trước). Để trống nếu không thuộc nhóm đó.</p>
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
                        <button class="btn btn-primary">Lưu nhóm cảm hứng</button>
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
function parseMapsInput(raw) {
    var t = String(raw || '').trim();
    try { t = decodeURIComponent(t); } catch (e) {}
    var ok = function (la, lo) { la = parseFloat(la); lo = parseFloat(lo); return isFinite(la) && isFinite(lo) && Math.abs(la) <= 90 && Math.abs(lo) <= 180 ? [la, lo] : null; };
    var m;
    if ((m = t.match(/!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)/))) return ok(m[1], m[2]);                 // ghim địa điểm
    if ((m = t.match(/@(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/))) return ok(m[1], m[2]);                     // tâm khung nhìn
    if ((m = t.match(/[?&](?:q|query|ll|destination)=(-?\d+(?:\.\d+)?)[,+ ]+(-?\d+(?:\.\d+)?)/))) return ok(m[1], m[2]);
    if ((m = t.match(/^(-?\d+(?:\.\d+)?)\s*[,;\s]\s*(-?\d+(?:\.\d+)?)$/))) return ok(m[1], m[2]);        // "21.0285, 105.8542"
    return null;
}
document.getElementById('map-paste')?.addEventListener('input', function () {
    var msg = document.getElementById('map-paste-msg'), v = this.value.trim();
    if (!v) { msg.textContent = ''; return; }
    var r = parseMapsInput(v);
    if (r) {
        document.getElementById('lat').value = r[0];
        document.getElementById('lng').value = r[1];
        msg.className = 'text-success';
        msg.textContent = 'Đã điền vĩ độ ' + r[0] + ', kinh độ ' + r[1] + '. Hãy kiểm tra lại cho đúng nơi bạn muốn.';
    } else {
        msg.className = 'text-danger';
        msg.textContent = /goo\.gl|maps\.app/.test(v)
            ? 'Link rút gọn không chứa tọa độ. Hãy mở link trong trình duyệt rồi chép link đầy đủ ở thanh địa chỉ.'
            : 'Không đọc được tọa độ từ nội dung này. Bạn có thể nhập tay vào hai ô bên dưới.';
    }
});
document.getElementById('apply-suggest')?.addEventListener('click', function () {
    document.querySelectorAll('.lvl').forEach(function (s) { if (s.dataset.suggest) s.value = s.dataset.suggest; });
});
</script>
@include('admin.blocks.footer')
