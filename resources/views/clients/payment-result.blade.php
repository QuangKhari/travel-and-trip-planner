@include('clients.blocks.header')
@php
    $ok = $r['valid'] && $r['success'];
    $paid = $r['paid'] ?? false;
    $mine = $b && (int) session('userId') === (int) $b->userId;
    $gwName = \App\Services\Payments\OnlinePaymentService::label($r['method'] ?? '');
@endphp
<style>
    .pr { padding: 140px 16px 80px; background: #f6faf9; min-height: 70vh; }
    .pr-card { max-width: 560px; margin: 0 auto; background: #fff; border: 1px solid #e3ecef; border-radius: 20px; padding: 36px 32px; text-align: center; box-shadow: 0 12px 34px rgba(15, 47, 58, .07); }
    .pr-ic { width: 72px; height: 72px; margin: 0 auto 16px; border-radius: 50%; display: grid; place-items: center; font-size: 32px; }
    .pr-ic.ok { background: #dcfce7; color: #16a34a; } .pr-ic.wait { background: #fef3c7; color: #b45309; } .pr-ic.bad { background: #fee2e2; color: #dc2626; }
    .pr h1 { font-size: 24px; margin: 0 0 8px; color: #0f2f3a; }
    .pr p { color: #557080; margin: 0 0 6px; }
    .pr dl { display: grid; grid-template-columns: auto 1fr; gap: 6px 16px; text-align: left; margin: 20px 0; padding: 16px 18px; background: #f6faf9; border-radius: 12px; }
    .pr dt { color: #6b8592; } .pr dd { margin: 0; color: #0f2f3a; font-weight: 600; }
    .pr-acts { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; margin-top: 18px; }
    .pr-btn { display: inline-flex; padding: 11px 20px; border-radius: 10px; background: #168F99; color: #fff; font-weight: 700; text-decoration: none; }
    .pr-btn:hover { background: #0f766e; color: #fff; }
    .pr-btn--ghost { background: #fff; color: #0f2f3a; border: 1px solid #cfdde2; }
    .pr-btn--ghost:hover { background: #f4fbfa; color: #0f2f3a; }
</style>
<main class="pr">
    <div class="pr-card" role="status">
        @if (!$r['valid'])
            <div class="pr-ic bad"><i class="fas fa-shield-alt"></i></div>
            <h1>Không xác thực được kết quả</h1>
            <p>Dữ liệu trả về không hợp lệ (sai chữ ký). Đơn của bạn không bị thay đổi. Vui lòng kiểm tra lại trong mục “Tour đã đặt”.</p>
        @elseif ($paid)
            <div class="pr-ic ok"><i class="fas fa-check"></i></div>
            <h1>Thanh toán thành công</h1>
            <p>Travela đã nhận thanh toán, đơn của bạn đã được xác nhận. Email xác nhận sẽ được gửi trong ít phút.</p>
        @elseif ($ok)
            <div class="pr-ic wait"><i class="fas fa-hourglass-half"></i></div>
            <h1>Đang chờ {{ $gwName }} xác nhận</h1>
            <p>{{ $gwName }} báo giao dịch thành công, hệ thống đang chờ xác nhận chính thức (thường vài giây đến 1 phút). Bạn có thể tải lại trang đơn sau ít phút.</p>
        @else
            <div class="pr-ic bad"><i class="fas fa-times"></i></div>
            <h1>Thanh toán chưa thành công</h1>
            <p>{{ $r['message'] }}. Chỗ vẫn được giữ{{ $b && $b->holdExpiresAt ? ' đến ' . \Carbon\Carbon::parse($b->holdExpiresAt)->format('H:i d/m/Y') : '' }}, bạn có thể thanh toán lại.</p>
        @endif

        @if ($b)
            <dl>
                <dt>Mã đơn</dt><dd>{{ $b->bookingCode }}</dd>
                <dt>Tour</dt><dd>{{ $b->title }}</dd>
                @if ($r['amount'])<dt>Số tiền</dt><dd>{{ number_format($r['amount'], 0, ',', '.') }} đ</dd>@endif
                <dt>Hình thức</dt><dd>{{ $gwName }}</dd>
            </dl>
        @endif

        <div class="pr-acts">
            @if ($b && $mine && $r['checkoutId'])
                <a class="pr-btn" href="{{ route('tour-booked', ['bookingId' => $b->bookingId, 'checkoutId' => $r['checkoutId']]) }}">Xem đơn</a>
                @if (!$paid && $r['valid'] && !$ok && $b->bookingStatus === 'n')
                    <a class="pr-btn pr-btn--ghost" href="{{ route('payment.pay', ['method' => $r['method'], 'bookingId' => $b->bookingId]) }}">Thanh toán lại</a>
                @endif
            @else
                <a class="pr-btn" href="{{ route('my-tours') }}">Tour đã đặt</a>
            @endif
            <a class="pr-btn pr-btn--ghost" href="{{ route('tours') }}">Xem tour khác</a>
        </div>
    </div>
</main>
@include('clients.blocks.footer')
