@extends('admin.layout')
@php $money = fn($v) => number_format((float) $v, 0, ',', '.') . ' đ'; @endphp
@section('content')
    <div class="ad-head">
        <div>
            <h1>Hoàn tiền</h1>
            <p>Đơn đã thanh toán bị hủy tạo yêu cầu hoàn theo chính sách: còn từ 14 ngày hoàn 100%, 7–13 ngày hoàn 50%. Đơn trả bằng VNPay / MoMo có thể hoàn thẳng qua API của cổng (khi PAYMENT_REFUND_ENABLED=true); đơn QR chuyển khoản hoặc xác nhận tay thì chuyển tiền cho khách rồi bấm “Đã hoàn” để ghi nhận.</p>
        </div>
    </div>

    <div class="ad-kpis">
        <div class="ad-kpi"><div class="ad-kpi__ic ad-kpi__ic--violet"><i class="fa fa-hourglass-half"></i></div>
            <div><div class="ad-kpi__l">Chờ hoàn</div><div class="ad-kpi__v">{{ (int) ($sum['pending']->n ?? 0) }}</div><div class="ad-kpi__h">{{ $money($sum['pending']->total ?? 0) }} cần chuyển</div></div></div>
        <div class="ad-kpi"><div class="ad-kpi__ic ad-kpi__ic--green"><i class="fa fa-check"></i></div>
            <div><div class="ad-kpi__l">Đã hoàn</div><div class="ad-kpi__v">{{ (int) ($sum['refunded']->n ?? 0) }}</div><div class="ad-kpi__h">{{ $money($sum['refunded']->total ?? 0) }} đã chuyển</div></div></div>
    </div>

    @if (($failed ?? 0) > 0 || ($processing ?? 0) > 0)
        <div class="ad-card" style="margin-bottom:16px;border-left:4px solid #E9C46A;padding:12px 16px">
            @if ($failed > 0)<b>{{ $failed }} lệnh hoàn qua cổng bị từ chối</b> — mở dòng đỏ, thử lại hoặc hoàn thủ công.@endif
            @if ($processing > 0)<span style="margin-left:12px"><b>{{ $processing }} lệnh đang xử lý tại cổng</b> — hệ thống tự hỏi lại mỗi 5 phút, hoặc bấm “Kiểm tra trạng thái”.</span>@endif
        </div>
    @endif

    <div class="ad-card">
        <div class="ad-toolbar">
            <div class="ad-chips">
                @foreach (['pending' => 'Chờ hoàn', 'refunded' => 'Đã hoàn', 'all' => 'Tất cả'] as $k => $l)
                    <a class="ad-chip {{ $status === $k ? 'is-on' : '' }}" href="{{ route('admin.refunds', ['status' => $k]) }}">{{ $l }}</a>
                @endforeach
            </div>
        </div>
        @if ($rows->count())
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead><tr><th>Đơn</th><th>Khách hàng</th><th>Lý do</th><th class="num">Đã trả</th><th class="num">Hoàn</th><th>Trạng thái</th><th class="num">Xử lý</th></tr></thead>
                    <tbody>
                    @foreach ($rows as $r)
                        <tr id="refund-{{ $r->refundId }}">
                            <td class="nowrap"><a href="{{ route('admin.booking-detail', ['id' => $r->bookingId]) }}"><b>{{ $r->bookingCode }}</b></a>
                                <span class="ad-sub" title="{{ $r->title }}">{{ \Illuminate\Support\Str::limit($r->title, 30) }}</span></td>
                            <td>{{ $r->fullName }}<span class="ad-sub">{{ $r->phoneNumber }} · {{ $r->email }}</span></td>
                            <td style="max-width:240px">{{ $r->requestedBy === 'customer' ? 'Khách tự hủy' : 'Admin hủy' }} · trước {{ $r->daysBefore }} ngày
                                @if ($r->reason)<span class="ad-sub">{{ $r->reason }}</span>@endif
                                <span class="ad-sub">{{ date('H:i d/m/Y', strtotime($r->createdAt)) }}</span></td>
                            <td class="num">{{ $money($r->paidAmount) }}</td>
                            <td class="num"><b>{{ $money($r->amount) }}</b><span class="ad-sub">{{ $r->percent }}%</span></td>
                            <td>
                                @if ($r->status === 'pending')
                                    @if (($r->gatewayStatus ?? null) === 'processing')
                                        <span class="ad-badge ad-badge--warn">Đang xử lý tại cổng</span>
                                    @elseif (($r->gatewayStatus ?? null) === 'failed')
                                        <span class="ad-badge ad-badge--bad">Cổng từ chối</span>
                                    @else
                                        <span class="ad-badge ad-badge--violet">Chờ hoàn</span>
                                    @endif
                                    @if (!empty($r->gatewayMessage))<span class="ad-sub">{{ $r->gatewayMessage }}</span>@endif
                                    @if (!empty($r->gatewayAttempts))<span class="ad-sub">Đã gửi {{ $r->gatewayAttempts }} lần</span>@endif
                                @else
                                    <span class="ad-badge ad-badge--mute">Đã hoàn</span>
                                    <span class="ad-sub">{{ $r->method }}{{ $r->reference ? ' · ' . $r->reference : '' }}</span>
                                    <span class="ad-sub">{{ $r->processedName ? $r->processedName . ' · ' : '' }}{{ $r->processedAt ? date('d/m/Y', strtotime($r->processedAt)) : '' }}</span>
                                @endif
                            </td>
                            <td class="num">
                                @if ($r->status === 'pending' && ($r->gatewayStatus ?? null) === 'processing')
                                    <form method="POST" action="{{ route('admin.refunds.sync', $r->refundId) }}">@csrf<button class="ad-btn ad-btn--sm ad-btn--primary" type="submit"><i class="fa fa-refresh"></i> Kiểm tra trạng thái</button></form>
                                @elseif ($r->status === 'pending')
                                    @if (!empty($r->gw['can']) || ($r->gatewayStatus ?? null) === 'failed')
                                        @if (!empty($r->gw['can']) || \App\Services\Payments\GatewayRefundService::enabled())
                                            <form method="POST" action="{{ route('admin.refunds.gateway', $r->refundId) }}" style="margin-bottom:6px">@csrf<button class="ad-btn ad-btn--sm ad-btn--primary" type="submit" data-confirm="Gửi lệnh hoàn {{ $money($r->amount) }} cho đơn {{ $r->bookingCode }} qua API cổng thanh toán? Đây là tiền thật và không thể thu hồi.">{{ ($r->gatewayStatus ?? null) === 'failed' ? 'Thử lại qua cổng' : 'Hoàn qua ' . ($r->gw['label'] ?? 'cổng') }}</button></form>
                                        @endif
                                    @elseif (!empty($r->gw['why']) && \App\Services\Payments\GatewayRefundService::enabled())
                                        <span class="ad-sub" title="{{ $r->gw['why'] }}">Hoàn thủ công</span>
                                    @endif
                                    <button type="button" class="ad-btn ad-btn--sm" data-toggle="modal" data-target="#rf-modal"
                                        data-id="{{ $r->refundId }}" data-code="{{ $r->bookingCode }}" data-amount="{{ $money($r->amount) }}" data-name="{{ $r->fullName }}">Đã hoàn…</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($rows->hasPages())<div class="ad-pager">{{ $rows->links('admin.partials.pager') }}</div>@endif
        @else
            <div class="ad-empty"><i class="fa fa-check-circle"></i><b>Không có yêu cầu nào</b>{{ $status === 'pending' ? 'Mọi khoản hoàn tiền đã được xử lý.' : '' }}</div>
        @endif
    </div>

    <div class="modal fade" id="rf-modal" tabindex="-1" role="dialog" aria-labelledby="rf-title" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <form class="modal-content" method="POST" id="rf-form" data-base="{{ url('admin/refunds') }}">
                @csrf
                <div class="modal-header"><h5 class="modal-title" id="rf-title">Ghi nhận đã hoàn tiền</h5><button type="button" class="close" data-dismiss="modal" aria-label="Đóng">&times;</button></div>
                <div class="modal-body">
                    <p id="rf-desc" style="margin-top:0"></p>
                    <div class="ad-form">
                        <div class="ad-fld"><label for="rf-method">Cách hoàn <span class="req">*</span></label>
                            <select id="rf-method" name="method" required>
                                <option>Chuyển khoản</option><option>Tiền mặt</option><option>Ví điện tử</option><option>Khác</option>
                            </select></div>
                        <div class="ad-fld"><label for="rf-ref">Mã giao dịch / số chứng từ</label><input id="rf-ref" name="reference" maxlength="80" placeholder="Vd: FT2611...">
                            <span class="ad-hint">Nên ghi để đối soát khi khách hỏi lại.</span></div>
                        <div class="ad-fld"><label for="rf-note">Ghi chú</label><input id="rf-note" name="note" maxlength="255"></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="ad-btn" data-dismiss="modal">Thôi</button><button class="ad-btn ad-btn--primary" type="submit"><i class="fa fa-check"></i> Đã hoàn tiền</button></div>
            </form>
        </div>
    </div>
@endsection
@push('scripts')
<script>
    $('#rf-modal').on('show.bs.modal', function (e) {
        var b = e.relatedTarget.dataset, f = document.getElementById('rf-form');
        f.action = f.dataset.base + '/' + b.id + '/complete';
        document.getElementById('rf-desc').innerHTML = 'Hoàn <b>' + b.amount + '</b> cho <b>' + b.name + '</b> (đơn ' + b.code + '). Khách sẽ nhận email xác nhận.';
    });
    if (location.hash.indexOf('#refund-') === 0) { var r = document.querySelector(location.hash); if (r) r.classList.add('is-hl'); }
</script>
@endpush
