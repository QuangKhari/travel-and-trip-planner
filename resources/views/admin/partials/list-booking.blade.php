@php
    $statusMap = ['n' => ['warn', 'Chưa xác nhận'], 'y' => ['info', 'Đã xác nhận'], 'f' => ['ok', 'Hoàn thành'], 'c' => ['bad', 'Đã hủy']];
    $payMethod = ['office-payment' => 'Tại văn phòng', 'vnpay' => 'VNPay', 'momo-payment' => 'MoMo', 'paypal-payment' => 'PayPal'];
@endphp
@foreach ($list_booking as $booking)
    @php [$sClass, $sLabel] = $statusMap[$booking->bookingStatus] ?? ['mute', $booking->bookingStatus]; @endphp
    <tr data-status="{{ $booking->bookingStatus }}" data-pay="{{ $booking->paymentStatus }}">
        <td style="min-width:200px"><b>{{ \Illuminate\Support\Str::limit($booking->title, 48) }}</b></td>
        <td>{{ $booking->fullName }}</td>
        <td>{{ $booking->email }}<br><small class="ad-note">{{ $booking->phoneNumber }}</small></td>
        <td>{{ \Illuminate\Support\Str::limit($booking->address, 36) }}</td>
        <td>{{ date('d/m/Y', strtotime($booking->bookingDate)) }}</td>
        <td class="num">{{ $booking->numAdults }}</td>
        <td class="num">{{ $booking->numChildren }}</td>
        <td class="num">{{ number_format($booking->totalPrice, 0, ',', '.') }}</td>
        <td><span class="ad-badge ad-badge--{{ $sClass }}">{{ $sLabel }}</span></td>
        <td>{{ $payMethod[$booking->paymentMethod] ?? $booking->paymentMethod }}</td>
        <td>
            @if ($booking->paymentStatus == 'n')
                <span class="ad-badge ad-badge--bad">Chưa thanh toán</span>
            @else
                <span class="ad-badge ad-badge--ok">Đã thanh toán</span>
            @endif
        </td>
        <td>
            {{-- Giữ nguyên class + data-* của các nút: custom-js.js gắn sự kiện theo tên class --}}
            <div class="ad-rowact">
                @if ($booking->bookingStatus == 'n')
                    <a class="ad-btn ad-btn--sm ad-btn--primary confirm-booking" href="javascript:void(0)"
                        data-bookingId="{{ $booking->bookingId }}" data-urlConfirm="{{ route('admin.confirm-booking') }}"
                        data-confirm="Xác nhận đơn #{{ $booking->bookingId }}?">Xác nhận</a>
                @endif
                @if ($booking->paymentStatus == 'n' && $booking->bookingStatus != 'c')
                    <a class="ad-btn ad-btn--sm confirm-payment" href="javascript:void(0)"
                        data-bookingId="{{ $booking->bookingId }}" data-urlPayment="{{ route('admin.confirm-payment') }}"
                        data-confirm="Xác nhận đã nhận tiền cho đơn #{{ $booking->bookingId }}?">Đã thu tiền</a>
                @endif
                @if ($booking->bookingStatus == 'y')
                    <a class="ad-btn ad-btn--sm finish-booking {{ $booking->hide }}" href="javascript:void(0)"
                        data-bookingId="{{ $booking->bookingId }}" data-urlfinish="{{ route('admin.finish-booking') }}"
                        data-confirm="Đánh dấu đơn #{{ $booking->bookingId }} đã hoàn thành?">Hoàn thành</a>
                @endif
                @if (in_array($booking->bookingStatus, ['n', 'y']) && $booking->paymentStatus != 'y')
                    {{-- custom-js.js đã tự hỏi xác nhận khi hủy, nên không thêm data-confirm ở đây --}}
                    <a class="ad-btn ad-btn--sm ad-btn--danger cancel-booking-admin" href="javascript:void(0)"
                        data-bookingId="{{ $booking->bookingId }}" data-urlcancel="{{ route('admin.cancel-booking') }}">Hủy</a>
                @endif
                <a class="ad-btn ad-btn--sm" href="{{ route('admin.booking-detail', ['id' => $booking->bookingId]) }}">Chi tiết</a>
            </div>
        </td>
    </tr>
@endforeach
