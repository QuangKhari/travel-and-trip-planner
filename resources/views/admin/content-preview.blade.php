@include('admin.blocks.header')

<div class="container body">
    <div class="main_container">

        @include('admin.blocks.sidebar')

        <div class="right_col" role="main">

            <div class="page-title">
                <div class="title_left">
                    <h3>Xem trước <small>{{ $cfg['label'] }} #{{ $id }}</small></h3>
                </div>
                <div class="title_right text-right">
                    <a class="btn btn-primary" href="{{ route('admin.content.edit', [$kind, $id]) }}">Sửa</a>
                    <a class="btn btn-default" href="{{ route('admin.content', $kind) }}">« Danh sách</a>
                </div>
            </div>
            <div class="clearfix"></div>

            <div class="alert alert-info">
                Đây là bản xem trước trong admin ({{ $statuses[$row->reviewStatus] }}). Trang công khai chỉ hiện khi đã xuất bản.
            </div>
            @foreach ($problems as $p)
                <div class="text-danger">• {{ $p }}</div>
            @endforeach

            <div class="x_panel">
                <div class="x_content" style="max-width:820px">
                    @if (!empty($row->coverImage))
                        <p><img src="{{ \App\Support\DiscoverImage::url($row->coverImage) }}" alt="" style="max-width:100%;border-radius:6px"></p>
                        @if (!empty($row->imageCredit))<p class="text-muted"><small>Ảnh: {{ $row->imageCredit }}</small></p>@endif
                    @endif

                    <h2>{{ $row->{$cfg['title']} }}</h2>
                    @if ($dest)<p class="text-muted">Điểm đến: {{ $dest->name }}, {{ $dest->province }}</p>@endif

                    @if ($kind === 'guides')
                        <div class="guide-preview">{!! $rendered['html'] !!}</div>
                    @elseif ($kind === 'itineraries')
                        <p>{{ $row->summary }}</p>
                        @forelse ($days as $no => $items)
                            <h4>Ngày {{ $no }}</h4>
                            <ol>
                                @foreach ($items as $it)
                                    <li>{{ $it->label }}@if ($it->note) <span class="text-muted">· {{ $it->note }}</span>@endif</li>
                                @endforeach
                            </ol>
                        @empty
                            <p class="text-muted">Chưa có điểm dừng nào.</p>
                        @endforelse
                    @elseif ($kind === 'places')
                        <p>{{ $row->address }}</p>
                        <p class="text-muted">{{ $row->category }} · {{ $row->kind }} · {{ $row->latitude }}, {{ $row->longitude }}</p>
                    @else
                        <p style="white-space:pre-wrap">{{ $row->description }}</p>
                    @endif

                    @if ($kind === 'events')
                        <h4>Ngày diễn ra sắp tới</h4>
                        @forelse ($dates as $d)
                            <div>
                                {{ \Illuminate\Support\Carbon::parse($d['start'])->format('d/m/Y') }} → {{ \Illuminate\Support\Carbon::parse($d['end'])->format('d/m/Y') }}
                                @if ($d['approx'])<span class="label label-warning">chỉ biết tháng</span>@endif
                            </div>
                        @empty
                            <div class="text-danger">Không tính ra được ngày.</div>
                        @endforelse
                        @if (!empty($row->dateNote))<p class="text-muted">{{ $row->dateNote }}</p>@endif
                    @endif

                    @if (!empty($row->license))
                        <hr>
                        <small class="text-muted">Giấy phép: {{ $row->license }}@if (!empty($row->sourceUrl)) · Nguồn: <a href="{{ $row->sourceUrl }}" target="_blank" rel="noopener">{{ $row->sourceUrl }}</a>@endif</small>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@include('admin.blocks.footer')
