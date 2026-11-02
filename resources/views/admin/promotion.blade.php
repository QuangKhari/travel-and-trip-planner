@extends('admin.layout')
@php
    $money = fn($v) => number_format((float) $v, 0, ',', '.');
    $state = ['live' => ['ok', 'Đang chạy'], 'scheduled' => ['info', 'Sắp chạy'], 'expired' => ['mute', 'Hết hạn'], 'empty' => ['warn', 'Hết lượt'], 'off' => ['bad', 'Đã tắt']];
@endphp
@section('content')
    <div class="ad-head">
        <div>
            <h1>Khuyến mãi</h1>
            <p>Mã giảm giá theo %, có thể giới hạn số tiền giảm tối đa, giá trị đơn tối thiểu, tổng số lượt và số lượt mỗi khách. Mã đã có đơn dùng khi xóa sẽ được tắt để giữ lịch sử.</p>
        </div>
        <div class="ad-actions"><button type="button" class="ad-btn ad-btn--primary" data-toggle="modal" data-target="#modal-add-promotion"><i class="fa fa-plus"></i> Tạo mã</button></div>
    </div>

    <div class="ad-kpis">
        <div class="ad-kpi"><div class="ad-kpi__ic ad-kpi__ic--teal"><i class="fa fa-tags"></i></div><div><div class="ad-kpi__l">Mã đang chạy</div><div class="ad-kpi__v">{{ $stats['live'] }}</div><div class="ad-kpi__h">{{ $stats['expiring'] }} mã hết hạn trong 7 ngày</div></div></div>
        <div class="ad-kpi"><div class="ad-kpi__ic ad-kpi__ic--blue"><i class="fa fa-ticket"></i></div><div><div class="ad-kpi__l">Lượt đã dùng</div><div class="ad-kpi__v">{{ $stats['used'] }}</div><div class="ad-kpi__h">Trên đơn chưa hủy</div></div></div>
        <div class="ad-kpi"><div class="ad-kpi__ic ad-kpi__ic--sand"><i class="fa fa-money"></i></div><div><div class="ad-kpi__l">Tổng tiền đã giảm</div><div class="ad-kpi__v">{{ $money($stats['discount']) }} <small>đ</small></div><div class="ad-kpi__h">Chi phí khuyến mãi</div></div></div>
    </div>

    <div class="ad-card">
        <div class="ad-toolbar">
            <div class="ad-chips">
                <button type="button" class="ad-chip is-on" data-ad-status-chip="" data-target="datatable-promotion">Tất cả <b>{{ count($list_promotion) }}</b></button>
                @foreach ($state as $k => [$c, $l])
                    @php $n = collect($list_promotion)->where('state', $k)->count(); @endphp
                    @if ($n)<button type="button" class="ad-chip" data-ad-status-chip="{{ $k }}" data-target="datatable-promotion">{{ $l }} <b>{{ $n }}</b></button>@endif
                @endforeach
            </div>
        </div>
        <div class="table-responsive ad-tablewrap">
            <table id="datatable-promotion" class="ad-table">
                <thead><tr><th>Mã</th><th>Ưu đãi</th><th>Điều kiện</th><th>Thời gian</th><th class="num">Lượt</th><th>Trạng thái</th><th class="num">Thao tác</th></tr></thead>
                <tbody>@include('admin.partials.list-promotion')</tbody>
            </table>
        </div>
        @if (!count($list_promotion))
            <div class="ad-empty"><i class="fa fa-tags"></i><b>Chưa có mã giảm giá</b>Bấm “Tạo mã” để thêm mã đầu tiên.</div>
        @endif
    </div>

    <div class="modal fade" id="modal-add-promotion" tabindex="-1" role="dialog" aria-labelledby="pm-add-t" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <form class="modal-content" method="POST" action="{{ route('admin.add-promotion') }}" id="form-add-promotion">
                @csrf
                <div class="modal-header"><h5 class="modal-title" id="pm-add-t">Tạo mã giảm giá</h5><button type="button" class="close" data-dismiss="modal" aria-label="Đóng">&times;</button></div>
                <div class="modal-body">@include('admin.partials.promotion-fields', ['p' => null])</div>
                <div class="modal-footer"><button type="button" class="ad-btn" data-dismiss="modal">Thôi</button><button class="ad-btn ad-btn--primary" type="submit">Tạo mã</button></div>
            </form>
        </div>
    </div>
@endsection
@push('scripts')
    @if ($errors->any() && old('code') !== null && !old('promotionId'))
        <script>$(function () { $('#modal-add-promotion').modal('show'); });</script>
    @endif
@endpush
