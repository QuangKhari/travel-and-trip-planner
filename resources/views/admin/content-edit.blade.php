@include('admin.blocks.header')

<div class="container body">
    <div class="main_container">

        @include('admin.blocks.sidebar')

        <div class="right_col" role="main">

            <div class="page-title">
                <div class="title_left">
                    <h3>Sửa <small>{{ $cfg['label'] }} #{{ $id }}</small></h3>
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

            <div class="x_panel">
                <div class="x_title">
                    <h2>Trạng thái: <span class="label label-default">{{ $statuses[$row->reviewStatus] }}</span></h2>
                    <div class="clearfix"></div>
                </div>
                <div class="x_content">
                    @foreach ($problems as $p)
                        <div class="text-danger">• {{ $p }}</div>
                    @endforeach

                    @if (!empty($row->coverImage))
                        <p style="margin-top:10px"><img src="{{ \App\Support\DiscoverImage::url($row->coverImage) }}" alt="" style="max-height:140px;border-radius:4px"></p>
                    @endif

                    <form method="POST" action="{{ route('admin.content.update', [$kind, $id]) }}" style="margin-top:12px">
                        @csrf
                        @foreach ($cfg['fields'] as $name => [$label, $type])
                            <div class="form-group">
                                <label>{{ $label }}</label>
                                @if ($type === 'textarea')
                                    <textarea name="{{ $name }}" rows="6" class="form-control">{{ old($name, $row->$name) }}</textarea>
                                @else
                                    <input type="{{ $type === 'number' ? 'number' : 'text' }}" name="{{ $name }}" value="{{ old($name, $row->$name) }}" class="form-control">
                                @endif
                            </div>
                        @endforeach
                        <button class="btn btn-primary">Lưu</button>
                        <a class="btn btn-default" href="{{ route('admin.content', $kind) }}">Quay lại danh sách</a>
                    </form>

                    <hr>
                    <form method="POST" action="{{ route('admin.content.status', [$kind, $id]) }}" class="form-inline">
                        @csrf
                        <button name="status" value="reviewed" class="btn btn-info">Đánh dấu đã xem</button>
                        <button name="status" value="published" class="btn btn-success">Xuất bản</button>
                        <button name="status" value="draft" class="btn btn-default">Về nháp</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@include('admin.blocks.footer')
