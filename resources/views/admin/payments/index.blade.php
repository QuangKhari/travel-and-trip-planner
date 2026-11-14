@extends('admin.layout')
@php
    $money = fn($v) => number_format((float) $v, 0, ',', '.');
    $kinds = ['create' => ['info', 'Tạo giao dịch'], 'return' => ['mute', 'Khách quay về'], 'notify' => ['teal', 'Cổng báo về'], 'query' => ['violet', 'Đối soát'], 'manual' => ['warn', 'Xác nhận tay']];
    $providers = ['vnpay' => 'VNPay', 'momo' => 'MoMo', 'qr' => 'QR chuyển khoản', 'zalopay' => 'ZaloPay (cũ)'];
    $tone = fn($r) => in_array($r, ['paid', 'url', 'duplicate'], true) ? 'ok'
        : (in_array($r, ['bad_signature', 'bad_amount', 'not_found', 'error', 'http_error', 'rejected'], true) ? 'bad'
        : (str_starts_with((string) $r, 'failed') || $r === 'unmatched' ? 'warn' : 'mute'));
    $resultLabel = ['paid' => 'đã thanh toán', 'url' => 'đã tạo', 'duplicate' => 'báo lặp (bỏ qua)', 'bad_signature' => 'sai chữ ký', 'bad_amount' => 'lệch số tiền',
        'not_found' => 'không thấy đơn', 'unmatched' => 'không khớp mã đơn', 'error' => 'lỗi xử lý', 'http_error' => 'lỗi kết nối', 'rejected' => 'cổng từ chối', 'pending' => 'đang chờ'];
