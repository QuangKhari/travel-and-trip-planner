@include('clients.blocks.header')

<div class="container mt-5 text-center">

    <h3>Thanh toán chuyển khoản</h3>

    <p>Mã đơn hàng:</p>
    <h4>{{ $bookingCode }}</h4>

    <p>Số tiền:</p>
    <h4>{{ number_format($amount) }} VNĐ</h4>

    <p>Nội dung chuyển khoản:</p>
    <h4>{{ $bookingCode }}</h4>

    <img
        src="https://img.vietqr.io/image/MB-123456789-compact2.png?amount={{ $amount }}&addInfo={{ $bookingCode }}"
        width="350">

    <br><br>

    <p class="text-warning mt-3">
        Sau khi chuyển khoản thành công, vui lòng chờ quản trị viên xác nhận thanh toán.
    </p>
    <div class="mt-4">
        <a href="{{ route('home') }}" class="btn btn-primary">
            Về trang chủ
        </a>
    </div>
</div>

@include('clients.blocks.footer')