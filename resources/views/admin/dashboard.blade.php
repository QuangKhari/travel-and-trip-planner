@include('admin.blocks.header')
@php
    $money = fn($v) => number_format((float) $v, 0, ',', '.');
    $maxRev = max(1, max($revenue));
    $monthTotal = array_sum($revenue);
    $regionTotal = max(1, array_sum(array_column($regions, 'n')));
@endphp
<div class="container body">
    <div class="main_container">
        @include('admin.blocks.sidebar')

        <div class="right_col" role="main">
            @include('admin.partials.flash')

            <div class="ad-head">
                <div>
                    <h1>Tổng quan</h1>
                    <p>Hôm nay {{ now()->format('d/m/Y') }} · những việc cần làm và số liệu chính của hệ thống.</p>
                </div>
                <div class="ad-actions">
                    <a class="ad-btn" href="{{ route('admin.booking') }}"><i class="fa fa-ticket"></i> Xem đơn đặt tour</a>
                    <a class="ad-btn ad-btn--primary" href="{{ route('admin.page-add-tours') }}"><i class="fa fa-plus"></i> Thêm tour</a>
                </div>
            </div>

            {{-- Chỉ số chính --}}
            <div class="ad-kpis">
                <a class="ad-kpi" href="{{ route('admin.tours') }}">
                    <div class="ad-kpi__ic ad-kpi__ic--teal"><i class="fa fa-suitcase"></i></div>
                    <div><div class="ad-kpi__l">Tour đang hiển thị</div><div class="ad-kpi__v">{{ $summary['tourWorking'] }}</div>
                        <div class="ad-kpi__h">{{ $todo['toursHidden'] }} tour đang ẩn</div></div>
                </a>
                <a class="ad-kpi" href="{{ route('admin.booking') }}">
                    <div class="ad-kpi__ic ad-kpi__ic--blue"><i class="fa fa-ticket"></i></div>
                    <div><div class="ad-kpi__l">Đơn đặt tour (chưa hủy)</div><div class="ad-kpi__v">{{ $summary['countBooking'] }}</div>
                        <div class="ad-kpi__h">{{ $todo['booking'] }} đơn chờ xác nhận</div></div>
                </a>
                <a class="ad-kpi" href="{{ route('admin.users') }}">
                    <div class="ad-kpi__ic ad-kpi__ic--green"><i class="fa fa-users"></i></div>
                    <div><div class="ad-kpi__l">Người dùng</div><div class="ad-kpi__v">{{ $money($users['total']) }}</div>
                        <div class="ad-kpi__h">+{{ $users['new30'] }} trong 30 ngày qua</div></div>
                </a>
                <div class="ad-kpi">
                    <div class="ad-kpi__ic ad-kpi__ic--sand"><i class="fa fa-money"></i></div>
                    <div><div class="ad-kpi__l">Doanh thu đã thu</div><div class="ad-kpi__v">{{ $money($summary['totalAmount']) }} <small>đ</small></div>
                        <div class="ad-kpi__h">Đơn đã thanh toán, chưa hủy</div></div>
                </div>
                <a class="ad-kpi" href="{{ route('admin.trips') }}">
                    <div class="ad-kpi__ic ad-kpi__ic--teal"><i class="fa fa-road"></i></div>
                    <div><div class="ad-kpi__l">Chuyến đi đã lập (Planner)</div><div class="ad-kpi__v">{{ $trips }}</div>
                        <div class="ad-kpi__h">Do khách tự lên kế hoạch</div></div>
                </a>
            </div>

            <div class="ad-grid ad-grid--21">
                {{-- Doanh thu theo tháng --}}
                <div class="ad-card">
                    <div class="ad-card__head">
                        <h2>Doanh thu theo tháng <small>· {{ $money($monthTotal) }} đ trong {{ $year }}</small></h2>
                        <form method="GET" action="{{ route('admin.dashboard') }}">
                            <select name="year" class="ad-field" onchange="this.form.submit()" aria-label="Chọn năm">
                                @foreach ($years as $y)<option value="{{ $y }}" @selected($y === $year)>Năm {{ $y }}</option>@endforeach
                            </select>
                        </form>
                    </div>
                    <div class="ad-card__body">
                        <div class="ad-bars" role="img" aria-label="Doanh thu 12 tháng năm {{ $year }}">
                            @foreach ($revenue as $i => $v)
                                <div class="ad-bars__c" title="Tháng {{ $i + 1 }}: {{ $money($v) }} đ">
                                    <div class="ad-bars__v">{{ $v > 0 ? round($v / 1000000, 1) . ' tr' : '' }}</div>
                                    <div class="ad-bars__b {{ $v > 0 ? '' : 'is-zero' }}" style="height:{{ $v > 0 ? max(4, round($v / $maxRev * 150)) : 3 }}px"></div>
                                    <div class="ad-bars__m">T{{ $i + 1 }}</div>
                                </div>
                            @endforeach
                        </div>
                        <p class="ad-note" style="margin:10px 0 0">Tính theo ngày thanh toán, chỉ đơn đã thanh toán và chưa hủy. Đơn vị cột: triệu đồng.</p>
                    </div>
                </div>

                {{-- Việc cần xử lý --}}
                <div class="ad-card">
                    <div class="ad-card__head"><h2>Việc cần xử lý</h2></div>
                    <ul class="ad-todo">
                        <li><a href="{{ route('admin.booking') }}"><span class="ad-todo__n {{ $todo['booking'] ? 'is-hot' : '' }}">{{ $todo['booking'] }}</span>
                            <span class="ad-todo__t">Đơn chờ xác nhận<small>Xác nhận hoặc hủy để giải phóng chỗ</small></span><i class="fa fa-angle-right"></i></a></li>
                        <li><a href="{{ route('admin.booking') }}"><span class="ad-todo__n {{ $todo['unpaid'] ? 'is-hot' : '' }}">{{ $todo['unpaid'] }}</span>
                            <span class="ad-todo__t">Đã xác nhận, chưa thu tiền<small>Theo dõi thanh toán</small></span><i class="fa fa-angle-right"></i></a></li>
                        <li><a href="{{ route('admin.contact', ['status' => 'new']) }}"><span class="ad-todo__n {{ $todo['contact'] ? 'is-hot' : '' }}">{{ $todo['contact'] }}</span>
                            <span class="ad-todo__t">Tin liên hệ mới<small>Phản hồi khách</small></span><i class="fa fa-angle-right"></i></a></li>
                        <li><a href="{{ route('admin.content', ['foods', 'status' => 'draft']) }}"><span class="ad-todo__n {{ $todo['pending'] ? 'is-hot' : '' }}">{{ $todo['pending'] }}</span>
                            <span class="ad-todo__t">Nội dung chưa xuất bản<small>Bài còn ở trạng thái Nháp (món, trải nghiệm, lễ hội, cẩm nang, lịch trình)</small></span><i class="fa fa-angle-right"></i></a></li>
                        <li><a href="{{ route('admin.tours') }}"><span class="ad-todo__n {{ $todo['toursNoDest'] ? 'is-hot' : '' }}">{{ $todo['toursNoDest'] }}</span>
                            <span class="ad-todo__t">Tour chưa gắn điểm đến<small>Không lọc được theo cảm hứng</small></span><i class="fa fa-angle-right"></i></a></li>
                    </ul>
                </div>
            </div>

            <div class="ad-grid ad-grid--21">
                {{-- Đơn mới --}}
                <div class="ad-card">
                    <div class="ad-card__head"><h2>Đơn đặt mới <small>· chờ xác nhận</small></h2><a class="ad-btn ad-btn--sm" href="{{ route('admin.booking') }}">Xem tất cả</a></div>
                    <div class="ad-tablewrap">
                        @if (count($newBooking))
                            <table class="ad-table">
                                <thead><tr><th>Mã</th><th>Khách</th><th>Tour</th><th class="num">Tổng tiền</th></tr></thead>
                                <tbody>
                                @foreach ($newBooking as $b)
                                    <tr>
                                        <td><a href="{{ route('admin.booking-detail', ['id' => $b->bookingId]) }}">#{{ $b->bookingId }}</a></td>
                                        <td>{{ $b->fullName }}</td>
                                        <td>{{ \Illuminate\Support\Str::limit($b->tour_name, 42) }}</td>
                                        <td class="num">{{ $money($b->totalPrice) }} đ</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        @else
                            <div class="ad-empty"><i class="fa fa-check-circle"></i>Không có đơn nào đang chờ xác nhận.</div>
                        @endif
                    </div>
                </div>

                {{-- Tour được đặt nhiều --}}
                <div class="ad-card">
                    <div class="ad-card__head"><h2>Tour được đặt nhiều nhất</h2></div>
                    <div class="ad-card__body">
                        @forelse ($toursBooked as $t)
                            @php $total = max(1, (int) $t->booked_quantity + (int) $t->quantity); @endphp
                            <div class="ad-meter">
                                <div class="ad-meter__top"><span>{{ \Illuminate\Support\Str::limit($t->title, 34) }}</span><b>{{ $t->booked_quantity }} chỗ</b></div>
                                <div class="ad-meter__bar"><i style="width:{{ round($t->booked_quantity / $total * 100) }}%"></i></div>
                            </div>
                        @empty
                            <div class="ad-empty" style="padding:20px 0"><i class="fa fa-suitcase"></i>Chưa có dữ liệu đặt tour.</div>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- Tour theo miền --}}
            <div class="ad-card">
                <div class="ad-card__head"><h2>Tour theo miền</h2><small>{{ array_sum(array_column($regions, 'n')) }} tour</small></div>
                <div class="ad-card__body">
                    @foreach ($regions as $r)
                        <div class="ad-meter">
                            <div class="ad-meter__top"><span>{{ $r['label'] }}</span><b>{{ $r['n'] }}</b></div>
                            <div class="ad-meter__bar"><i style="width:{{ round($r['n'] / $regionTotal * 100) }}%"></i></div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>
@include('admin.blocks.footer')
