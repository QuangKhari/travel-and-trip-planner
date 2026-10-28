@include('admin.blocks.header')

@php
    $labelOf = fn($s) => $s === 'published' ? 'success' : ($s === 'reviewed' ? 'info' : 'default');
@endphp

<div class="container body">
    <div class="main_container">

        @include('admin.blocks.sidebar')

        <div class="right_col" role="main">

            <div class="page-title">
                <div class="title_left">
                    <h3>{{ $isNew ? 'Thêm' : 'Sửa' }} <small>{{ $cfg['label'] }}{{ $isNew ? '' : ' #' . $id }}</small></h3>
                </div>
                <div class="title_right text-right">
                    <a class="btn btn-default" href="{{ route('admin.content', $kind) }}">« Quay lại danh sách</a>
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
                <div class="alert alert-danger"><ul style="margin-bottom:0">
                    @foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach
                </ul></div>
            @endif

            @php
                $friendly = [
                    'Thiếu giấy phép nội dung' => ['Chọn nguồn gốc nội dung (Travela tự viết, hoặc dựa theo nguồn khác).', 'src'],
                    'Thiếu liên kết nguồn (sourceUrl)' => ['Nội dung dựa theo nguồn khác thì cần dán liên kết nguồn.', 'src'],
                    'Có ảnh nhưng thiếu ghi công ảnh' => ['Ảnh cần ghi nguồn: chọn "Ảnh Travela tự chụp" hoặc ghi tác giả.', 'cover'],
                    'Nội dung/mô tả quá ngắn (dưới 30 ký tự)' => ['Mô tả còn ngắn, cần từ 30 ký tự.', 'f_' . ($cfg['body'] ?? 'description')],
                    'Món ăn phải gắn với một điểm đến' => ['Chọn điểm đến.', 'f_destinationId'],
                    'Trải nghiệm phải gắn với một điểm đến' => ['Chọn điểm đến.', 'f_destinationId'],
                ];
                $legalOn = $cfg['legal'] && isset($cfg['fields']['license']);
                $hasCover = !empty($row->coverImage);
                // Giá trị ban đầu của hai lựa chọn nguồn gốc
                $lic = old('license', $row->license ?? null);
                $prov = old('provenance', $isNew ? 'own' : ($lic === $ownLicense ? 'own' : (!empty($lic) ? 'other' : '')));
                $cred = old('imageCredit', $row->imageCredit ?? null);
                $credMode = old('imageCreditMode', $isNew ? 'own' : ($cred === $ownCredit ? 'own' : (!empty($cred) ? 'other' : ($hasCover ? '' : 'own'))));
                $groups = $cfg['groups'] ?? [];
                $inGroup = array_merge(...array_values($groups ?: [[]]));
                $mainFields = array_filter($cfg['fields'], fn($f, $n) => !in_array($n, $inGroup, true) && !in_array($n, ['sourceUrl', 'license', 'imageCredit'], true), ARRAY_FILTER_USE_BOTH);
                $pick = fn(string $g) => array_filter($cfg['fields'], fn($f, $n) => in_array($n, $groups[$g] ?? [], true), ARRAY_FILTER_USE_BOTH);
                $fieldVars = compact('row', 'kind', 'destinations', 'categories', 'categoryHints', 'isNew');
            @endphp

            @if ($kind === 'events' && !$isNew)
                <div class="x_panel">
                    <div class="x_title"><h2>Ngày dương tính ra từ quy tắc</h2><div class="clearfix"></div></div>
                    <div class="x_content">
                        @forelse ($dates as $d)
                            <div>
                                {{ \Illuminate\Support\Carbon::parse($d['start'])->format('d/m/Y') }}
                                → {{ \Illuminate\Support\Carbon::parse($d['end'])->format('d/m/Y') }}
                                @if ($d['approx'])<span class="label label-warning">chỉ biết tháng</span>@endif
                            </div>
                        @empty
                            <div class="text-danger">Quy tắc hiện tại không tính ra được ngày nào. Kiểm tra lại tháng/ngày âm hoặc dương.</div>
                        @endforelse
                        <small class="text-muted">Đối chiếu với lịch âm: nếu sai ngày thì sửa quy tắc bên dưới, KHÔNG sửa ngày dương (hệ thống chỉ lưu quy tắc).</small>
                    </div>
                </div>
            @endif

            <div class="row">
                <div class="col-md-8">
                    <form id="contentForm" method="POST" enctype="multipart/form-data"
                        action="{{ $isNew ? route('admin.content.store', $kind) : route('admin.content.update', [$kind, $id]) }}">
                        @csrf

                        <div class="x_panel">
                            <div class="x_title"><h2>1. Thông tin chính</h2><div class="clearfix"></div></div>
                            <div class="x_content">
                                @foreach ($mainFields as $name => $f)
                                    @include('admin.partials.content-field', ['name' => $name, 'f' => $f] + $fieldVars)
                                @endforeach
                            </div>
                        </div>

                        @if (!empty($groups['when']))
                            <div class="x_panel">
                                <div class="x_title"><h2>Thời gian diễn ra</h2><div class="clearfix"></div></div>
                                <div class="x_content">
                                    @foreach ($pick('when') as $name => $f)
                                        @include('admin.partials.content-field', ['name' => $name, 'f' => $f] + $fieldVars)
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        @if (!empty($groups['planner']))
                            <div class="x_panel">
                                <div class="x_title"><h2>Để Planner xếp lịch được</h2><div class="clearfix"></div></div>
                                <div class="x_content">
                                    <p class="text-muted">Vẫn xuất bản được nếu để trống, nhưng trải nghiệm sẽ không được Planner gợi ý.</p>
                                    @foreach ($pick('planner') as $name => $f)
                                        @include('admin.partials.content-field', ['name' => $name, 'f' => $f] + $fieldVars)
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        @if ($cfg['cover'])
                            <div class="x_panel" id="cover">
                                <div class="x_title"><h2>2. Ảnh bìa <small>(không bắt buộc)</small></h2><div class="clearfix"></div></div>
                                <div class="x_content">
                                    @if ($hasCover)
                                        <input type="hidden" name="hasCover" value="1">
                                        <p><img src="{{ \App\Support\DiscoverImage::url($row->coverImage) }}" alt="" style="max-height:140px;border-radius:4px"></p>
                                        <div class="checkbox"><label><input type="checkbox" name="removeCover" value="1"> Xóa ảnh bìa hiện tại</label></div>
                                    @endif
                                    <div class="form-group @error('cover') has-error @enderror">
                                        <label for="f_cover">{{ $hasCover ? 'Thay bằng ảnh khác' : 'Chọn ảnh' }}</label>
                                        <input id="f_cover" type="file" name="cover" accept="image/jpeg,image/png,image/webp" class="form-control">
                                        <small class="text-muted">JPG, PNG hoặc WebP. Hệ thống tự thu nhỏ và đổi sang WebP (≤ 160 KB).</small>
                                        @error('cover')<span class="help-block">{{ $message }}</span>@enderror
                                    </div>
                                    @if (isset($cfg['fields']['imageCredit']))
                                        <div class="form-group @error('imageCredit') has-error @enderror" id="creditBox">
                                            <label>Ảnh này của ai?</label>
                                            <div>
                                                <label class="radio-inline"><input type="radio" name="imageCreditMode" value="own" @checked($credMode === 'own')> Ảnh Travela tự chụp / tự tạo</label>
                                                <label class="radio-inline"><input type="radio" name="imageCreditMode" value="other" @checked($credMode === 'other')> Ảnh lấy từ nguồn khác</label>
                                            </div>
                                            <div class="js-credit-other" style="margin-top:8px">
                                                <input type="text" name="imageCredit" value="{{ $cred !== $ownCredit ? $cred : '' }}" maxlength="255" class="form-control"
                                                    placeholder="Tác giả / nguồn, ví dụ: Nguyễn Văn A / Wikimedia Commons, CC BY-SA 4.0">
                                            </div>
                                            @error('imageCredit')<span class="help-block">{{ $message }}</span>@enderror
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endif

                        @if ($legalOn)
                            <div class="x_panel" id="src">
                                <div class="x_title"><h2>3. Nguồn gốc nội dung</h2><div class="clearfix"></div></div>
                                <div class="x_content">
                                    <p class="text-muted">Nội dung lấy từ Wikipedia, báo chí hay sách bắt buộc phải ghi nguồn và giấy phép. Bài do chính Travela viết thì chỉ cần chọn "tự viết".</p>
                                    <div class="form-group @error('license') has-error @enderror @error('sourceUrl') has-error @enderror">
                                        <div>
                                            <label class="radio-inline"><input type="radio" name="provenance" value="own" @checked($prov === 'own')> <b>Travela tự viết</b></label>
                                            <label class="radio-inline"><input type="radio" name="provenance" value="other" @checked($prov === 'other')> <b>Dựa theo nguồn khác</b></label>
                                        </div>
                                        <div class="js-src-other" style="margin-top:10px">
                                            <label for="f_sourceUrl">Liên kết nguồn</label>
                                            <input id="f_sourceUrl" type="text" name="sourceUrl" value="{{ old('sourceUrl', $row->sourceUrl ?? '') }}" class="form-control" placeholder="https://vi.wikipedia.org/wiki/...">
                                            @error('sourceUrl')<span class="help-block">{{ $message }}</span>@enderror
                                            <label for="f_license" style="margin-top:8px">Giấy phép của nguồn</label>
                                            <input id="f_license" type="text" name="license" value="{{ $lic !== $ownLicense ? $lic : '' }}" maxlength="80" class="form-control" list="licenseHints" placeholder="Chọn gợi ý hoặc tự gõ, ví dụ CC BY-SA 4.0">
                                            <datalist id="licenseHints">@foreach ($licenseHints as $h)<option value="{{ $h }}">@endforeach</datalist>
                                            @error('license')<span class="help-block">{{ $message }}</span>@enderror
                                            <small class="text-muted">Wikipedia tiếng Việt dùng CC BY-SA 4.0.</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endif

                        @if (!empty($groups['advanced']) || $cfg['slug'])
                            <details class="x_panel" style="padding:10px 14px">
                                <summary style="cursor:pointer;font-weight:600">Thông tin thêm (không bắt buộc)</summary>
                                <div style="margin-top:14px">
                                    @if ($cfg['slug'])
                                        <div class="form-group @error('slug') has-error @enderror">
                                            <label for="f_slug">Đường dẫn (slug)</label>
                                            <input id="f_slug" type="text" name="slug" value="{{ old('slug', $row->slug ?? '') }}" class="form-control"
                                                placeholder="{{ $isNew ? 'để trống: tự sinh từ tên' : '' }}">
                                            <small class="text-muted">Thường để trống. Đổi slug sẽ đổi địa chỉ trang công khai (link cũ báo 404).</small>
                                            @error('slug')<span class="help-block">{{ $message }}</span>@enderror
                                        </div>
                                    @endif
                                    @foreach ($pick('advanced') as $name => $f)
                                        @include('admin.partials.content-field', ['name' => $name, 'f' => $f] + $fieldVars)
                                    @endforeach
                                </div>
                            </details>
                        @endif
                    </form>
                </div>

                <div class="col-md-4">
                    <div class="x_panel" style="position:sticky;top:10px">
                        <div class="x_title">
                            <h2>{{ $isNew ? 'Lưu bài' : 'Xuất bản' }}
                                @if (!$isNew)<span class="label label-{{ $labelOf($row->reviewStatus) }}" style="margin-left:6px">{{ $statuses[$row->reviewStatus] }}</span>@endif
                            </h2>
                            <div class="clearfix"></div>
                        </div>
                        <div class="x_content">
                            @if (!$isNew && !empty($row->reviewedAt))
                                <p class="text-muted"><small>{{ $adminNames[$row->reviewedBy] ?? 'không rõ' }} · {{ \Illuminate\Support\Carbon::parse($row->reviewedAt)->format('d/m/Y H:i') }}</small></p>
                            @endif
                            @if (!$isNew && $row->reviewStatus === 'draft' && !empty($row->reviewNote))
                                <div class="alert alert-warning"><strong>Bị từ chối:</strong> {{ $row->reviewNote }}</div>
                            @endif

                            <strong>Trước khi xuất bản</strong>
                            @if ($isNew)
                                <ul class="list-unstyled" style="margin-top:6px">
                                    <li>• Mô tả từ 30 ký tự</li>
                                    <li>• Chọn nguồn gốc nội dung</li>
                                    @if (in_array($kind, ['foods', 'experiences', 'events'], true))<li>• Gắn với một điểm đến</li>@endif
                                    <li>• Có ảnh thì ảnh phải ghi nguồn</li>
                                </ul>
                                <p class="text-muted"><small>Chưa đủ cũng không sao: bấm "Lưu nháp" và bổ sung sau.</small></p>
                            @else
                                <ul class="list-unstyled" style="margin-top:6px">
                                    @forelse ($problems as $p)
                                        @php $fx = $friendly[$p] ?? [$p, null]; @endphp
                                        <li class="text-danger">✗ @if ($fx[1])<a href="#{{ $fx[1] }}">{{ $fx[0] }}</a>@else{{ $fx[0] }}@endif</li>
                                    @empty
                                        <li class="text-success">✓ Đủ điều kiện xuất bản.</li>
                                    @endforelse
                                </ul>
                                @if (!empty($plannerProblems))
                                    <div class="alert alert-info" style="margin-top:8px"><small><strong>Chưa vào được Planner</strong> (vẫn xuất bản được): {{ implode('; ', $plannerProblems) }}</small></div>
                                @endif
                            @endif

                            <div style="margin-top:12px">
                                @if ($isNew || $row->reviewStatus !== 'published')
                                    <button type="submit" form="contentForm" name="after" value="publish" class="btn btn-success btn-block">Lưu và xuất bản</button>
                                    <button type="submit" form="contentForm" class="btn btn-primary btn-block">{{ $isNew ? 'Lưu nháp' : 'Chỉ lưu thay đổi' }}</button>
                                @else
                                    <button type="submit" form="contentForm" class="btn btn-primary btn-block">Lưu thay đổi</button>
                                @endif
                                <a class="btn btn-default btn-block" href="{{ route('admin.content', $kind) }}">Quay lại danh sách</a>
                            </div>

                            @if (!$isNew)
                                <hr>
                                <a class="btn btn-default btn-sm" href="{{ route('admin.content.preview', [$kind, $id]) }}" target="_blank">Xem trước</a>
                                @if ($kind === 'itineraries')
                                    <a class="btn btn-info btn-sm" href="{{ route('admin.itinerary.items', $id) }}">Soạn điểm dừng ({{ $itemCount }})</a>
                                @endif
                                @if ($row->reviewStatus === 'published')
                                    <form method="POST" action="{{ route('admin.content.status', [$kind, $id]) }}" style="display:inline">
                                        @csrf
                                        <button name="status" value="draft" class="btn btn-default btn-sm">Về nháp</button>
                                    </form>
                                @endif
                                <details style="margin-top:10px">
                                    <summary style="cursor:pointer">Từ chối hoặc xóa</summary>
                                    <form method="POST" action="{{ route('admin.content.reject', [$kind, $id]) }}" style="margin-top:8px">
                                        @csrf
                                        <input type="text" name="reason" class="form-control" placeholder="Lý do từ chối (bắt buộc)" required minlength="5" maxlength="255">
                                        <button class="btn btn-danger btn-sm" style="margin-top:6px">Từ chối (về Nháp)</button>
                                    </form>
                                    @if ($row->reviewStatus !== 'published')
                                        <form method="POST" action="{{ route('admin.content.destroy', [$kind, $id]) }}" style="margin-top:6px"
                                            onsubmit="return confirm('Xóa nội dung này? Không hoàn tác được.')">
                                            @csrf
                                            <button class="btn btn-link text-danger" style="padding-left:0">Xóa nội dung này</button>
                                        </form>
                                    @endif
                                </details>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            @if (!empty($raw))
                <div class="x_panel">
                    <div class="x_title"><h2>Đối chiếu dữ liệu thô (Wikipedia)</h2><div class="clearfix"></div></div>
                    <div class="x_content">
                        @if (empty($raw['title']))
                            <p class="text-muted">Bản ghi chưa có liên kết nguồn Wikipedia nên không có dữ liệu thô để đối chiếu.</p>
                        @elseif (empty($raw['raw']))
                            <p class="text-muted">Chưa có dữ liệu thô cho bài "{{ $raw['title'] }}" trong tbl_import_raw (chạy content:import-articles).</p>
                        @else
                            <div class="row">
                                <div class="col-md-6">
                                    <strong>Đang lưu</strong>
                                    <div style="white-space:pre-wrap">{{ $row->description }}</div>
                                </div>
                                <div class="col-md-6">
                                    <strong>Bản gốc "{{ $raw['raw']['title'] ?? $raw['title'] }}"</strong>
                                    <div style="white-space:pre-wrap">{{ $raw['raw']['extract'] ?? '' }}</div>
                                    @if (!empty($raw['raw']['url']))<a href="{{ $raw['raw']['url'] }}" target="_blank" rel="noopener">Mở bài gốc</a>@endif
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            @if (!$isNew)
                <div class="x_panel">
                    <div class="x_title"><h2>Lịch sử</h2><div class="clearfix"></div></div>
                    <div class="x_content">
                        <table class="table table-condensed">
                            <thead><tr><th>Lúc</th><th>Ai</th><th>Việc</th><th>Chi tiết</th></tr></thead>
                            <tbody>
                                @forelse ($history as $h)
                                    <tr>
                                        <td>{{ \Illuminate\Support\Carbon::parse($h->createdAt)->format('d/m/Y H:i') }}</td>
                                        <td>{{ $adminNames[$h->adminId] ?? '—' }}</td>
                                        <td>
                                            {{ ['status' => 'Đổi trạng thái', 'reject' => 'Từ chối', 'create' => 'Tạo', 'update' => 'Sửa', 'delete' => 'Xóa'][$h->action] ?? $h->action }}
                                            @if ($h->fromStatus !== $h->toStatus)
                                                ({{ $statuses[$h->fromStatus] ?? '—' }} → {{ $statuses[$h->toStatus] ?? '—' }})
                                            @endif
                                        </td>
                                        <td>{{ $h->note }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-muted">Chưa có lịch sử.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>

<script>
(function () {
    "use strict";
    var form = document.getElementById("contentForm");
    if (!form) return;
    function val(name) { var c = form.querySelector('input[name="' + name + '"]:checked'); return c ? c.value : ""; }
    function show(sel, on) { form.querySelectorAll(sel).forEach(function (e) { e.style.display = on ? "" : "none"; }); }
    function sync() {
        show(".js-src-other", val("provenance") === "other");
        show(".js-credit-other", val("imageCreditMode") === "other");
        var lu = form.querySelector('input[name="isLunar"]');
        if (lu) { var l = val("isLunar") !== "0"; show(".js-lunar", l); show(".js-solar", !l); }
    }
    form.addEventListener("change", sync);
    sync();
})();
</script>
@include('admin.blocks.footer')
