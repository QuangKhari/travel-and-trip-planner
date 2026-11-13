@extends('admin.layout')
@php $canManage = in_array(session('adminRole'), ['admin', 'manager'], true); @endphp
@section('content')
    <div class="ad-head">
        <div>
            <h1>Đơn đặt tour</h1>
            <p>Xác nhận đơn, ghi nhận thanh toán, hoàn tất sau chuyến đi hoặc hủy (đơn đã thanh toán sẽ tự tạo yêu cầu hoàn tiền theo chính sách). Bấm vào một dòng để xem chi tiết.</p>
        </div>
        @if ($canManage)
            <form class="ad-actions" method="GET" action="{{ route('admin.booking.export') }}">
                <select name="status" class="ad-field" aria-label="Trạng thái xuất">
                    <option value="">Mọi trạng thái</option>
                    <option value="n">Chờ xác nhận</option><option value="y">Đã xác nhận</option>
                    <option value="f">Hoàn tất</option><option value="c">Đã hủy</option>
                </select>
                <input type="date" name="from" class="ad-field" aria-label="Từ ngày đặt" title="Từ ngày đặt">
                <input type="date" name="to" class="ad-field" aria-label="Đến ngày đặt" title="Đến ngày đặt">
                <button class="ad-btn"><i class="fa fa-download"></i> Xuất CSV</button>
            </form>
        @endif
        <div class="ad-actions"><a class="ad-btn ad-btn--primary" href="{{ route('admin.booking.create') }}"><i class="fa fa-plus"></i> Đặt hộ khách</a></div>
    </div>

    <div class="ad-card" id="bk-card" data-url-preview="{{ $canManage ? route('admin.cancel-preview') : '' }}">
        <div class="ad-toolbar">
            <div class="ad-chips" id="bk-chips">
                @foreach (['' => ['Tất cả', 'all'], 'n' => ['Chờ xác nhận', 'n'], 'y' => ['Đã xác nhận', 'y'], 'unpaid' => ['Chưa thu tiền', 'unpaid'], 'f' => ['Hoàn tất', 'f'], 'c' => ['Đã hủy', 'c'], 'refund_pending' => ['Chờ hoàn tiền', 'refund'], 'noshow' => ['Khách không đến', 'noshow']] as $k => [$label, $ck])
                    <button type="button" class="ad-chip {{ $k === '' ? 'is-on' : '' }}" data-ad-status-chip="{{ $k }}" data-target="datatable-booking">{{ $label }} <b data-count="{{ $ck }}">{{ $counts[$ck] ?? 0 }}</b></button>
                @endforeach
            </div>
        </div>
        {{-- .table-responsive: admin-paginate.js chèn ô tìm kiếm + phân trang quanh phần tử này --}}
        <div class="table-responsive ad-tablewrap">
            <table id="datatable-booking" class="ad-table">
                <thead>
                    <tr>
                        <th>Mã đơn</th><th>Tour · ngày đi</th><th>Khách hàng</th>
                        <th class="num">Tổng tiền</th><th>Đơn</th><th>Thanh toán</th><th class="num">Thao tác</th>
                    </tr>
                </thead>
                <tbody id="tbody-booking">
                    @include('admin.partials.list-booking')
                </tbody>
            </table>
        </div>
    </div>
@endsection
@push('scripts')
    <script src="{{ asset('admin/assets/js/admin-booking.js') }}?v={{ @filemtime(public_path('admin/assets/js/admin-booking.js')) }}"></script>
@endpush
