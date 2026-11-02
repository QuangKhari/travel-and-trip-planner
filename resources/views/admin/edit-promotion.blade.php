@extends('admin.layout')
@php $money = fn($v) => number_format((float) $v, 0, ',', '.') . ' đ'; @endphp
@section('content')
    <div class="ad-head">
        <div>
            <a class="ad-back" href="{{ route('admin.promotion') }}"><i class="fa fa-arrow-left"></i> Khuyến mãi</a>
            <h1>Sửa mã {{ $promotion->code }}</h1>
            <p>Đổi điều kiện chỉ áp dụng cho đơn đặt sau thời điểm lưu; đơn cũ giữ nguyên số tiền đã giảm.</p>
        </div>
    </div>
    <div class="ad-grid ad-grid--side">
        <form class="ad-card" method="POST" action="{{ route('admin.edit-promotion') }}">
            @csrf
            <input type="hidden" name="promotionId" value="{{ $promotion->promotionId }}">
            <div class="ad-card__body">@include('admin.partials.promotion-fields', ['p' => $promotion])</div>
            <div class="ad-card__foot"><a class="ad-btn" href="{{ route('admin.promotion') }}">Thôi</a><button class="ad-btn ad-btn--primary" type="submit"><i class="fa fa-check"></i> Lưu thay đổi</button></div>
        </form>
        <section class="ad-card">
            <div class="ad-card__head"><h2>Lượt dùng gần đây</h2><small>{{ count($usage) }}</small></div>
            @if (count($usage))
                <ul class="ad-todo">
                    @foreach ($usage as $u)
                        <li><a href="{{ route('admin.booking-detail', ['id' => $u->bookingId]) }}">
                            <span class="ad-todo__t">{{ $u->userName }}<small>{{ $u->bookingCode }} · {{ $u->bookingStatus === 'c' ? 'đã hủy (lượt đã trả lại)' : 'giảm ' . $money($u->discountAmount) }}</small></span><i class="fa fa-angle-right"></i></a></li>
                    @endforeach
                </ul>
            @else
                <div class="ad-empty"><i class="fa fa-ticket"></i>Chưa có đơn nào dùng mã này.</div>
            @endif
        </section>
    </div>
@endsection
