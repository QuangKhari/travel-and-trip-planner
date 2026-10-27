@include('admin.blocks.header')
@php
    $cnt = fn(string $s) => collect($list_booking)->where('bookingStatus', $s)->count();
    $unpaid = collect($list_booking)->filter(fn($b) => $b->paymentStatus === 'n' && $b->bookingStatus !== 'c')->count();
    $canExport = in_array(session('adminRole'), ['admin', 'manager'], true);
@endphp
<div class="container body">
    <div class="main_container">
        @include('admin.blocks.sidebar')

        <div class="right_col" role="main">
            @include('admin.partials.flash')
            <div class="ad-head">
                <div>
                    <h1>Đơn đặt tour</h1>
                    <p>Xác nhận đơn, xác nhận thanh toán, hoàn thành hoặc hủy. Bấm “Chi tiết” để xem hóa đơn và ảnh chuyển khoản.</p>
                </div>
                @if ($canExport)
                    <form class="ad-actions" method="GET" action="{{ route('admin.booking.export') }}">
                        <select name="status" class="ad-field" aria-label="Trạng thái xuất">
                            <option value="">Mọi trạng thái</option>
                            <option value="n">Chưa xác nhận</option><option value="y">Đã xác nhận</option>
                            <option value="f">Đã hoàn thành</option><option value="c">Đã hủy</option>
                        </select>
                        <input type="date" name="from" class="ad-field" aria-label="Từ ngày" title="Từ ngày đặt">
                        <input type="date" name="to" class="ad-field" aria-label="Đến ngày" title="Đến ngày đặt">
                        <button class="ad-btn"><i class="fa fa-download"></i> Xuất CSV</button>
                    </form>
                @endif
            </div>

            <div class="ad-card">
                <div class="ad-toolbar">
                    <div class="ad-chips">
                        <button type="button" class="ad-chip is-on" data-ad-status-chip="" data-target="datatable-booking">Tất cả <b>{{ count($list_booking) }}</b></button>
                        <button type="button" class="ad-chip" data-ad-status-chip="n" data-target="datatable-booking">Chưa xác nhận <b>{{ $cnt('n') }}</b></button>
                        <button type="button" class="ad-chip" data-ad-status-chip="y" data-target="datatable-booking">Đã xác nhận <b>{{ $cnt('y') }}</b></button>
                        <button type="button" class="ad-chip" data-ad-status-chip="unpaid" data-target="datatable-booking">Chưa thanh toán <b>{{ $unpaid }}</b></button>
                        <button type="button" class="ad-chip" data-ad-status-chip="f" data-target="datatable-booking">Hoàn thành <b>{{ $cnt('f') }}</b></button>
                        <button type="button" class="ad-chip" data-ad-status-chip="c" data-target="datatable-booking">Đã hủy <b>{{ $cnt('c') }}</b></button>
                    </div>
                </div>
                <div class="ad-card__body" style="padding-top:12px">
                    {{-- .table-responsive: admin-paginate.js chèn ô tìm kiếm + phân trang quanh phần tử này --}}
                    <div class="table-responsive">
                        <table id="datatable-booking" class="ad-table" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Tour</th><th>Khách hàng</th><th>Liên hệ</th><th>Địa chỉ</th><th>Ngày đặt</th>
                                    <th class="num">NL</th><th class="num">TE</th><th class="num">Tổng tiền</th>
                                    <th>Đơn</th><th>Thanh toán</th><th>Tiền</th><th></th>
                                </tr>
                            </thead>
                            <tbody id="tbody-booking">
                                @include('admin.partials.list-booking')
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@include('admin.blocks.footer')
