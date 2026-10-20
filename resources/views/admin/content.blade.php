@include('admin.blocks.header')

<div class="container body">
    <div class="main_container">

        @include('admin.blocks.sidebar')

        <div class="right_col" role="main">

            <div class="page-title">
                <div class="title_left">
                    <h3>Duyệt <small>nội dung Khám phá</small></h3>
                </div>
            </div>
            <div class="clearfix"></div>

            @if (session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif
            @if (session('error'))
                <div class="alert alert-danger">{{ session('error') }}</div>
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
                            <a class="label label-{{ $s === 'published' ? 'success' : ($s === 'reviewed' ? 'info' : 'default') }}"
                               href="{{ route('admin.content', ['kind' => $kind, 'status' => $s] + request()->only('destination')) }}">
                                {{ $label }}: {{ $counts[$s] ?? 0 }}</a>
                        @endforeach
                        <a href="{{ route('admin.content', $kind) }}">Bỏ lọc</a>
                    </p>

                    <form method="GET" action="{{ route('admin.content', $kind) }}" class="form-inline" style="margin-bottom:12px">
                        <input type="hidden" name="status" value="{{ $status }}">
                        @if ($cfg['dest'])
                            <select name="destination" class="form-control">
                                <option value="">Tất cả điểm đến</option>
                                @foreach ($destinations as $d)
                                    <option value="{{ $d->slug }}" @selected($destSlug === $d->slug)>{{ $d->name }}</option>
                                @endforeach
                            </select>
                        @endif
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
                        </div>

                        <table class="table table-striped table-bordered">
                            <thead>
                                <tr>
                                    <th style="width:30px"><input type="checkbox" id="check-all"></th>
                                    <th>Tên</th>
                                    @if ($cfg['dest'])<th>Điểm đến</th>@endif
                                    <th>Nội dung</th>
                                    @if ($kind !== 'places')<th>Giấy phép</th>@endif
                                    <th>Trạng thái</th>
                                    <th>Việc cần làm</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($rows as $r)
                                    @php $id = $r->{$cfg['key']}; @endphp
                                    <tr>
                                        <td><input type="checkbox" name="ids[]" value="{{ $id }}" class="row-check"></td>
                                        <td>{{ $r->{$cfg['title']} }}</td>
                                        @if ($cfg['dest'])<td>{{ $r->destName ?? '(chung)' }}</td>@endif
                                        <td>{{ \Illuminate\Support\Str::limit(strip_tags((string) ($r->{$cfg['body']} ?? '')), 90) }}</td>
                                        @if ($kind !== 'places')<td>{{ $r->license }}</td>@endif
                                        <td>
                                            <span class="label label-{{ $r->reviewStatus === 'published' ? 'success' : ($r->reviewStatus === 'reviewed' ? 'info' : 'default') }}">{{ $statuses[$r->reviewStatus] }}</span>
                                        </td>
                                        <td>
                                            @foreach ($problems[$id] as $p)
                                                <div class="text-danger" style="font-size:12px">• {{ $p }}</div>
                                            @endforeach
                                        </td>
                                        <td>
                                            @if ($cfg['fields'])
                                                <a class="btn btn-xs btn-primary" href="{{ route('admin.content.edit', [$kind, $id]) }}">Sửa</a>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="8" class="text-center">Không có dữ liệu.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </form>

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
</script>
@include('admin.blocks.footer')