@endphp
@section('content')
    <div class="ad-head">
        <div>
            <h1>Thanh toán trực tuyến</h1>
            <p>VNPay, MoMo và QR chuyển khoản. Mọi lần tạo giao dịch, khách quay về, cổng báo về và đối soát đều được ghi lại. Thông báo của cổng (có chữ ký) là nguồn xác nhận chính; trang khách quay về chỉ hiển thị. Đơn sắp hết hạn giữ chỗ được hỏi lại cổng tự động mỗi phút.</p>
        </div>
    </div>

    <div class="ad-grid ad-grid--3" style="margin-bottom:16px">
        @foreach ($gateways as $code => $g)
            <section class="ad-card" style="margin:0">
                <div class="ad-card__head"><h2>{{ $g['label'] }}</h2><span class="ad-badge ad-badge--{{ $g['on'] ? 'ok' : 'mute' }}">{{ $g['on'] ? 'Đang bật' : 'Đang tắt' }}</span></div>
                <div class="ad-card__body" style="font-size:13px">
                    @if ($code === 'qr')
                        @if ($g['on'])
                            <p style="margin:0 0 6px">{{ ($g['auto'] ?? false) ? 'Tự xác nhận qua webhook SePay. Địa chỉ webhook:' : 'Chưa nối SePay: đối chiếu sao kê và bấm “Đã nhận tiền” ở bảng bên dưới.' }}</p>
                            @if ($g['auto'] ?? false)<code class="ad-code" style="word-break:break-all">{{ $g['notify'] }}</code>@endif
                        @else
                            <p style="margin:0">Điền <code class="ad-code">QR_ENABLED=true</code>, <code class="ad-code">QR_BANK_ID</code>, <code class="ad-code">QR_ACCOUNT_NO</code>, <code class="ad-code">QR_ACCOUNT_NAME</code> vào <code class="ad-code">.env</code>.</p>
                        @endif
                    @else
                        @if ($g['on'])
                            <p style="margin:0 0 6px">{{ $code === 'vnpay' ? 'URL IPN cần khai báo trong trang quản trị merchant VNPay:' : 'Địa chỉ nhận thông báo (gửi kèm mỗi giao dịch, không cần khai báo với cổng):' }}</p>
                            <code class="ad-code" style="word-break:break-all">{{ $g['notify'] }}</code>
                        @else
                            <p style="margin:0">Điền khóa sandbox vào <code class="ad-code">.env</code> ({{ $code === 'momo' ? 'MOMO_ENABLED, MOMO_PARTNER_CODE, MOMO_ACCESS_KEY, MOMO_SECRET_KEY' : 'VNPAY_ENABLED, VNPAY_TMN_CODE, VNPAY_HASH_SECRET (và khai báo URL IPN ' . \App\Services\Payments\OnlinePaymentService::publicRoute('payment.notify', ['method' => 'vnpay']) . ' trong trang merchant VNPay)' }}) rồi chạy <code class="ad-code">php artisan config:clear</code>.</p>
                        @endif
                    @endif
                </div>
            </section>
        @endforeach
    </div>

    <div class="ad-kpis">
        <div class="ad-kpi"><div class="ad-kpi__ic ad-kpi__ic--green"><i class="fa fa-credit-card"></i></div><div><div class="ad-kpi__l">Thanh toán trực tuyến hôm nay</div><div class="ad-kpi__v">{{ $stats['paidToday'] }}</div><div class="ad-kpi__h">{{ $money($stats['amountToday']) }} đ</div></div></div>
        <div class="ad-kpi"><div class="ad-kpi__ic ad-kpi__ic--sand"><i class="fa fa-hourglass-half"></i></div><div><div class="ad-kpi__l">Đơn đang chờ thanh toán</div><div class="ad-kpi__v">{{ $stats['pending'] }}</div><div class="ad-kpi__h">Chưa nhận được tiền</div></div></div>
        <a class="ad-kpi" href="{{ route('admin.payments', ['problem' => 1]) }}"><div class="ad-kpi__ic ad-kpi__ic--red"><i class="fa fa-shield"></i></div><div><div class="ad-kpi__l">Bất thường 7 ngày</div><div class="ad-kpi__v">{{ $stats['bad'] }}</div><div class="ad-kpi__h">Sai chữ ký / lệch số tiền</div></div></a>
    </div>

    @if (count($qrPending))
        <section class="ad-card">
            <div class="ad-card__head"><h2>Chuyển khoản QR đang chờ</h2><small>Đối chiếu nội dung chuyển khoản = mã đơn và số tiền trên sao kê</small></div>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead><tr><th>Mã đơn (nội dung CK)</th><th class="num">Số tiền</th><th>Hạn giữ chỗ</th><th>Xác nhận đã nhận tiền</th></tr></thead>
                    <tbody>
                    @foreach ($qrPending as $p)
                        <tr>
                            <td><a href="{{ route('admin.booking-detail', ['id' => $p->bookingId]) }}"><b>{{ $p->bookingCode }}</b></a></td>
                            <td class="num"><b>{{ $money($p->amount) }} đ</b></td>
                            <td class="nowrap">{{ $p->holdExpiresAt ? date('H:i d/m', strtotime($p->holdExpiresAt)) : ($p->bookingStatus === 'y' ? 'đã xác nhận, trả sau' : '—') }}</td>
                            <td>
                                <form method="POST" action="{{ route('admin.payments.confirm', $p->bookingId) }}" style="display:flex;gap:6px;align-items:center">@csrf
                                    <input type="text" name="reference" required maxlength="40" placeholder="Mã GD trên sao kê" style="height:32px;border:1px solid var(--ad-line);border-radius:8px;padding:0 8px;width:170px">
                                    <button class="ad-btn ad-btn--sm ad-btn--primary" data-confirm="Xác nhận đã nhận {{ $money($p->amount) }} đ cho đơn {{ $p->bookingCode }}? Đơn sẽ chuyển sang Đã xác nhận và khách nhận email.">Đã nhận tiền</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <div class="ad-card">
        <form class="ad-toolbar" method="GET" action="{{ route('admin.payments') }}">
            <div class="ad-chips">
                <a class="ad-chip {{ !$kind && !$provider && !request('problem') ? 'is-on' : '' }}" href="{{ route('admin.payments') }}">Tất cả</a>
                @foreach (['vnpay', 'momo', 'qr'] as $pv)<a class="ad-chip {{ $provider === $pv ? 'is-on' : '' }}" href="{{ route('admin.payments', ['provider' => $pv]) }}">{{ $providers[$pv] }}</a>@endforeach
                @foreach ($kinds as $k => [$c, $l])<a class="ad-chip {{ $kind === $k ? 'is-on' : '' }}" href="{{ route('admin.payments', array_filter(['kind' => $k, 'provider' => $provider])) }}">{{ $l }}</a>@endforeach
                <a class="ad-chip {{ request('problem') ? 'is-on' : '' }}" href="{{ route('admin.payments', ['problem' => 1]) }}">Bất thường</a>
            </div>
            <span class="ad-grow"></span>
            <div class="ad-search" style="max-width:280px"><i class="fa fa-search"></i><input type="search" name="q" value="{{ is_string(request('q')) ? request('q') : '' }}" placeholder="Mã đơn / mã giao dịch"></div>
        </form>
        @if ($rows->count())
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead><tr><th>Thời điểm</th><th>Cổng</th><th>Loại</th><th>Đơn · mã giao dịch</th><th>Kết quả</th><th>Chữ ký</th><th>IP</th><th class="num"></th></tr></thead>
                    <tbody>
                    @foreach ($rows as $l)
                        @php $p = json_decode($l->payload ?? '[]', true) ?: [];
                             $amt = $p['amount'] ?? ($p['data']['amount'] ?? ($p['transferAmount'] ?? ($p['request']['amount'] ?? (isset($p['vnp_Amount']) ? $p['vnp_Amount'] / 100 : null)))); @endphp
                        <tr>
                            <td class="nowrap">{{ date('H:i:s', strtotime($l->createdAt)) }}<span class="ad-sub">{{ date('d/m/Y', strtotime($l->createdAt)) }}</span></td>
                            <td class="nowrap">{{ $providers[$l->provider] ?? $l->provider }}</td>
                            <td><span class="ad-badge ad-badge--{{ $kinds[$l->kind][0] ?? 'mute' }} ad-badge--plain">{{ $kinds[$l->kind][1] ?? $l->kind }}</span></td>
                            <td class="nowrap">@if ($l->bookingId)<a href="{{ route('admin.booking-detail', ['id' => $l->bookingId]) }}">{{ $l->bookingCode }}</a>@else<span class="ad-muted">không khớp đơn</span>@endif
                                <span class="ad-sub">{{ $l->txnRef }}{{ is_numeric($amt) ? ' · ' . $money($amt) . ' đ' : '' }}</span></td>
                            <td><span class="ad-badge ad-badge--{{ $tone($l->result) }}">{{ $resultLabel[$l->result] ?? ($l->result ?? '—') }}</span></td>
                            <td>{!! $l->validSignature === null ? '<span class="ad-muted">—</span>' : ($l->validSignature ? '<i class="fa fa-check" style="color:var(--ad-ok)"></i> hợp lệ' : '<i class="fa fa-times" style="color:var(--ad-bad)"></i> sai') !!}</td>
                            <td class="nowrap ad-muted">{{ $l->ip }}</td>
                            <td class="num"><button type="button" class="ad-btn ad-btn--sm" data-toggle="collapse" data-target="#pl-{{ $l->logId }}">Payload</button></td>
                        </tr>
                        <tr class="collapse" id="pl-{{ $l->logId }}"><td colspan="8" style="background:#fbfcfd"><pre style="margin:0;max-height:320px;overflow:auto;font-size:12px;white-space:pre-wrap">{{ json_encode($p, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($rows->hasPages())<div class="ad-pager"><span class="ad-note">{{ $rows->total() }} dòng</span>{{ $rows->links('admin.partials.pager') }}</div>@endif
        @else
            <div class="ad-empty"><i class="fa fa-credit-card"></i><b>Chưa có giao dịch</b>Nhật ký xuất hiện khi khách chọn VNPay, MoMo hoặc QR chuyển khoản.</div>
        @endif
    </div>
@endsection
