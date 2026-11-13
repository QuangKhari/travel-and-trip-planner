@extends('admin.layout')
@php
    $money = fn($v) => number_format((float) $v, 0, ',', '.');
    $short = function ($v) {
        $v = (float) $v;
        if (abs($v) >= 1e9) return round($v / 1e9, 1) . ' tỷ';
        if (abs($v) >= 1e6) return round($v / 1e6, 1) . ' tr';
        if (abs($v) >= 1e3) return round($v / 1e3) . 'k';
        return (string) round($v);
    };
    $maxRev = max(1, max(array_map('abs', $revenue)));
    $yearTotal = array_sum($revenue);
    $refundTotal = array_sum($revenueRefund);
    $regionTotal = max(1, array_sum(array_column($regions, 'n')));
    $sc = $statusCounts;
    $scTotal = max(1, array_sum($sc));
    $scMeta = [
        'n' => ['Chờ xác nhận', '#f59e0b'], 'y' => ['Đã xác nhận', '#3b82f6'], 'f' => ['Hoàn tất', '#16a34a'],
        'c' => ['Đã hủy', '#ef4444'], 'e' => ['Hết hạn giữ chỗ', '#94a3b8'],
    ];
    $delta = ($monthNow !== null && $monthPrev) ? round(($monthNow - $monthPrev) / max(1, abs($monthPrev)) * 100) : null;
    $hour = (int) now()->format('H');
    $greet = $hour < 11 ? 'Chào buổi sáng' : ($hour < 14 ? 'Chào buổi trưa' : ($hour < 18 ? 'Chào buổi chiều' : 'Chào buổi tối'));
@endphp

