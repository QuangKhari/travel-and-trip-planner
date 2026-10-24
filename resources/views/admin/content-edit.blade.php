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

            @if (!$isNew)
                <div class="x_panel">
                    <div class="x_title">
                        <h2>Trạng thái: <span class="label label-{{ $labelOf($row->reviewStatus) }}">{{ $statuses[$row->reviewStatus] }}</span>
                            @if (!empty($row->reviewedAt))
                                <small>· {{ $adminNames[$row->reviewedBy] ?? 'không rõ' }} · {{ \Illuminate\Support\Carbon::parse($row->reviewedAt)->format('d/m/Y H:i') }}</small>
                            @endif
                        </h2>
                        <div class="clearfix"></div>
                    </div>
                    <div class="x_content">
                        @if ($row->reviewStatus === 'draft' && !empty($row->reviewNote))
                            <div class="alert alert-warning"><strong>Bị từ chối:</strong> {{ $row->reviewNote }}</div>
                        @endif
                        @forelse ($problems as $p)
                            <div class="text-danger">• {{ $p }}</div>
                        @empty
                            <div class="text-success">Đủ điều kiện xuất bản.</div>
                        @endforelse

                        <div style="margin-top:12px">
                            <form method="POST" action="{{ route('admin.content.status', [$kind, $id]) }}" class="form-inline" style="display:inline">
                                @csrf
                                <button name="status" value="reviewed" class="btn btn-info">Đánh dấu đã xem</button>
                                <button name="status" value="published" class="btn btn-success">Xuất bản</button>
                                <button name="status" value="draft" class="btn btn-default">Về nháp</button>
                            </form>
                            <a class="btn btn-default" href="{{ route('admin.content.preview', [$kind, $id]) }}" target="_blank">Xem trước</a>
                            @if ($kind === 'itineraries')
                                <a class="btn btn-info" href="{{ route('admin.itinerary.items', $id) }}">Soạn điểm dừng ({{ $itemCount }})</a>
                            @endif
                        </div>

                        <form method="POST" action="{{ route('admin.content.reject', [$kind, $id]) }}" class="form-inline" style="margin-top:10px">
                            @csrf
                            <input type="text" name="reason" class="form-control" style="width:340px" placeholder="Lý do từ chối (bắt buộc)" required minlength="5" maxlength="255">
                            <button class="btn btn-danger">Từ chối</button>
                        </form>

                        @if ($row->reviewStatus !== 'published')
                            <form method="POST" action="{{ route('admin.content.destroy', [$kind, $id]) }}" style="margin-top:10px"
                                onsubmit="return confirm('Xóa nội dung này? Không hoàn tác được.')">
                                @csrf
                                <button class="btn btn-link text-danger" style="padding-left:0">Xóa nội dung này</button>
                            </form>
                        @endif
                    </div>
                </div>
            @endif

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

            <div class="x_panel">
                <div class="x_title"><h2>Thông tin</h2><div class="clearfix"></div></div>
                <div class="x_content">
                    <form method="POST" enctype="multipart/form-data"
                        action="{{ $isNew ? route('admin.content.store', $kind) : route('admin.content.update', [$kind, $id]) }}">
                        @csrf

                        @if ($cfg['slug'])
                            <div class="form-group @error('slug') has-error @enderror">
                                <label>Slug (đường dẫn)</label>
                                <input type="text" name="slug" value="{{ old('slug', $row->slug ?? '') }}" class="form-control"
                                    placeholder="{{ $isNew ? 'để trống sẽ tự sinh từ tiêu đề' : '' }}">
                                <small class="text-muted">Chữ thường không dấu, nối bằng gạch ngang. Đổi slug sẽ đổi địa chỉ trang công khai (link cũ sẽ báo 404).</small>
                                @error('slug')<span class="help-block">{{ $message }}</span>@enderror
                            </div>
                        @endif

                        @foreach ($cfg['fields'] as $name => $f)
                            @php
                                $label = $f[0];
                                $type = $f[1];
                                $val = old($name, $row->$name ?? null);
                            @endphp
                            <div class="form-group @error($name) has-error @enderror">
                                <label>{{ $label }}</label>
                                @if ($type === 'textarea')
                                    <textarea name="{{ $name }}" rows="{{ $name === 'body' ? 16 : 6 }}" class="form-control">{{ $val }}</textarea>
                                @elseif ($type === 'dest')
                                    <select name="{{ $name }}" class="form-control">
                                        <option value="">(chưa gắn điểm đến)</option>
                                        @foreach ($destinations as $d)
                                            <option value="{{ $d->destinationId }}" @selected((string) $val === (string) $d->destinationId)>{{ $d->name }}{{ $d->isActive ? '' : ' (đã tắt)' }}</option>
                                        @endforeach
                                    </select>
                                @elseif ($type === 'category')
                                    <select name="{{ $name }}" class="form-control">
                                        <option value="">(chưa có danh mục, hiện ở mục "Khác")</option>
                                        @foreach ($categories as $c)
                                            <option value="{{ $c->categoryId }}" @selected((string) $val === (string) $c->categoryId)>{{ $c->name }}</option>
                                        @endforeach
                                    </select>
                                @elseif ($type === 'enum')
                                    <select name="{{ $name }}" class="form-control">
                                        <option value="">(không chọn)</option>
                                        @foreach ($f[3] as $code => $text)
                                            <option value="{{ $code }}" @selected((string) $val === (string) $code)>{{ $text }}</option>
                                        @endforeach
                                    </select>
                                @elseif ($type === 'bool')
                                    <div class="checkbox">
                                        <label>
                                            <input type="hidden" name="{{ $name }}" value="0">
                                            <input type="checkbox" name="{{ $name }}" value="1" @checked((int) $val === 1)> Bật
                                        </label>
                                    </div>
                                @elseif ($type === 'number')
                                    <input type="number" name="{{ $name }}" value="{{ $val }}" class="form-control">
                                @else
                                    <input type="text" name="{{ $name }}" value="{{ $val }}" class="form-control">
                                @endif
                                @error($name)<span class="help-block">{{ $message }}</span>@enderror
                            </div>
                        @endforeach

                        @if ($cfg['cover'])
                            <div class="form-group @error('cover') has-error @enderror">
                                <label>Ảnh bìa</label>
                                @if (!empty($row->coverImage))
                                    <p><img src="{{ \App\Support\DiscoverImage::url($row->coverImage) }}" alt="" style="max-height:140px;border-radius:4px"></p>
                                    <div class="checkbox">
                                        <label><input type="checkbox" name="removeCover" value="1"> Xóa ảnh bìa hiện tại</label>
                                    </div>
                                @else
                                    <p class="text-muted">Chưa có ảnh bìa.</p>
                                @endif
                                <input type="file" name="cover" accept="image/jpeg,image/png,image/webp" class="form-control">
                                <small class="text-muted">JPG/PNG/WebP, tự đổi sang WebP ≤ 160 KB. Tải ảnh lên thì nhớ điền "Ghi công ảnh".</small>
                                @error('cover')<span class="help-block">{{ $message }}</span>@enderror
                            </div>
                        @endif

                        <button class="btn btn-primary">{{ $isNew ? 'Tạo (ở trạng thái Nháp)' : 'Lưu' }}</button>
                        <a class="btn btn-default" href="{{ route('admin.content', $kind) }}">Quay lại danh sách</a>
                    </form>
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
@include('admin.blocks.footer')
