@extends('admin.layout')
@php
    $money = fn($v) => number_format((float) $v, 0, ',', '.');
    $kinds = ['create' => ['info', 'Tạo link'], 'return' => ['mute', 'Khách quay về'], 'ipn' => ['teal', 'IPN'], 'querydr' => ['violet', 'Đối soát']];
    $tone = fn($r) => in_array($r, ['paid', '00/00', '00'], true) ? 'ok' : (in_array($r, ['97', '04', '01', '99', 'http_error'], true) ? 'bad' : (str_starts_with((string) $r, 'failed') ? 'warn' : 'mute'));
@endphp
@section('content')
    <div class="ad-head">
        <div>
            <h1>Giao dịch VNPay</h1>
            <p>Mọi lần tạo link, khách quay về, IPN và đối soát đều được ghi lại. IPN là nguồn xác nhận duy nhất; trang khách quay về chỉ hiển thị. Đơn VNPay sắp hết hạn giữ chỗ được hỏi lại VNPay (querydr) tự động mỗi phút.</p>
        </div>
    </div>
    @unless ($enabled)
        <div class="ad-flash ad-flash--warn"><i class="fa fa-plug"></i><div>VNPay đang <b>tắt</b>. Điền <code class="ad-code">VNPAY_ENABLED=true</code>, <code class="ad-code">VNPAY_TMN_CODE</code>, <code class="ad-code">VNPAY_HASH_SECRET</code> vào <code class="ad-code">.env</code> rồi chạy <code class="ad-code">php artisan config:clear</code>. Khai báo IPN URL với VNPay: <code class="ad-code">{{ route('payment.vnpay.ipn') }}</code></div></div>
    @endunless
    <div class="ad-kpis">
        <div class="ad-kpi"><div class="ad-kpi__ic ad-kpi__ic--green"><i class="fa fa-credit-card"></i></div><div><div class="ad-kpi__l">Thanh toán VNPay hôm nay</div><div class="ad-kpi__v">{{ $stats['paidToday'] }}</div><div class="ad-kpi__h">{{ $money($stats['amountToday']) }} đ</div></div></div>
        <div class="ad-kpi"><div class="ad-kpi__ic ad-kpi__ic--sand"><i class="fa fa-hourglass-half"></i></div><div><div class="ad-kpi__l">Đơn VNPay đang chờ</div><div class="ad-kpi__v">{{ $stats['pending'] }}</div><div class="ad-kpi__h">Chưa có IPN thành công</div></div></div>
        <a class="ad-kpi" href="{{ route('admin.payments', ['problem' => 1]) }}"><div class="ad-kpi__ic ad-kpi__ic--red"><i class="fa fa-shield"></i></div><div><div class="ad-kpi__l">Bất thường 7 ngày</div><div class="ad-kpi__v">{{ $stats['bad'] }}</div><div class="ad-kpi__h">Sai chữ ký / lệch số tiền</div></div></a>
    </div>
    <div class="ad-card">
        <form class="ad-toolbar" method="GET" action="{{ route('admin.payments') }}">
            <div class="ad-chips">
                <a class="ad-chip {{ !$kind && !request('problem') ? 'is-on' : '' }}" href="{{ route('admin.payments') }}">Tất cả</a>
                @foreach ($kinds as $k => [$c, $l])<a class="ad-chip {{ $kind === $k ? 'is-on' : '' }}" href="{{ route('admin.payments', ['kind' => $k]) }}">{{ $l }}</a>@endforeach
                <a class="ad-chip {{ request('problem') ? 'is-on' : '' }}" href="{{ route('admin.payments', ['problem' => 1]) }}">Bất thường</a>
            </div>
            <span class="ad-grow"></span>
            <div class="ad-search" style="max-width:280px"><i class="fa fa-search"></i><input type="search" name="q" value="{{ request('q') }}" placeholder="Mã đơn / vnp_TxnRef"></div>
        </form>
        @if ($rows->count())
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead><tr><th>Thời điểm</th><th>Loại</th><th>Đơn · TxnRef</th><th>Kết quả</th><th>Chữ ký</th><th>IP</th><th class="num"></th></tr></thead>
                    <tbody>
                    @foreach ($rows as $l)
                        @php $p = json_decode($l->payload ?? '[]', true) ?: []; $amt = $p['vnp_Amount'] ?? ($p['response']['vnp_Amount'] ?? null); @endphp
                        <tr>
                            <td class="nowrap">{{ date('H:i:s', strtotime($l->createdAt)) }}<span class="ad-sub">{{ date('d/m/Y', strtotime($l->createdAt)) }}</span></td>
                            <td><span class="ad-badge ad-badge--{{ $kinds[$l->kind][0] ?? 'mute' }} ad-badge--plain">{{ $kinds[$l->kind][1] ?? $l->kind }}</span></td>
                            <td class="nowrap">@if ($l->bookingId)<a href="{{ route('admin.booking-detail', ['id' => $l->bookingId]) }}">{{ $l->bookingCode }}</a>@else<span class="ad-muted">không khớp đơn</span>@endif
                                <span class="ad-sub">{{ $l->txnRef }}{{ $amt ? ' · ' . $money($amt / 100) . ' đ' : '' }}</span></td>
                            <td><span class="ad-badge ad-badge--{{ $tone($l->result) }}">{{ $l->result ?? '—' }}</span>
                                @if (isset($p['vnp_ResponseCode']))<span class="ad-sub">{{ \App\Services\VnpayService::RESPONSE[$p['vnp_ResponseCode']] ?? '' }}</span>@endif</td>
                            <td>{!! $l->validSignature === null ? '<span class="ad-muted">—</span>' : ($l->validSignature ? '<i class="fa fa-check" style="color:var(--ad-ok)"></i> hợp lệ' : '<i class="fa fa-times" style="color:var(--ad-bad)"></i> sai') !!}</td>
                            <td class="nowrap ad-muted">{{ $l->ip }}</td>
                            <td class="num"><button type="button" class="ad-btn ad-btn--sm" data-toggle="collapse" data-target="#pl-{{ $l->logId }}">Payload</button></td>
                        </tr>
                        <tr class="collapse" id="pl-{{ $l->logId }}"><td colspan="7" style="background:#fbfcfd"><pre style="margin:0;max-height:320px;overflow:auto;font-size:12px;white-space:pre-wrap">{{ json_encode($p, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($rows->hasPages())<div class="ad-pager"><span class="ad-note">{{ $rows->total() }} dòng</span>{{ $rows->links('admin.partials.pager') }}</div>@endif
        @else
            <div class="ad-empty"><i class="fa fa-credit-card"></i><b>Chưa có giao dịch VNPay</b>Nhật ký xuất hiện khi khách chọn thanh toán VNPay.</div>
        @endif
    </div>
@endsection