@section('content')
    <div class="ad-head">
        <div>
            <h1>{{ $greet }}, {{ session('admin') }}</h1>
            <p>{{ ['Chủ nhật', 'Thứ hai', 'Thứ ba', 'Thứ tư', 'Thứ năm', 'Thứ sáu', 'Thứ bảy'][now()->dayOfWeek] }}, {{ now()->format('d/m/Y') }} · {{ $new24 }} đơn mới trong 24 giờ qua, {{ $todo['booking'] }} đơn đang chờ xác nhận.</p>
        </div>
        <div class="ad-actions">
            <a class="ad-btn" href="{{ route('admin.booking') }}"><i class="fa fa-ticket"></i> Đơn đặt tour</a>
            @if ($isMgr)
                <a class="ad-btn ad-btn--primary" href="{{ route('admin.page-add-tours') }}"><i class="fa fa-plus"></i> Thêm tour</a>
            @endif
        </div>
    </div>

    {{-- Chỉ số chính --}}
    <div class="ad-kpis">
        <div class="ad-kpi">
            <div class="ad-kpi__ic ad-kpi__ic--teal"><i class="fa fa-line-chart"></i></div>
            <div>
                <div class="ad-kpi__l">Doanh thu thuần tháng này</div>
                <div class="ad-kpi__v">{{ $monthNow !== null ? $short($monthNow) : '—' }} <small>đ</small></div>
                <div class="ad-kpi__h">
                    @if ($delta !== null)
                        <span class="{{ $delta >= 0 ? 'up' : 'down' }}"><i class="fa fa-caret-{{ $delta >= 0 ? 'up' : 'down' }}"></i> {{ abs($delta) }}%</span> so với tháng trước
                    @else
                        Chưa có tháng trước để so sánh
                    @endif
                </div>
            </div>
        </div>
        <a class="ad-kpi" href="{{ route('admin.booking') }}">
            <div class="ad-kpi__ic ad-kpi__ic--blue"><i class="fa fa-ticket"></i></div>
            <div>
                <div class="ad-kpi__l">Đơn đang hiệu lực</div>
                <div class="ad-kpi__v">{{ $summary['countBooking'] }}</div>
                <div class="ad-kpi__h">{{ $new24 }} đơn mới trong 24 giờ</div>
            </div>
        </a>
        <a class="ad-kpi" href="{{ $isMgr ? route('admin.departures') : route('admin.booking') }}">
            <div class="ad-kpi__ic ad-kpi__ic--violet"><i class="fa fa-calendar-check-o"></i></div>
            <div>
                <div class="ad-kpi__l">Tỷ lệ lấp đầy chuyến sắp tới</div>
                <div class="ad-kpi__v">{{ $fill['avg'] !== null ? $fill['avg'] . '%' : '—' }}</div>
                <div class="ad-kpi__h">{{ $fill['booked'] + $fill['held'] }}/{{ $fill['total'] }} chỗ · {{ $fill['departures'] }} ngày khởi hành</div>
            </div>
        </a>
        <a class="ad-kpi" href="{{ route('admin.reviews') }}">
            <div class="ad-kpi__ic ad-kpi__ic--sand"><i class="fa fa-star"></i></div>
            <div>
                <div class="ad-kpi__l">Điểm đánh giá trung bình</div>
                <div class="ad-kpi__v">{{ $ratings['avg'] ?? '—' }} <small>/ 5</small></div>
                <div class="ad-kpi__h">{{ $ratings['count'] }} đánh giá đang hiện</div>
            </div>
        </a>
        <a class="ad-kpi" href="{{ $isMgr ? route('admin.users') : route('admin.dashboard') }}">
            <div class="ad-kpi__ic ad-kpi__ic--green"><i class="fa fa-users"></i></div>
            <div>
                <div class="ad-kpi__l">Người dùng</div>
                <div class="ad-kpi__v">{{ $money($users['total']) }}</div>
                <div class="ad-kpi__h">+{{ $users['new30'] }} trong 30 ngày · {{ $trips }} chuyến đi đã lập</div>
            </div>
        </a>
    </div>

    <div class="ad-grid ad-grid--21">
        {{-- Doanh thu theo tháng (có năm) --}}
        <section class="ad-card">
            <div class="ad-card__head">
                <h2>Doanh thu thuần theo tháng <small>· {{ $money($yearTotal) }} đ năm {{ $year }}</small></h2>
                <form method="GET" action="{{ route('admin.dashboard') }}">
                    <select name="year" class="ad-field" onchange="this.form.submit()" aria-label="Chọn năm">
                        @foreach ($years as $y)<option value="{{ $y }}" @selected($y === $year)>Năm {{ $y }}</option>@endforeach
                    </select>
                </form>
            </div>
            <div class="ad-card__body">
                <div class="ad-bars" role="img" aria-label="Doanh thu thuần 12 tháng năm {{ $year }}">
                    @foreach ($revenue as $i => $v)
                        <div class="ad-bars__c" title="Tháng {{ $i + 1 }}/{{ $year }}: thu {{ $money($revenueGross[$i]) }} đ, hoàn {{ $money($revenueRefund[$i]) }} đ, thuần {{ $money($v) }} đ">
                            <div class="ad-bars__v">{{ $v != 0 ? $short($v) : '' }}</div>
                            <div class="ad-bars__b {{ $v == 0 ? 'is-zero' : ($v < 0 ? 'is-neg' : '') }}" style="height:{{ $v != 0 ? max(4, round(abs($v) / $maxRev * 160)) : 3 }}px"></div>
                            <div class="ad-bars__m">T{{ $i + 1 }}</div>
                        </div>
                    @endforeach
                </div>
                <div class="ad-legend">
                    <span style="--c:var(--ad-primary)">Đã thu <b>{{ $money(array_sum($revenueGross)) }} đ</b></span>
                    <span style="--c:#ef4444">Đã hoàn <b>{{ $money($refundTotal) }} đ</b></span>
                    <span style="--c:#94a3b8">Năm {{ $year - 1 }} <b>{{ $money($revenuePrevYear) }} đ</b></span>
                </div>
                <p class="ad-note" style="margin:10px 0 0">Tính theo ngày thanh toán (múi giờ Việt Nam) của đơn đã thu tiền, trừ khoản đã hoàn theo ngày hoàn (§12.3).</p>
            </div>
        </section>

        {{-- Việc cần xử lý --}}
        <section class="ad-card">
            <div class="ad-card__head"><h2>Việc cần xử lý</h2></div>
            <ul class="ad-todo">
                <li><a href="{{ route('admin.booking') }}"><span class="ad-todo__n {{ $todo['booking'] ? 'is-hot' : '' }}">{{ $todo['booking'] }}</span>
                    <span class="ad-todo__t">Đơn chờ xác nhận<small>Xác nhận hoặc hủy để giải phóng chỗ</small></span><i class="fa fa-angle-right"></i></a></li>
                <li><a href="{{ route('admin.booking') }}"><span class="ad-todo__n {{ $todo['unpaid'] ? 'is-hot' : '' }}">{{ $todo['unpaid'] }}</span>
                    <span class="ad-todo__t">Đã xác nhận, chưa thu tiền<small>Theo dõi thanh toán</small></span><i class="fa fa-angle-right"></i></a></li>
                @if ($isMgr)
                    <li><a href="{{ route('admin.refunds') }}"><span class="ad-todo__n {{ $todo['refund'] ? 'is-bad' : '' }}">{{ $todo['refund'] }}</span>
                        <span class="ad-todo__t">Yêu cầu hoàn tiền<small>Hoàn qua cổng hoặc chuyển tiền rồi đánh dấu đã hoàn</small></span><i class="fa fa-angle-right"></i></a></li>
                    @if (($todo['refundFail'] ?? 0) > 0)
                        <li><a href="{{ route('admin.refunds') }}"><span class="ad-todo__n is-bad">{{ $todo['refundFail'] }}</span>
                            <span class="ad-todo__t">Hoàn qua cổng bị từ chối<small>Thử lại hoặc hoàn thủ công</small></span><i class="fa fa-angle-right"></i></a></li>
                    @endif
                    <li><a href="{{ route('admin.departures.rescue') }}"><span class="ad-todo__n {{ ($todo['shortfall'] ?? 0) ? 'is-bad' : '' }}">{{ $todo['shortfall'] ?? 0 }}</span>
                        <span class="ad-todo__t">Lịch / đoàn sắp thiếu khách<small>Ghép đoàn, đổi ngày hoặc hủy và hoàn tiền</small></span><i class="fa fa-angle-right"></i></a></li>
                    <li><a href="{{ route('admin.departures.rescue') }}"><span class="ad-todo__n {{ ($todo['rescue'] ?? 0) ? 'is-hot' : '' }}">{{ $todo['rescue'] ?? 0 }}</span>
                        <span class="ad-todo__t">Phương án thiếu khách chờ xử lý<small>Khách đã trả lời: áp dụng hoặc hủy khởi hành</small></span><i class="fa fa-angle-right"></i></a></li>
                    <li><a href="{{ route('admin.incidents') }}"><span class="ad-todo__n {{ ($todo['incident'] ?? 0) ? 'is-hot' : '' }}">{{ $todo['incident'] ?? 0 }}</span>
                        <span class="ad-todo__t">Sự cố tour đang xử lý<small>Xe, khách sạn, HDV, thời tiết, sức khỏe khách</small></span><i class="fa fa-angle-right"></i></a></li>
                    <li><a href="{{ route('admin.reconciliation') }}"><span class="ad-todo__n {{ ($todo['reconcile'] ?? 0) ? 'is-bad' : '' }}">{{ $todo['reconcile'] ?? 0 }}</span>
                        <span class="ad-todo__t">Điểm đối soát không khớp<small>Đơn – tiền thu – hoàn tiền</small></span><i class="fa fa-angle-right"></i></a></li>
                    <li><a href="{{ route('admin.complaints') }}"><span class="ad-todo__n {{ ($todo['complaint'] ?? 0) ? 'is-bad' : '' }}">{{ $todo['complaint'] ?? 0 }}</span>
                        <span class="ad-todo__t">Khiếu nại đang xử lý<small>Phản hồi, giải quyết, bồi hoàn</small></span><i class="fa fa-angle-right"></i></a></li>
                    <li><a href="{{ route('admin.adjustments') }}"><span class="ad-todo__n {{ ($todo['adjust'] ?? 0) ? 'is-hot' : '' }}">{{ $todo['adjust'] ?? 0 }}</span>
                        <span class="ad-todo__t">Khoản thu thêm / hoàn do đổi số khách<small>Ghi nhận khi đã thu hoặc đã hoàn</small></span><i class="fa fa-angle-right"></i></a></li>
                    @if (($todo['noShowCheck'] ?? 0) > 0)
                        <li><a href="{{ route('admin.booking') }}"><span class="ad-todo__n is-hot">{{ $todo['noShowCheck'] }}</span>
                            <span class="ad-todo__t">Đơn đã qua ngày khởi hành chưa chốt<small>Mở đơn: hoàn tất hoặc đánh dấu khách không đến</small></span><i class="fa fa-angle-right"></i></a></li>
                    @endif
                    <li><a href="{{ route('admin.travel-alerts') }}"><span class="ad-todo__n {{ ($todo['travelAlerts'] ?? 0) ? 'is-hot' : '' }}">{{ $todo['travelAlerts'] ?? 0 }}</span>
                        <span class="ad-todo__t">Cảnh báo điểm đến đang hiệu lực<small>La bàn nhắc các chuyến trùng ngày, gợi ý đổi lịch</small></span><i class="fa fa-angle-right"></i></a></li>
                    <li><a href="{{ route('admin.waitlist') }}"><span class="ad-todo__n">{{ $todo['waitlist'] ?? 0 }}</span>
                        <span class="ad-todo__t">Khách đang trong danh sách chờ<small>Tự mời khi có chỗ trống</small></span><i class="fa fa-angle-right"></i></a></li>
                @endif
                <li><a href="{{ route('admin.support') }}"><span class="ad-todo__n {{ $todo['support'] ? 'is-hot' : '' }}">{{ $todo['support'] }}</span>
                    <span class="ad-todo__t">Yêu cầu tư vấn đang mở<small>Khách cần nhân viên hỗ trợ lịch trình</small></span><i class="fa fa-angle-right"></i></a></li>
                @if ($isMgr)
                    <li><a href="{{ route('admin.contact', ['status' => 'new']) }}"><span class="ad-todo__n {{ $todo['contact'] ? 'is-hot' : '' }}">{{ $todo['contact'] }}</span>
                        <span class="ad-todo__t">Tin liên hệ mới<small>Phản hồi khách</small></span><i class="fa fa-angle-right"></i></a></li>
                    <li><a href="{{ route('admin.content', ['foods', 'status' => 'draft']) }}"><span class="ad-todo__n {{ $todo['pending'] ? 'is-hot' : '' }}">{{ $todo['pending'] }}</span>
                        <span class="ad-todo__t">Nội dung chưa xuất bản<small>Món, trải nghiệm, lễ hội, cẩm nang, lịch trình</small></span><i class="fa fa-angle-right"></i></a></li>
                    <li><a href="{{ route('admin.tours') }}"><span class="ad-todo__n {{ $todo['toursNoDest'] ? 'is-hot' : '' }}">{{ $todo['toursNoDest'] }}</span>
                        <span class="ad-todo__t">Tour chưa gắn điểm đến<small>Không lọc được theo cảm hứng</small></span><i class="fa fa-angle-right"></i></a></li>
                @endif
            </ul>
        </section>
    </div>

    <div class="ad-grid ad-grid--3">
        {{-- Đơn theo trạng thái --}}
        <section class="ad-card">
            <div class="ad-card__head"><h2>Đơn theo trạng thái</h2><small>{{ array_sum($sc) }} đơn</small></div>
            <div class="ad-card__body">
                <div class="ad-split" role="img" aria-label="Tỷ lệ đơn theo trạng thái">
                    @foreach ($scMeta as $k => [$l, $c])
                        @if ($sc[$k] > 0)<i style="width:{{ $sc[$k] / $scTotal * 100 }}%;background:{{ $c }}" title="{{ $l }}: {{ $sc[$k] }}"></i>@endif
                    @endforeach
                </div>
                <table class="ad-table" style="margin-top:12px">
                    <tbody>
                    @foreach ($scMeta as $k => [$l, $c])
                        <tr><td style="padding:7px 0"><span class="ad-legend" style="margin:0"><span style="--c:{{ $c }}">{{ $l }}</span></span></td>
                            <td class="num" style="padding:7px 0"><b>{{ $sc[$k] }}</b> <span class="ad-muted">· {{ round($sc[$k] / $scTotal * 100) }}%</span></td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        {{-- Top 5 tour --}}
        <section class="ad-card">
            <div class="ad-card__head"><h2>Top 5 tour theo số khách</h2><small>Đơn đã xác nhận / hoàn tất</small></div>
            <div class="ad-card__body">
                @php $topMax = max(1, (int) collect($toursBooked)->max('booked_quantity')); @endphp
                @forelse ($toursBooked as $i => $t)
                    <div class="ad-meter">
                        <div class="ad-meter__top"><span title="{{ $t->title }}">{{ $i + 1 }}. {{ $t->title }}</span><b>{{ $t->booked_quantity }} khách</b></div>
                        <div class="ad-meter__bar"><i style="width:{{ round($t->booked_quantity / $topMax * 100) }}%"></i></div>
                    </div>
                @empty
                    <div class="ad-empty" style="padding:20px 0"><i class="fa fa-suitcase"></i>Chưa có đơn đã xác nhận.</div>
                @endforelse
            </div>
        </section>

        {{-- Đánh giá --}}
        <section class="ad-card">
            <div class="ad-card__head"><h2>Phân bố đánh giá</h2><small>Chỉ đánh giá đang hiện</small></div>
            <div class="ad-card__body">
                @php $rMax = max(1, max($ratings['dist'])); @endphp
                @foreach ($ratings['dist'] as $star => $n)
                    <div class="ad-meter" style="margin:9px 0">
                        <div class="ad-meter__top"><span>{{ $star }} <i class="fa fa-star" style="color:#f59e0b"></i></span><b>{{ $n }}</b></div>
                        <div class="ad-meter__bar"><i class="is-sand" style="width:{{ round($n / $rMax * 100) }}%"></i></div>
                    </div>
                @endforeach
            </div>
        </section>
    </div>

    <div class="ad-grid ad-grid--21">
        {{-- Đơn mới --}}
        <section class="ad-card">
            <div class="ad-card__head"><h2>Đơn mới chờ xác nhận</h2><a class="ad-btn ad-btn--sm" href="{{ route('admin.booking') }}">Xem tất cả</a></div>
            <div class="ad-tablewrap">
                @if (count($newBooking))
                    <table class="ad-table">
                        <thead><tr><th>Mã đơn</th><th>Khách</th><th>Tour</th><th>Đặt lúc</th><th class="num">Tổng tiền</th></tr></thead>
                        <tbody>
                        @foreach ($newBooking as $b)
                            <tr data-href="{{ route('admin.booking-detail', ['id' => $b->bookingId]) }}">
                                <td class="nowrap"><a href="{{ route('admin.booking-detail', ['id' => $b->bookingId]) }}">{{ $b->bookingCode }}</a></td>
                                <td>{{ $b->fullName }}</td>
                                <td>{{ \Illuminate\Support\Str::limit($b->tour_name, 40) }}</td>
                                <td class="nowrap ad-muted">{{ \Carbon\Carbon::parse($b->bookingDate)->diffForHumans() }}</td>
                                <td class="num">{{ $money($b->totalPrice) }} đ</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="ad-empty"><i class="fa fa-check-circle"></i>Không có đơn nào đang chờ xác nhận.</div>
                @endif
            </div>
        </section>

        {{-- Khởi hành 7 ngày tới --}}
        <section class="ad-card">
            <div class="ad-card__head"><h2>Khởi hành trong 7 ngày</h2>@if ($isMgr)<a class="ad-btn ad-btn--sm" href="{{ route('admin.departures') }}">Lịch khởi hành</a>@endif</div>
            <div class="ad-card__body">
                @forelse ($upcoming as $d)
                    @php $used = $d->bookedSlots + $d->heldSlots; $pct = $d->totalSlots ? round($used / $d->totalSlots * 100) : 0; @endphp
                    <div class="ad-meter">
                        <div class="ad-meter__top"><span title="{{ $d->title }}"><b style="color:var(--ad-ink)">{{ \Carbon\Carbon::parse($d->departureDate)->format('d/m') }}</b> · {{ $d->title }}</span><b>{{ $used }}/{{ $d->totalSlots }}</b></div>
                        <div class="ad-meter__bar"><i class="{{ $pct >= 90 ? 'is-bad' : ($pct >= 60 ? 'is-warn' : '') }}" style="width:{{ $pct }}%"></i></div>
                    </div>
                @empty
                    <div class="ad-empty" style="padding:20px 0"><i class="fa fa-calendar-o"></i>Không có chuyến nào khởi hành trong 7 ngày tới.</div>
                @endforelse
            </div>
        </section>
    </div>

    <div class="ad-grid ad-grid--2">
        <section class="ad-card">
            <div class="ad-card__head"><h2>Lấp đầy theo tour</h2><small>Trung bình các ngày khởi hành sắp tới</small></div>
            <div class="ad-card__body">
                @forelse ($fill['tours'] as $t)
                    @php $pct = round(($t->avgFill ?? 0) * 100); @endphp
                    <div class="ad-meter">
                        <div class="ad-meter__top"><span title="{{ $t->title }}">{{ $t->title }}</span><b>{{ $pct }}% · {{ $t->deps }} ngày</b></div>
                        <div class="ad-meter__bar"><i style="width:{{ $pct }}%"></i></div>
                    </div>
                @empty
                    <div class="ad-empty" style="padding:20px 0"><i class="fa fa-calendar-o"></i>Chưa có ngày khởi hành sắp tới.</div>
                @endforelse
            </div>
        </section>
        <section class="ad-card">
            <div class="ad-card__head"><h2>Tour theo miền</h2><small>{{ array_sum(array_column($regions, 'n')) }} tour đang hiện · {{ $summary['tourWorking'] }} đang nhận đặt</small></div>
            <div class="ad-card__body">
                @foreach ($regions as $r)
                    <div class="ad-meter">
                        <div class="ad-meter__top"><span>{{ $r['label'] }}</span><b>{{ $r['n'] }}</b></div>
                        <div class="ad-meter__bar"><i style="width:{{ round($r['n'] / $regionTotal * 100) }}%"></i></div>
                    </div>
                @endforeach
                <p class="ad-note" style="margin:14px 0 0">Tổng đã thu từ trước đến nay: <b>{{ $money($summary['received']) }} đ</b>, đã hoàn {{ $money($summary['refunded']) }} đ.</p>
            </div>
        </section>
    </div>
@endsection
