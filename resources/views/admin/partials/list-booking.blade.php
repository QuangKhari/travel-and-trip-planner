@php
    use App\Http\Controllers\admin\BookingManagementController as BM;
    $canManage = in_array(session('adminRole'), ['admin', 'manager'], true);
@endphp
@foreach ($list_booking as $booking)
    @php
        [$sClass, $sLabel] = BM::STATUS[$booking->bookingStatus] ?? ['mute', $booking->bookingStatus];
        [$pClass, $pLabel] = BM::PAY[$booking->paymentStatus] ?? ['mute', $booking->paymentStatus];
        $why = $booking->bookingStatus === 'c' && !empty($booking->cancelReason) ? (BM::CANCEL_REASON[$booking->cancelReason] ?? $booking->cancelReason) : null;
        $detail = route('admin.booking-detail', ['id' => $booking->bookingId]);
    @endphp
    <tr data-status="{{ $booking->bookingStatus }}" data-pay="{{ $booking->paymentStatus }}" data-href="{{ $detail }}" class="{{ $booking->bookingStatus === 'c' ? 'is-muted' : '' }}">
        <td class="nowrap">
            <a href="{{ $detail }}"><b>{{ $booking->bookingCode }}</b></a>
            <span class="ad-sub">đặt {{ date('H:i d/m/Y', strtotime($booking->bookingDate)) }}</span>
        </td>
        <td style="min-width:220px">
            <b style="color:var(--ad-ink)" title="{{ $booking->title }}">{{ \Illuminate\Support\Str::limit($booking->title, 40) }}</b>
            <span class="ad-sub"><i class="fa fa-calendar-o"></i> {{ date('d/m', strtotime($booking->tripStart)) }} → {{ date('d/m/Y', strtotime($booking->tripEnd)) }}</span>
        </td>
        <td style="min-width:160px" title="{{ $booking->email }}">
            {{ $booking->fullName }}
            <span class="ad-sub nowrap">{{ $booking->phoneNumber }} · {{ $booking->numAdults }} NL{{ $booking->numChildren ? ' + ' . $booking->numChildren . ' TE' : '' }}</span>
            <span class="ad-sub" style="display:none">{{ $booking->email }}</span>
        </td>
        <td class="num nowrap"><b>{{ number_format($booking->totalPrice, 0, ',', '.') }}</b>
            @if (!empty($booking->couponCode))<span class="ad-sub">mã {{ $booking->couponCode }}</span>@endif</td>
        <td>
            <span class="ad-badge ad-badge--{{ $sClass }}">{{ $sLabel }}</span>
            @if ($why)<span class="ad-sub">{{ $why }}</span>@endif
            @if ($booking->holdLeft && $booking->paymentStatus === 'n')
                <span class="ad-sub" title="Hết hạn giữ chỗ lúc {{ $booking->holdLeft->format('H:i d/m/Y') }}">giữ chỗ đến {{ $booking->holdLeft->format('H:i d/m') }}</span>
            @endif
        </td>
        <td>
            <span class="ad-badge ad-badge--{{ $pClass }}">{{ $pLabel }}</span>
            <span class="ad-sub">{{ BM::METHOD[$booking->paymentMethod] ?? $booking->paymentMethod }}{{ !empty($booking->responseCode) && $booking->responseCode !== '00' && $booking->paymentStatus === 'n' ? ' · lỗi ' . $booking->responseCode : '' }}</span>
            @if (!empty($booking->refundAmount) && $booking->paymentStatus !== 'y')<span class="ad-sub">hoàn {{ number_format($booking->refundAmount, 0, ',', '.') }} ({{ $booking->refundPercent }}%)</span>@endif
        </td>
        <td>
            <div class="ad-rowact">
                @if ($booking->bookingStatus === 'n')
                    <button type="button" class="ad-btn ad-btn--sm ad-btn--primary js-bk" data-act="confirm" data-id="{{ $booking->bookingId }}" data-code="{{ $booking->bookingCode }}" data-url="{{ route('admin.confirm-booking') }}">Xác nhận</button>
                @endif
                @if ($canManage && $booking->paymentStatus === 'n' && $booking->bookingStatus !== 'c')
                    <button type="button" class="ad-btn ad-btn--sm js-bk" data-act="paid" data-id="{{ $booking->bookingId }}" data-code="{{ $booking->bookingCode }}" data-amount="{{ number_format($booking->totalPrice, 0, ',', '.') }}" data-url="{{ route('admin.confirm-payment') }}">Đã thu tiền</button>
                @endif
                @if ($canManage && $booking->canFinish)
                    <button type="button" class="ad-btn ad-btn--sm js-bk" data-act="finish" data-id="{{ $booking->bookingId }}" data-code="{{ $booking->bookingCode }}" data-url="{{ route('admin.finish-booking') }}">Hoàn tất</button>
                @endif
                @if ($canManage && in_array($booking->bookingStatus, ['n', 'y'], true))
                    <button type="button" class="ad-btn ad-btn--sm ad-btn--ghost ad-btn--icon js-bk" data-act="cancel" data-id="{{ $booking->bookingId }}" data-code="{{ $booking->bookingCode }}" data-url="{{ route('admin.cancel-booking') }}" title="Hủy đơn" aria-label="Hủy đơn {{ $booking->bookingCode }}"><i class="fa fa-ban" style="color:var(--ad-bad)"></i></button>
                @endif
            </div>
        </td>
    </tr>
@endforeach
