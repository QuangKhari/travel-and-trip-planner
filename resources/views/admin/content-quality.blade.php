@include('admin.blocks.header')
@php
    $tone = fn(int $p) => $p >= 80 ? 'progress-bar-success' : ($p >= 50 ? 'progress-bar-warning' : 'progress-bar-danger');
    $q = fn(array $extra = []) => route('admin.content.quality', array_filter(array_merge(['scope' => $scope === 'all' ? 'all' : null, 'destination' => $focus['slug'] ?? null, 'code' => $code ?: null], $extra), fn($v) => $v !== null && $v !== ''));
    $editUrl = function (array $i) {
        return match ($i['kind']) {
            'destinations' => route('admin.destinations.edit', $i['id']),
            default => route('admin.content.edit', [$i['kind'], $i['id']]),
        };
    };
    $kindLabel = ['foods' => 'Món', 'experiences' => 'Trải nghiệm', 'events' => 'Lễ hội', 'itineraries' => 'Lịch trình', 'guides' => 'Cẩm nang', 'places' => 'Địa điểm', 'destinations' => 'Điểm đến'];
@endphp
<div class="container body"><div class="main_container">
@include('admin.blocks.sidebar')
<div class="right_col" role="main">
    <div class="page-title">
        <div class="title_left"><h3>Báo cáo nội dung <small>điểm từng điểm đến và lỗi cần sửa</small></h3></div>
        <div class="title_right text-right">
            <form method="POST" action="{{ route('admin.content.quality.snapshot') }}" style="display:inline">@csrf
                <button class="btn btn-default" type="submit" title="Lưu điểm hôm nay để theo dõi tiến độ theo thời gian">Lưu mốc hôm nay</button>
            </form>
        </div>
    </div>
    <div class="clearfix"></div>
    @include('admin.partials.report-tabs')
    @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif

    <div class="x_panel"><div class="x_content">
        <div class="row text-center">
            <div class="col-sm-4"><h2>{{ $avg }}%</h2><small title="Mức đầy đủ của nội dung đã xuất bản, tối đa 100%">Điểm đầy đủ trung bình ({{ count($scores) }} điểm đến)</small></div>
            <div class="col-sm-4"><h2>{{ $ready }}/{{ count($scores) }}</h2><small title="Đủ món, trải nghiệm, địa điểm và mùa để Planner gợi ý lịch trình">Điểm đến đủ dữ liệu để gợi ý lịch trình</small></div>
            <div class="col-sm-4"><h2>{{ array_sum($codeCounts) }}</h2><small title="Việc cần sửa: thiếu ảnh, thiếu nguồn, thiếu tọa độ...">Việc cần sửa</small></div>
        </div>
        <p class="text-muted" style="margin-top:12px">
            Điểm = Σ (điểm tối đa × min(1, có / mục tiêu)) chia tổng điểm các tiêu chí áp dụng, làm tròn về số nguyên; chỉ tính nội dung <b>đã xuất bản</b>.
            Ngưỡng ở <code>config/coverage.php</code>. Xem: @if ($hasCore)
                <a href="{{ route('admin.content.quality') }}" class="{{ $scope === 'core' ? 'text-bold' : '' }}">{{ count((array) config('core_destinations.slugs')) }} điểm đến lõi</a> ·
                <a href="{{ route('admin.content.quality', ['scope' => 'all']) }}" class="{{ $scope === 'all' ? 'text-bold' : '' }}">tất cả điểm đến</a>
            @else tất cả điểm đến (chưa khai báo điểm đến lõi trong <code>config/core_destinations.php</code>) @endif
        </p>
    </div></div>

    <div class="x_panel"><div class="x_title"><h2>Điểm từng điểm đến</h2><div class="clearfix"></div></div><div class="x_content">
        <div class="table-responsive"><table class="table table-condensed table-striped">
            <thead><tr><th>Điểm đến</th><th style="width:22%">Điểm</th><th title="Có đủ dữ liệu để Planner gợi ý lịch trình hay chưa">Gợi ý lịch trình</th><th title="đã duyệt">Địa điểm</th><th>Món</th><th>Trải nghiệm</th><th>Lễ hội</th><th>Lịch trình</th><th>Tour</th><th title="Số bài chưa xuất bản của điểm đến này">Chờ xuất bản</th><th>Lịch sử</th></tr></thead>
            <tbody>
            @foreach ($scores as $s)
                @php $n = $s['counts']; $c = $s['criteria']; @endphp
                <tr>
                    <td><a href="{{ $q(['destination' => $s['slug']]) }}">{{ $s['name'] }}</a></td>
                    <td><div class="progress" style="margin:0 0 2px"><div class="progress-bar {{ $tone($s['percent']) }}" style="width:{{ $s['percent'] }}%">{{ $s['percent'] }}%</div></div>
                        <small class="text-muted">{{ rtrim(rtrim(number_format($s['earned'], 1), '0'), '.') }}/{{ $s['applicable'] }} điểm @if (!$c['tours']['applicable']) · miễn tiêu chí tour @endif</small></td>
                    <td>@if ($s['plannerReady'])<span class="label label-success">Sẵn sàng</span>@else<span class="label label-default" title="{{ implode('; ', $s['plannerMissing']) }}">Chưa ({{ count($s['plannerMissing']) }} thiếu)</span>@endif</td>
                    <td class="{{ $n['placesCoords'] < 20 ? 'text-danger' : '' }}">{{ $n['places'] }} <small class="text-muted">/ tọa độ {{ $n['placesCoords'] }}</small></td>
                    <td class="{{ $n['foods'] < 6 ? 'text-danger' : '' }}">{{ $n['foods'] }}</td>
                    <td class="{{ $n['experiencesReady'] < 4 ? 'text-danger' : '' }}">{{ $n['experiences'] }} <small class="text-muted">/ đủ {{ $n['experiencesReady'] }}</small></td>
                    <td class="{{ $n['eventsDated'] < 1 ? 'text-danger' : '' }}">{{ $n['events'] }}</td>
                    <td class="{{ $n['itineraries'] < 1 ? 'text-danger' : '' }}">{{ $n['itineraries'] }}</td>
                    <td>{{ $n['tours'] }}</td>
                    <td>@foreach (['foods' => 'Món', 'experiences' => 'TN', 'events' => 'LH', 'places' => 'ĐĐ'] as $k => $lbl)@if (!empty($pending[$s['destinationId']][$k]))<a class="label label-info" style="margin-right:2px" href="{{ route('admin.content', [$k, 'destination' => $s['slug']]) }}" title="Mở danh sách {{ $lbl }} chưa xuất bản">{{ $lbl }} {{ $pending[$s['destinationId']][$k] }}</a>@endif @endforeach</td>
                    <td><small>{{ collect($history[$s['destinationId']] ?? [])->pluck('percent')->map(fn($p) => $p . '%')->implode(' → ') ?: '—' }}</small></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </div></div>

    @if ($focus)
        <div class="x_panel"><div class="x_title"><h2>{{ $focus['name'] }}: chi tiết điểm</h2><div class="clearfix"></div></div><div class="x_content">
            <div class="row"><div class="col-md-6">
                <table class="table table-condensed"><thead><tr><th>Tiêu chí</th><th>Có / mục tiêu</th><th>Điểm</th></tr></thead><tbody>
                @foreach ($focus['criteria'] as $k => $c)<tr class="{{ !$c['applicable'] ? 'text-muted' : '' }}"><td>{{ $c['label'] }}</td>
                    <td>{{ $c['have'] }}/{{ $c['target'] }}</td><td>{{ $c['applicable'] ? rtrim(rtrim(number_format($c['points'], 1), '0'), '.') . '/' . $c['max'] : 'miễn' }}</td></tr>@endforeach
                </tbody></table></div>
            <div class="col-md-6"><h5>Điều kiện để gợi ý lịch trình</h5>
                <ul class="list-unstyled">@foreach ($focus['planner'] as $p)<li>{!! $p['ok'] ? '<span class="text-success">✔</span>' : '<span class="text-danger">✘</span>' !!} {{ $p['label'] }}: {{ $p['have'] }}/{{ $p['need'] }}@if ($p['waiver']) <small class="text-muted">(miễn: {{ $p['waiver'] }})</small>@endif</li>@endforeach</ul>
            </div></div>
            <a href="{{ $q(['destination' => null]) }}">« Bỏ lọc điểm đến</a>
        </div></div>
    @endif

    <div class="x_panel"><div class="x_title"><h2>Việc cần sửa{{ $focus ? ' của ' . $focus['name'] : '' }}</h2><div class="clearfix"></div></div><div class="x_content">
        <p>
            <a class="label {{ $code === '' ? 'label-primary' : 'label-default' }}" href="{{ $q(['code' => null]) }}">Tất cả {{ array_sum($codeCounts) }}</a>
            @foreach ($codeCounts as $k => $cnt)<a class="label {{ $code === $k ? 'label-primary' : 'label-default' }}" href="{{ $q(['code' => $k]) }}">{{ $codes[$k] }} {{ $cnt }}</a> @endforeach
        </p>
        @if ($issues)
            <div class="table-responsive"><table class="table table-condensed table-striped"><thead><tr><th>Loại</th><th>Mục</th><th>Điểm đến</th><th>Vấn đề</th><th></th></tr></thead><tbody>
            @foreach ($issues as $i)<tr><td>{{ $kindLabel[$i['kind']] ?? $i['kind'] }}</td><td>{{ $i['title'] }}</td><td>{{ $names[$i['destinationId']] ?? '' }}</td><td>{{ $i['problem'] }}</td>
                <td><a class="btn btn-xs btn-default" href="{{ $editUrl($i) }}">Sửa</a></td></tr>@endforeach
            </tbody></table></div>
            @if ($issueTotal > $show)<p class="text-muted">Hiện {{ $show }} trên {{ $issueTotal }} lỗi; lọc theo điểm đến hoặc loại lỗi để xem phần còn lại.</p>@endif
        @else
            <span class="text-success">Không có lỗi dữ liệu nào trong phạm vi này.</span>
        @endif
    </div></div>
</div></div></div>
@include('admin.blocks.footer')
