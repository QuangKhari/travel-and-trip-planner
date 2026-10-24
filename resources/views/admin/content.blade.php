@include('admin.blocks.header')

@php
    $keep = fn(array $extra = [], array $drop = []) => array_merge(['kind' => $kind], array_diff_key(request()->except('page'), array_flip($drop)), $extra);
    $sortUrl = fn(string $col) => route('admin.content', $keep(['sort' => $col, 'dir' => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc'], ['sort', 'dir']));
    $arrow = fn(string $col) => $sort === $col ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
    $labelOf = fn($s) => $s === 'published' ? 'success' : ($s === 'reviewed' ? 'info' : 'default');
@endphp

<div class="container body">
    <div class="main_container">

        @include('admin.blocks.sidebar')

        <div class="right_col" role="main">

            <div class="page-title">
                <div class="title_left">
                    <h3>Duyệt <small>nội dung Khám phá</small></h3>
                </div>
                <div class="title_right text-right">
                    @if ($cfg['create'])
                        <a class="btn btn-success" href="{{ route('admin.content.create', $kind) }}"><i class="fa fa-plus"></i> Thêm {{ mb_strtolower($cfg['label']) }}</a>
                    @endif
                    <form method="POST" action="{{ route('admin.content.rag') }}" style="display:inline">
                        @csrf
                        <button class="btn {{ $ragDirty ? 'btn-warning' : 'btn-default' }}"
                            title="Nội dung vừa duyệt chỉ trả lời được trong Hỏi đáp sau khi dựng lại chỉ mục (tự chạy mỗi 10 phút nếu bật scheduler)">
                            <i class="fa fa-refresh"></i> Cập nhật chỉ mục Hỏi đáp{{ $ragDirty ? ' (có nội dung mới)' : '' }}
                        </button>
                    </form>
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
                    @foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach
                </div>
            @endif

            <div class="x_panel">
                <div class="x_title">
                    <ul class="nav nav-tabs" style="border-bottom:0">
                        @foreach ($kinds as $k => $c)
                            <li class="{{ $k === $kind ? 'active' : '' }}"><a href="{{ route('admin.content', $k) }}">{{ $c['label'] }}</a></li>
                        @endforeach
                    </ul>
                    <div class="clearfix"></div>
                </div>

                <div class="x_content">
                    <p>
                        @foreach ($statuses as $s => $label)
                            <a class="label label-{{ $labelOf($s) }}" style="{{ $status === $s ? 'outline:2px solid #333' : '' }}"
                                href="{{ route('admin.content', $keep(['status' => $s], ['status'])) }}">
                                {{ $label }}: {{ $counts[$s] ?? 0 }}</a>
                        @endforeach
                        <a href="{{ route('admin.content', $kind) }}">Bỏ tất cả bộ lọc</a>
                    </p>

                    <form method="GET" action="{{ route('admin.content', $kind) }}" class="form-inline" style="margin-bottom:12px">
                        <input type="hidden" name="status" value="{{ $status }}">
                        <input type="hidden" name="sort" value="{{ $sort }}">
                        <input type="hidden" name="dir" value="{{ $dir }}">
                        @if ($cfg['dest'])
                            <select name="destination" class="form-control">
                                <option value="">Tất cả điểm đến</option>
                                <option value="_none" @selected($destSlug === '_none')>(chưa gắn điểm đến)</option>
                                @foreach ($destinations as $d)
                                    <option value="{{ $d->slug }}" @selected($destSlug === $d->slug)>{{ $d->name }}</option>
                                @endforeach
                            </select>
                        @endif
                        @if ($cfg['dest'] || $kind === 'itineraries')
                            <select name="mien" class="form-control">
                                <option value="">Mọi miền</option>
                                @foreach ($regions as $code => $name)
                                    <option value="{{ $code }}" @selected($mien === $code)>{{ $name }}</option>
                                @endforeach
                            </select>
                        @endif
                        @if ($kind === 'guides')
                            <select name="category" class="form-control">
                                <option value="">Mọi danh mục</option>
                                <option value="_none" @selected($categoryFilter === '_none')>(chưa có danh mục)</option>
                                @foreach ($categories as $c)
                                    <option value="{{ $c->categoryId }}" @selected($categoryFilter === (string) $c->categoryId)>{{ $c->name }}</option>
                                @endforeach
                            </select>
                        @endif
                        <select name="filter" class="form-control">
                            <option value="">Mọi tình trạng</option>
                            <option value="problem" @selected($filter === 'problem')>Chưa đạt điều kiện xuất bản</option>
                            @if ($cfg['cover'])<option value="noimage" @selected($filter === 'noimage')>Thiếu ảnh bìa</option>@endif
                            @if ($cfg['legal'])<option value="nolicense" @selected($filter === 'nolicense')>Thiếu giấy phép</option>@endif
                        </select>
                        <input type="text" name="q" value="{{ $search }}" class="form-control" placeholder="Tìm theo tên">
                        <button class="btn btn-default">Lọc</button>
                    </form>

                    <form method="POST" action="{{ route('admin.content.bulk', $kind) }}" id="bulk-form">
                        @csrf
                        <div style="margin-bottom:10px">
                            <span>Với các dòng đã chọn:</span>
                            <button name="status" value="reviewed" class="btn btn-info btn-sm">Đánh dấu đã xem</button>
                            <button name="status" value="published" class="btn btn-success btn-sm">Xuất bản</button>
                            <button name="status" value="draft" class="btn btn-default btn-sm">Về nháp</button>
                            <span style="margin-left:12px">
                                <input type="text" name="reason" class="form-control input-sm" style="display:inline-block;width:240px" placeholder="Lý do từ chối (bắt buộc khi từ chối)">
                                <button name="action" value="reject" class="btn btn-danger btn-sm">Từ chối</button>
                            </span>
                        </div>

                        <div class="table-responsive">
                        <table class="table table-striped table-bordered">
                            <thead>
                                <tr>
                                    <th style="width:30px"><input type="checkbox" id="check-all"></th>
                                    <th><a href="{{ $sortUrl('title') }}">Tên{!! $arrow('title') !!}</a></th>
                                    @if ($cfg['dest'])<th>Điểm đến</th>@endif
                                    <th>Nội dung</th>
                                    @if ($cfg['legal'])<th>Giấy phép</th>@endif
                                    <th><a href="{{ $sortUrl('status') }}">Trạng thái{!! $arrow('status') !!}</a></th>
                                    <th>Việc cần làm</th>
                                    <th><a href="{{ $sortUrl('updated') }}">Cập nhật{!! $arrow('updated') !!}</a></th>
                                    <th style="width:150px"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($rows as $r)
                                    @php $id = $r->{$cfg['key']}; @endphp
                                    <tr>
                                        <td><input type="checkbox" name="ids[]" value="{{ $id }}" class="row-check"></td>
                                        <td>
                                            {{ $r->{$cfg['title']} }}
                                            @if (!empty($r->isMustTry))<span class="label label-warning" title="Phải thử">Phải thử</span>@endif
                                            @if (!empty($r->isFeatured))<span class="label label-primary" title="Nổi bật">Nổi bật</span>@endif
                                            @if ($cfg['slug'])<br><small class="text-muted">{{ $r->slug }}</small>@endif
                                        </td>
                                        @if ($cfg['dest'])
                                            <td>
                                                @if ($r->destName)
                                                    {{ $r->destName }}
                                                @else
                                                    <span class="text-danger">chưa gắn</span>
                                                @endif
                                            </td>
                                        @endif
                                        <td>{{ \Illuminate\Support\Str::limit(strip_tags((string) ($r->{$cfg['body']} ?? '')), 90) }}</td>
                                        @if ($cfg['legal'])<td>{{ $r->license }}</td>@endif
                                        <td>
                                            <span class="label label-{{ $labelOf($r->reviewStatus) }}">{{ $statuses[$r->reviewStatus] }}</span>
                                            @if (!empty($r->reviewedAt))
                                                <br><small class="text-muted">{{ $adminNames[$r->reviewedBy] ?? 'không rõ' }} · {{ \Illuminate\Support\Carbon::parse($r->reviewedAt)->format('d/m H:i') }}</small>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($r->reviewStatus === 'draft' && !empty($r->reviewNote))
                                                <div class="text-warning" style="font-size:12px"><strong>Bị từ chối:</strong> {{ $r->reviewNote }}</div>
                                            @endif
                                            @foreach ($problems[$id] as $p)
                                                <div class="text-danger" style="font-size:12px">• {{ $p }}</div>
                                            @endforeach
                                        </td>
                                        <td>
                                            <small>{{ \Illuminate\Support\Carbon::parse($r->{$cfg['updatedCol']} ?? $r->createdAt ?? now())->format('d/m/Y') }}</small>
                                        </td>
                                        <td>
                                            <a class="btn btn-xs btn-primary" href="{{ route('admin.content.edit', [$kind, $id]) }}">Sửa</a>
                                            <a class="btn btn-xs btn-default" href="{{ route('admin.content.preview', [$kind, $id]) }}" target="_blank">Xem trước</a>
                                            @if ($kind === 'itineraries')
                                                <a class="btn btn-xs btn-info" href="{{ route('admin.itinerary.items', $id) }}">Điểm dừng</a>
                                            @endif
                                            @if ($r->reviewStatus !== 'published')
                                                <button type="button" class="btn btn-xs btn-danger js-delete"
                                                    data-url="{{ route('admin.content.destroy', [$kind, $id]) }}"
                                                    data-name="{{ $r->{$cfg['title']} }}">Xóa</button>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="9" class="text-center">Không có dữ liệu.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                        </div>
                    </form>

                    <form method="POST" id="delete-form" style="display:none">@csrf</form>

                    <div>
                        @if ($rows->previousPageUrl())<a class="btn btn-default btn-sm" href="{{ $rows->previousPageUrl() }}">« Trước</a>@endif
                        <span>Trang {{ $rows->currentPage() }}/{{ $rows->lastPage() }} ({{ $rows->total() }} dòng)</span>
                        @if ($rows->nextPageUrl())<a class="btn btn-default btn-sm" href="{{ $rows->nextPageUrl() }}">Sau »</a>@endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.getElementById('check-all')?.addEventListener('change', function () {
        document.querySelectorAll('.row-check').forEach(function (c) { c.checked = this.checked; }, this);
    });
    document.querySelectorAll('.js-delete').forEach(function (b) {
        b.addEventListener('click', function () {
            if (!confirm('Xóa "' + b.dataset.name + '"? Không hoàn tác được.')) return;
            var f = document.getElementById('delete-form');
            f.action = b.dataset.url;
            f.submit();
        });
    });
    // Từ chối: bắt buộc nhập lý do trước khi gửi
    document.querySelector('#bulk-form button[value="reject"]')?.addEventListener('click', function (e) {
        var r = document.querySelector('#bulk-form input[name="reason"]');
        if (!r.value.trim() || r.value.trim().length < 5) { e.preventDefault(); alert('Nhập lý do từ chối (tối thiểu 5 ký tự).'); r.focus(); }
    });
</script>
@include('admin.blocks.footer')
