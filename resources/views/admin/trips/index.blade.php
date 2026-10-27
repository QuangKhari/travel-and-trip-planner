@include('admin.blocks.header')
<div class="container body">
    <div class="main_container">
        @include('admin.blocks.sidebar')

        <div class="right_col" role="main">
            @include('admin.partials.flash')
            <div class="ad-head">
                <div>
                    <h1>Chuyến đi (Trip Planner)</h1>
                    <p>Các chuyến khách đã lập ({{ $counts->sum() }} chuyến, {{ number_format($itemTotal, 0, ',', '.') }} mục trong lịch trình). Admin chỉ xem và xóa chuyến vi phạm.</p>
                </div>
            </div>

            <div class="ad-card">
                <div class="ad-toolbar">
                    <form method="GET" class="ad-search">
                        <i class="fa fa-search"></i>
                        <input type="search" name="q" value="{{ $search }}" placeholder="Tên chuyến, tên hoặc email khách…">
                        @if ($status)<input type="hidden" name="status" value="{{ $status }}">@endif
                    </form>
                    <div class="ad-chips">
                        <a class="ad-chip {{ !$status ? 'is-on' : '' }}" href="{{ route('admin.trips') }}">Tất cả <b>{{ $counts->sum() }}</b></a>
                        @foreach ($statuses as $k => $l)
                            <a class="ad-chip {{ $status === $k ? 'is-on' : '' }}" href="{{ route('admin.trips', ['status' => $k]) }}">{{ $l }} <b>{{ $counts[$k] ?? 0 }}</b></a>
                        @endforeach
                    </div>
                </div>

                @if ($rows->count())
                    <div class="ad-tablewrap">
                        <table class="ad-table">
                            <thead><tr><th>Chuyến đi</th><th>Khách</th>@if ($hasDest)<th>Điểm đến</th>@endif<th>Khởi hành</th><th class="num">Ngày</th><th class="num">Mục</th><th>Trạng thái</th><th>Tạo lúc</th><th></th></tr></thead>
                            <tbody>
                            @foreach ($rows as $t)
                                <tr>
                                    <td style="min-width:200px"><b>{{ $t->title }}</b>
                                        @if ($hasShare && $t->shareToken)<br><a class="ad-note" href="{{ route('planner.shared', ['token' => $t->shareToken]) }}" target="_blank" rel="noopener"><i class="fa fa-external-link"></i> Bản chia sẻ</a>@endif
                                    </td>
                                    <td>{{ $t->fullName }}<br><small class="ad-note">{{ $t->email }}</small></td>
                                    @if ($hasDest)<td>{{ $t->destName ?: '—' }}</td>@endif
                                    <td>{{ $t->startDate ? \Illuminate\Support\Carbon::parse($t->startDate)->format('d/m/Y') : '—' }}</td>
                                    <td class="num">{{ $t->days ?: '—' }}</td>
                                    <td class="num">{{ $t->itemCount }}</td>
                                    <td><span class="ad-badge ad-badge--{{ ['draft' => 'mute', 'planned' => 'info', 'done' => 'ok'][$t->status] ?? 'mute' }}">{{ $statuses[$t->status] ?? $t->status }}</span></td>
                                    <td>{{ \Illuminate\Support\Carbon::parse($t->createdAt)->format('d/m/Y') }}</td>
                                    <td>
                                        <form method="POST" action="{{ route('admin.trips.destroy', $t->tripId) }}">@csrf
                                            <button class="ad-btn ad-btn--sm ad-btn--danger" data-confirm="Xóa chuyến “{{ $t->title }}” của {{ $t->fullName }}? Không khôi phục được."><i class="fa fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($rows->hasPages())
                        <div class="ad-pager"><span class="ad-note">Trang {{ $rows->currentPage() }} / {{ $rows->lastPage() }}</span>{{ $rows->links('admin.partials.pager') }}</div>
                    @endif
                @else
                    <div class="ad-empty"><i class="fa fa-road"></i>Chưa có chuyến đi nào{{ $search !== '' ? ' khớp “' . e($search) . '”' : '' }}.</div>
                @endif
            </div>
        </div>
    </div>
</div>
@include('admin.blocks.footer')
