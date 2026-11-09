@extends('admin.layout')
@php
    use App\Http\Controllers\admin\BookingManagementController as BM;
    use App\Http\Controllers\admin\AuditLogController as AL;
    $money = fn($v) => number_format((float) $v, 0, ',', '.') . ' đ';
    $canManage = in_array(session('adminRole'), ['admin', 'manager'], true);
    [$sClass, $sLabel] = BM::STATUS[$b->bookingStatus] ?? ['mute', $b->bookingStatus];
    [$pClass, $pLabel] = BM::PAY[$b->paymentStatus] ?? ['mute', $b->paymentStatus];
    $subtotal = (int) round($b->priceAdult * $b->numAdults + $b->priceChild * $b->numChildren);
    $discount = (int) ($b->discountAmount ?? max(0, $subtotal - (int) $b->totalPrice));
    $method = BM::METHOD[$b->paymentMethod] ?? $b->paymentMethod;
    $stage = ['n' => 1, 'y' => 2, 'f' => 3, 'c' => 0][$b->bookingStatus] ?? 0;
    $site = ['name' => config('site.name', 'Travela'), 'address' => config('site.address') ?: '—', 'phone' => config('site.phone') ?: '—', 'email' => config('site.email') ?: '—'];
@endphp

@section('content')
    <div class="ad-head" data-context="detail" data-url-preview="{{ $canManage ? route('admin.cancel-preview') : '' }}">
        <div>
            <a class="ad-back" href="{{ route('admin.booking') }}"><i class="fa fa-arrow-left"></i> Đơn đặt tour</a>
            <h1>Đơn {{ $b->bookingCode }}</h1>
            <p>
                <span class="ad-badge ad-badge--{{ $sClass }}">{{ $sLabel }}{{ $b->bookingStatus === 'c' && $b->cancelReason ? ' · ' . (BM::CANCEL_REASON[$b->cancelReason] ?? $b->cancelReason) : '' }}</span>
                <span class="ad-badge ad-badge--{{ $pClass }}">{{ $pLabel }}</span>
                <span class="ad-muted">· đặt lúc {{ date('H:i d/m/Y', strtotime($b->bookingDate)) }}</span>
            </p>
        </div>
        <div class="ad-actions no-print">
            @if ($b->bookingStatus === 'n')
                <button type="button" class="ad-btn ad-btn--primary js-bk" data-act="confirm" data-id="{{ $b->bookingId }}" data-code="{{ $b->bookingCode }}" data-url="{{ route('admin.confirm-booking') }}"><i class="fa fa-check"></i> Xác nhận đơn</button>
            @endif
            @if ($canManage && $b->paymentStatus === 'n' && $b->bookingStatus !== 'c')
                <button type="button" class="ad-btn js-bk" data-act="paid" data-id="{{ $b->bookingId }}" data-code="{{ $b->bookingCode }}" data-amount="{{ number_format($b->totalPrice, 0, ',', '.') }}" data-url="{{ route('admin.confirm-payment') }}"><i class="fa fa-money"></i> Đã thu tiền</button>
            @endif
            @if ($canManage && $b->canFinish)
                <button type="button" class="ad-btn js-bk" data-act="finish" data-id="{{ $b->bookingId }}" data-code="{{ $b->bookingCode }}" data-url="{{ route('admin.finish-booking') }}"><i class="fa fa-flag-checkered"></i> Hoàn tất</button>
            @endif
            @if ($canManage && in_array($b->bookingStatus, ['n', 'y'], true))
                <button type="button" class="ad-btn ad-btn--danger js-bk" data-act="cancel" data-id="{{ $b->bookingId }}" data-code="{{ $b->bookingCode }}" data-url="{{ route('admin.cancel-booking') }}"><i class="fa fa-ban"></i> Hủy đơn</button>
            @endif
        </div>
    </div>

    @if ($b->holdLeft && $b->paymentStatus === 'n')
        <div class="ad-flash ad-flash--warn"><i class="fa fa-clock-o"></i>
            <div>Chỗ được giữ đến <b>{{ $b->holdLeft->format('H:i d/m/Y') }}</b> ({{ $b->holdLeft->diffForHumans() }}). Quá hạn mà chưa xác nhận / chưa thu tiền thì hệ thống tự hủy và trả chỗ.</div></div>
    @endif

    <div class="ad-grid ad-grid--side">
        <div>
            {{-- Tiến trình --}}
            <section class="ad-card">
                <div class="ad-card__body">
                    @if ($stage)
                        <ol class="ad-steps" style="margin:0;padding:0">
                            <li class="{{ $stage >= 1 ? 'is-done' : '' }} {{ $stage === 1 ? 'is-on' : '' }}">Đặt chỗ</li>
                            <li class="{{ $stage >= 2 ? 'is-done' : '' }} {{ $stage === 2 ? 'is-on' : '' }}">Xác nhận</li>
                            <li class="{{ $b->paymentStatus !== 'n' ? 'is-done' : '' }}">Thanh toán</li>
                            <li class="{{ $stage >= 3 ? 'is-done is-on' : '' }}">Hoàn tất chuyến đi</li>
                        </ol>
                    @else
                        <div class="ad-flash ad-flash--bad" style="margin:0"><i class="fa fa-ban"></i><div>Đơn đã hủy{{ $b->cancelReason ? ' (' . (BM::CANCEL_REASON[$b->cancelReason] ?? '') . ')' : '' }}. Chỗ đã được trả lại cho ngày khởi hành.</div></div>
                    @endif
                </div>
            </section>

            {{-- Hóa đơn --}}
            <section class="ad-card" id="invoice">
                <div class="ad-invoice__head">
                    <div>
                        <small class="ad-muted">HÓA ĐƠN {{ $invoiceNo ? '· ' . $invoiceNo : '(chưa cấp số)' }}</small>
                        <h2>{{ $b->title }}</h2>
                        <div class="ad-muted"><i class="fa fa-calendar-o"></i> {{ date('d/m/Y', strtotime($b->tripStart)) }} → {{ date('d/m/Y', strtotime($b->tripEnd)) }} · {{ $b->time }} · {{ $b->destination }}</div>
                    </div>
                    <div style="text-align:right">
                        <small class="ad-muted">Mã đơn</small>
                        <div style="font-size:18px;font-weight:700;color:var(--ad-ink)">{{ $b->bookingCode }}
                            <button type="button" class="ad-btn ad-btn--ghost ad-btn--sm ad-btn--icon no-print" data-copy="{{ $b->bookingCode }}" title="Sao chép mã"><i class="fa fa-copy"></i></button></div>
                    </div>
                </div>
                <div class="ad-invoice__parties">
                    <div><h4>Khách hàng</h4>
                        <p><b>{{ $b->fullName }}</b><br>{{ $b->phoneNumber }}<br>{{ $b->email }}<br>{{ $b->address }}</p></div>
                    <div><h4>Đơn vị cung cấp</h4>
                        <p><b>{{ $site['name'] }}</b><br>{{ $site['address'] }}<br>{{ $site['phone'] }}<br>{{ $site['email'] }}</p></div>
                    <div><h4>Thanh toán</h4>
                        <p>{{ $method }}<br>Trạng thái: <b>{{ $pLabel }}</b><br>
                            {{ $b->paymentDate ? 'Ngày thu: ' . date('H:i d/m/Y', strtotime($b->paymentDate)) : 'Chưa thu tiền' }}<br>
                            @if ($b->transactionId)Mã GD: {{ $b->transactionId }}@endif</p></div>
                </div>
                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <thead><tr><th>Hạng mục</th><th class="num">Số lượng</th><th class="num">Đơn giá</th><th class="num">Thành tiền</th></tr></thead>
                        <tbody>
                            <tr><td>Người lớn (từ 12 tuổi)</td><td class="num">{{ $b->numAdults }}</td><td class="num">{{ $money($b->priceAdult) }}</td><td class="num">{{ $money($b->priceAdult * $b->numAdults) }}</td></tr>
                            <tr><td>Trẻ em (dưới 12 tuổi)</td><td class="num">{{ $b->numChildren }}</td><td class="num">{{ $money($b->priceChild) }}</td><td class="num">{{ $money($b->priceChild * $b->numChildren) }}</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="ad-invoice__total">
                    <div><span class="ad-muted">Tạm tính</span><span>{{ $money($subtotal) }}</span></div>
                    @if ($discount > 0)
                        <div><span class="ad-muted">Giảm giá{{ !empty($b->couponCode) ? ' (' . $b->couponCode . ')' : '' }}</span><span>−{{ $money($discount) }}</span></div>
                    @endif
                    <div class="grand"><span>Tổng thanh toán</span><span>{{ $money($b->totalPrice) }}</span></div>
                    @if ($refund)
                        <div style="color:#991b1b"><span>Hoàn tiền ({{ $refund->percent }}%) · {{ $refund->status === 'refunded' ? 'đã hoàn' : 'chờ hoàn' }}</span><span>−{{ $money($refund->amount) }}</span></div>
                    @endif
                </div>
                <div class="ad-card__foot no-print">
                    <span class="ad-note" style="margin-right:auto;align-self:center">Hóa đơn PDF có đủ tiếng Việt, gửi kèm email cho khách.</span>
                    <button type="button" class="ad-btn" onclick="window.print()"><i class="fa fa-print"></i> In</button>
                    <button type="button" class="ad-btn" id="inv-download"><i class="fa fa-file-pdf-o"></i> Tải PDF</button>
                    @if ($canManage && $b->bookingStatus !== 'c')
                        <button type="button" class="ad-btn ad-btn--primary" id="inv-send" data-url="{{ route('admin.send.pdf') }}" data-id="{{ $b->bookingId }}" data-email="{{ $b->email }}"><i class="fa fa-paper-plane"></i> Gửi PDF cho khách</button>
                    @endif
                </div>
            </section>
        </div>

        <aside>
            @if ($refund)
                <section class="ad-card ad-card--hl">
                    <div class="ad-card__head"><h2>Hoàn tiền</h2><span class="ad-badge ad-badge--{{ $refund->status === 'refunded' ? 'mute' : 'violet' }}">{{ $refund->status === 'refunded' ? 'Đã hoàn' : 'Chờ hoàn' }}</span></div>
                    <div class="ad-card__body">
                        <dl class="ad-kv ad-kv--tight">
                            <dt>Đã thanh toán</dt><dd>{{ $money($refund->paidAmount) }}</dd>
                            <dt>Hoàn</dt><dd><b>{{ $money($refund->amount) }}</b> ({{ $refund->percent }}%)</dd>
                            <dt>Hủy trước</dt><dd>{{ $refund->daysBefore }} ngày · {{ $refund->requestedBy === 'customer' ? 'khách tự hủy' : 'admin hủy' }}</dd>
                            @if ($refund->reason)<dt>Lý do</dt><dd>{{ $refund->reason }}</dd>@endif
                            @if ($refund->status === 'refunded')
                                <dt>Đã hoàn lúc</dt><dd>{{ $refund->processedAt ? date('H:i d/m/Y', strtotime($refund->processedAt)) : '—' }}</dd>
                                <dt>Cách hoàn</dt><dd>{{ $refund->method ?: '—' }}{{ $refund->reference ? ' · ' . $refund->reference : '' }}</dd>
                            @endif
                        </dl>
                        @if ($refund->status === 'pending' && $canManage)
                            <a class="ad-btn ad-btn--primary" style="width:100%;margin-top:14px" href="{{ route('admin.refunds') }}#refund-{{ $refund->refundId }}">Xử lý hoàn tiền</a>
                        @endif
                    </div>
                </section>
            @endif

            @php $onlinePay = \App\Services\Payments\OnlinePaymentService::isOnline($b->paymentMethod ?? null); @endphp
            @if ($onlinePay || count($payLogs))
                @php $gw = $onlinePay ? app(\App\Services\Payments\OnlinePaymentService::class)->gateway($b->paymentMethod) : null; @endphp
                <section class="ad-card">
                    <div class="ad-card__head"><h2>{{ \App\Services\Payments\OnlinePaymentService::label($b->paymentMethod) }}</h2>
                        @if ($canManage && $gw && $gw->code() !== 'qr' && $gw->enabled() && !empty($b->providerTxnRef) && $b->paymentStatus === 'n')
                            <form method="POST" action="{{ route('admin.payments.reconcile', $b->bookingId) }}">@csrf<button class="ad-btn ad-btn--sm" title="Hỏi cổng thanh toán trạng thái giao dịch"><i class="fa fa-refresh"></i> Đối soát</button></form>
                        @endif
                    </div>
                    <div class="ad-card__body">
                        <dl class="ad-kv ad-kv--tight">
                            <dt>Mã giao dịch gửi cổng</dt><dd><code class="ad-code">{{ $b->providerTxnRef ?? '—' }}</code></dd>
                            <dt>Mã GD của cổng</dt><dd>{{ $b->providerTxnNo ?? '—' }}</dd>
                            <dt>Nguồn tiền</dt><dd>{{ $b->bankCode ?? '—' }}</dd>
                            <dt>Kết quả gần nhất</dt><dd>{{ $b->responseCode ? ($b->responseCode === '00' ? 'Đã thanh toán' : ($gw ? $gw->describe($b->responseCode) : $b->responseCode)) : '—' }}</dd>
                            <dt>Số lần tạo giao dịch</dt><dd>{{ (int) ($b->attempts ?? 0) }}</dd>
                        </dl>
                        @if ($canManage && ($b->paymentMethod ?? '') === 'qr' && $b->paymentStatus === 'n' && in_array($b->bookingStatus, ['n', 'y'], true))
                            <form method="POST" action="{{ route('admin.payments.confirm', $b->bookingId) }}" style="display:flex;gap:6px;margin-top:12px">@csrf
                                <input type="text" name="reference" required maxlength="40" placeholder="Mã GD trên sao kê" style="flex:1;height:34px;border:1px solid var(--ad-line);border-radius:8px;padding:0 8px">
                                <button class="ad-btn ad-btn--primary ad-btn--sm" data-confirm="Xác nhận đã nhận tiền chuyển khoản cho đơn {{ $b->bookingCode }}?">Đã nhận tiền</button>
                            </form>
                        @endif
                        @if (count($payLogs))
                            <ul class="ad-timeline" style="margin-top:14px">
                                @foreach ($payLogs as $l)
                                    <li style="--c:{{ in_array($l->result, ['paid', 'url'], true) ? 'var(--ad-ok)' : ($l->validSignature === 0 || in_array($l->result, ['bad_signature', 'bad_amount', 'error'], true) ? 'var(--ad-bad)' : '#94a3b8') }}">
                                        <div class="ad-timeline__t">{{ ['create' => 'Tạo giao dịch', 'return' => 'Khách quay về', 'notify' => 'Cổng báo về', 'query' => 'Đối soát', 'manual' => 'Admin xác nhận', 'ipn' => 'IPN', 'querydr' => 'Đối soát'][$l->kind] ?? $l->kind }} · {{ $l->result }}</div>
                                        <div class="ad-timeline__m">{{ date('H:i:s d/m', strtotime($l->createdAt)) }} · {{ $l->provider }} · {{ $l->txnRef }}{{ $l->validSignature === 0 ? ' · sai chữ ký' : '' }}</div>
                                    </li>
                                @endforeach
                            </ul>
                            <a class="ad-btn ad-btn--sm" href="{{ route('admin.payments', ['q' => $b->bookingCode]) }}">Xem payload</a>
                        @endif
                    </div>
                </section>
            @endif

            <section class="ad-card">
                <div class="ad-card__head"><h2>Chuyến đi</h2></div>
                <div class="ad-card__body">
                    <dl class="ad-kv ad-kv--tight">
                        <dt>Ngày đi</dt><dd>{{ date('d/m/Y', strtotime($b->tripStart)) }}{{ $b->started && !$b->ended ? ' · đang diễn ra' : '' }}</dd>
                        <dt>Ngày về</dt><dd>{{ date('d/m/Y', strtotime($b->tripEnd)) }}</dd>
                        <dt>Số khách</dt><dd>{{ $b->numAdults }} người lớn{{ $b->numChildren ? ', ' . $b->numChildren . ' trẻ em' : '' }}</dd>
                        @if (!empty($tourVersion))
                            <dt>Nội dung đã bán</dt><dd>Phiên bản V{{ $tourVersion['version'] }}@if ($tourVersion['outdated']) <span class="ad-badge ad-badge--warn">Tour đã đổi sau khi đặt</span><span class="ad-sub">Khác: {{ implode(', ', array_slice($tourVersion['changes'], 0, 5)) }}. Khách vẫn được hưởng bản V{{ $tourVersion['version'] }}.</span>@endif</dd>
                        @endif
                        @if ($canPreview = $canCancelPreview)
                            <dt>Nếu hủy</dt><dd>còn {{ $canPreview['days'] }} ngày · {{ $b->paymentStatus === 'y' ? 'hoàn ' . $canPreview['percent'] . '%' : 'không phát sinh hoàn tiền' }}</dd>
                        @endif
                    </dl>
                    <div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap">
                        <a class="ad-btn ad-btn--sm" href="{{ route('tour-detail', ['id' => $b->tourId]) }}" target="_blank" rel="noopener"><i class="fa fa-external-link"></i> Trang tour</a>
                        @if ($canManage && !empty($b->availabilityId))
                            <a class="ad-btn ad-btn--sm" href="{{ route('admin.departures', ['tour' => $b->tourId]) }}"><i class="fa fa-calendar"></i> Lịch khởi hành</a>
                        @endif
                    </div>
                </div>
            </section>

            <section class="ad-card">
                <div class="ad-card__head"><h2>Lịch sử thao tác</h2></div>
                <div class="ad-card__body">
                    @if (count($history))
                        <ul class="ad-timeline">
                            @foreach ($history as $h)
                                <li style="--c:{{ str_contains($h->action, 'cancel') ? '#ef4444' : (str_contains($h->action, 'refund') ? '#7c3aed' : '#168F99') }}">
                                    <div class="ad-timeline__t">{{ AL::label($h->action) }}</div>
                                    <div class="ad-timeline__m">{{ $h->actorName ?? 'Hệ thống' }} · {{ date('H:i d/m/Y', strtotime($h->createdAt)) }}</div>
                                    @if ($h->summary)<div class="ad-note">{{ $h->summary }}</div>@endif
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="ad-note" style="margin:0">Chưa có thao tác quản trị nào trên đơn này (đơn tạo trước khi bật nhật ký, hoặc chưa được xử lý).</p>
                    @endif
                </div>
            </section>
        </aside>
    </div>

    {{-- Dữ liệu cho hóa đơn PDF (pdfmake dựng ngay trên trình duyệt, font Roboto có đủ dấu tiếng Việt) --}}
    @php
        $inv = [
        'invoiceNo' => $invoiceNo, 'code' => $b->bookingCode, 'date' => now()->format('d/m/Y'),
        'title' => $b->title, 'trip' => date('d/m/Y', strtotime($b->tripStart)) . ' – ' . date('d/m/Y', strtotime($b->tripEnd)), 'time' => $b->time, 'destination' => $b->destination,
        'customer' => [$b->fullName, $b->phoneNumber, $b->email, $b->address],
        'site' => array_values($site),
        'lines' => [
            ['Người lớn', (int) $b->numAdults, $money($b->priceAdult), $money($b->priceAdult * $b->numAdults)],
            ['Trẻ em', (int) $b->numChildren, $money($b->priceChild), $money($b->priceChild * $b->numChildren)],
        ],
        'subtotal' => $money($subtotal), 'discount' => $discount > 0 ? '−' . $money($discount) . (!empty($b->couponCode) ? ' (' . $b->couponCode . ')' : '') : null,
        'total' => $money($b->totalPrice), 'method' => $method, 'pay' => $pLabel,
        'paidAt' => $b->paymentDate ? date('H:i d/m/Y', strtotime($b->paymentDate)) : null,
        'refund' => $refund ? 'Hoàn ' . $refund->percent . '%: −' . $money($refund->amount) . ($refund->status === 'refunded' ? ' (đã hoàn)' : ' (chờ hoàn)') : null,
    ];
    @endphp
    <script type="application/json" id="inv-data">{!! json_encode($inv, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>

    @if (isset($adjustEl))
        <section class="ad-card" style="margin-top:20px">
            <div class="ad-card__head"><h2>Điều chỉnh số khách</h2></div>
            <div class="ad-card__body">
                @if ($adjustEl['can'])
                    <form method="POST" action="{{ route('admin.booking.adjust', $b->bookingId) }}" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">@csrf
                        <label>Người lớn<br><input type="number" name="numAdults" min="1" max="50" value="{{ $b->numAdults }}" required style="width:90px"></label>
                        <label>Trẻ em<br><input type="number" name="numChildren" min="0" max="50" value="{{ $b->numChildren }}" required style="width:90px"></label>
                        <label>Ghi chú<br><input name="note" maxlength="255" placeholder="Lý do" style="width:220px"></label>
                        <button class="ad-btn ad-btn--primary" type="submit" data-confirm="Đổi số khách của đơn này? Giá tính lại theo giá khách đã đồng ý lúc đặt, chỗ được cập nhật ngay.">Điều chỉnh</button>
                    </form>
                @else
                    <p>{{ $adjustEl['why'] }}</p>
                @endif
                @if ($adjustments->count())
                    <ul style="margin:12px 0 0;padding-left:18px">
                        @foreach ($adjustments as $j)
                            <li>{{ date('d/m H:i', strtotime($j->createdAt)) }} · {{ $j->oldAdults }}+{{ $j->oldChildren }} → {{ $j->newAdults }}+{{ $j->newChildren }} · {{ number_format($j->oldTotal, 0, ',', '.') }} → {{ number_format($j->newTotal, 0, ',', '.') }} đ
                                @if ($j->moneyStatus === 'pending_collect') · <b>chờ thu thêm {{ number_format($j->settleAmount, 0, ',', '.') }} đ</b>
                                @elseif ($j->moneyStatus === 'pending_refund') · <b>chờ hoàn {{ number_format($j->settleAmount, 0, ',', '.') }} đ</b>
                                @elseif ($j->moneyStatus === 'collected') · đã thu thêm
                                @elseif ($j->moneyStatus === 'refunded') · đã hoàn phần dư @endif
                                ({{ $j->by === 'admin' ? 'admin' : 'khách' }})</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>
    @endif
@endsection

@push('scripts')
    <script src="{{ asset('admin/vendors/pdfmake/build/pdfmake.min.js') }}"></script>
    <script src="{{ asset('admin/vendors/pdfmake/build/vfs_fonts.js') }}"></script>
    <script src="{{ asset('admin/assets/js/admin-invoice.js') }}?v={{ @filemtime(public_path('admin/assets/js/admin-invoice.js')) }}"></script>
    <script src="{{ asset('admin/assets/js/admin-booking.js') }}?v={{ @filemtime(public_path('admin/assets/js/admin-booking.js')) }}"></script>
@endpush
