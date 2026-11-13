@include('clients.blocks.header')
@php
    // Biến từ TourBookedController::index: $tour_booked, $canCancel, $bookingId, $cover
    $b = $tour_booked;
    $status = $b->bookingStatus;
    $statusMap = [
        'n' => ['Chờ xác nhận', 'is-wait'],
        'y' => ['Đã xác nhận', 'is-go'],
        'f' => ['Hoàn thành', 'is-done'],
        'c' => ['Đã hủy', 'is-cancel'],
    ];
    [$statusLabel, $statusClass] = $statusMap[$status] ?? ['Không xác định', ''];
    // B5: khách không có mặt ngày khởi hành (admin đánh dấu) — không còn là "Hoàn thành"
    $noShow = !empty($b->noShowAt);
    if ($noShow) {
        [$statusLabel, $statusClass] = ['Không tham gia', 'is-cancel'];
    }

    $start = \Carbon\Carbon::parse($b->startDate);
    $end = \Carbon\Carbon::parse($b->endDate);
    $guests = (int) $b->numAdults + (int) $b->numChildren;
    $holdAt = $status === 'n' && !empty($b->holdExpiresAt) ? \Carbon\Carbon::parse($b->holdExpiresAt) : null;
    $holdPassed = $holdAt && $holdAt->isPast();
    $cancelDays = (int) config('booking.paid_cancel_min_days', 7);
    $payLabel = ['y' => 'Đã thanh toán', 'refund_pending' => 'Đã hủy – đang chờ hoàn tiền', 'refunded' => 'Đã hoàn tiền'][$b->paymentStatus ?? 'n'] ?? 'Chưa thanh toán';
    if (($b->paymentStatus ?? '') === 'refunded' && !empty($refund) && (int) $refund->amount === 0) {
        $payLabel = 'Đã hủy – không phát sinh hoàn tiền (0%)';
    }
    $sitePhone = config('site.phone');
    $siteAddress = config('site.address');
    $siteEmail = config('site.email');
    $coverUrl = \App\Support\TourImage::url($cover ?? null, 600);
    $code = $b->bookingCode ?? '#' . $b->bookingId;
    $money = fn($n) => number_format((int) $n, 0, ',', '.') . 'đ';

    // Tiến trình: 1 = đã đặt chỗ, 2 = đã xác nhận, 3 = hoàn thành
    $stage = match ($status) {
        'n' => 1,
        'y' => 2,
        'f' => 3,
        default => 0,
    };
@endphp

<main class="bk bk--view wcx" id="bkPage">
    <div class="container">

        <nav class="bk-crumb" aria-label="breadcrumb">
            <a href="{{ route('home') }}">Trang chủ</a><i class="fas fa-chevron-right" aria-hidden="true"></i>
            <a href="{{ route('my-tours') }}">Tour đã đặt</a><i class="fas fa-chevron-right" aria-hidden="true"></i>
            <span aria-current="page">Đơn {{ $code }}</span>
        </nav>

        <header class="bk-head bk-head--row">
            <div>
                <h1 class="wcx-title">Chi tiết đơn đặt tour</h1>
                <p class="bk-code">
                    Mã đơn <b id="bkCode">{{ $code }}</b>
                    <button type="button" class="bk-link" data-copy="#bkCode" aria-label="Sao chép mã đơn">
                        <i class="fal fa-copy" aria-hidden="true"></i> Sao chép
                    </button>
                </p>
            </div>
            <span class="tv-status-pill {{ $statusClass }}">{{ $statusLabel }}</span>
        </header>

        {{-- Tiến trình đơn (đơn đã hủy thì hiện thông báo thay vì tiến trình) --}}
        @if ($noShow)
            <div class="bk-alert bk-alert--bad" role="status">
                <b>Bạn không có mặt vào ngày khởi hành {{ $start->format('d/m/Y') }}.</b>
                Theo chính sách hủy, đơn vắng mặt không được hoàn tiền. Nếu có nhầm lẫn, vui lòng
                <a href="{{ route('contact') }}">liên hệ Travela</a> trong 72 giờ để được kiểm tra lại.
            </div>
        @elseif ($status === 'c')
            <div class="bk-alert bk-alert--bad" role="status">
                <b>Đơn này đã được hủy{{ ['expired' => ' vì quá hạn giữ chỗ (chưa đến văn phòng xác nhận)', 'admin' => ' bởi Travela', 'shortfall' => ' vì tour không đủ khách tối thiểu — Travela hủy khởi hành', 'incident' => ' vì sự cố khiến Travela không thể tổ chức khởi hành', 'customer' => ' theo yêu cầu của bạn', 'no_show' => ' vì bạn không có mặt ngày khởi hành'][$tour_booked->cancelReason ?? ''] ?? '' }}.</b> Chỗ đã được trả lại cho tour. Bạn có thể
                <a href="{{ route('tours') }}">xem các tour khác</a> bất cứ lúc nào.
            </div>
        @else
            <ol class="bk-steps bk-steps--status" aria-label="Tiến trình đơn">
                <li class="{{ $stage >= 1 ? 'is-done' : '' }} {{ $stage === 1 ? 'is-now' : '' }}"
                    @if ($stage === 1) aria-current="step" @endif><span>1</span> Đã đặt chỗ</li>
                <li class="{{ $stage >= 2 ? 'is-done' : '' }} {{ $stage === 2 ? 'is-now' : '' }}"
                    @if ($stage === 2) aria-current="step" @endif><span>2</span> Văn phòng xác nhận</li>
                <li class="{{ $stage >= 3 ? 'is-done' : '' }} {{ $stage === 3 ? 'is-now' : '' }}"
                    @if ($stage === 3) aria-current="step" @endif><span>3</span> Hoàn thành</li>
            </ol>
        @endif

        <div class="bk-layout">

            {{-- ================= CỘT TRÁI ================= --}}
            <div class="bk-main">

                {{-- Việc cần làm tiếp theo: thông tin hữu ích nhất nên đặt trên cùng --}}
                @if ($status === 'n')
                    <section class="bk-next" aria-labelledby="bkNextH">
                        <h2 id="bkNextH"><i class="fal fa-flag" aria-hidden="true"></i> Bước tiếp theo</h2>
                        @php
                            $paySvc = app(\App\Services\Payments\OnlinePaymentService::class);
                            $payGw = ($b->paymentStatus ?? 'n') === 'n' && !$holdPassed ? $paySvc->enabledGateways() : [];
                            $curGw = $paySvc->gateway((string) ($b->paymentMethod ?? ''));
                            $isOnline = (bool) $curGw;
                        @endphp
                        @if ($payGw)
                            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:0 0 12px">
                                @if ($curGw && isset($payGw[$curGw->code()]))
                                    <a class="bk-btn bk-btn--inline" href="{{ route('payment.pay', ['method' => $curGw->code(), 'bookingId' => $b->bookingId]) }}"><i class="fal {{ $curGw->code() === 'qr' ? 'fa-qrcode' : 'fa-credit-card' }}" aria-hidden="true"></i> {{ $curGw->code() === 'qr' ? 'Xem lại mã QR' : 'Thanh toán ' . $curGw->label() }}</a>
                                @endif
                                @foreach ($payGw as $code => $gw)
                                    @continue($curGw && $curGw->code() === $code)
                                    <a class="bk-btn bk-btn--ghost bk-btn--inline" href="{{ route('payment.pay', ['method' => $code, 'bookingId' => $b->bookingId]) }}">{{ $code === 'qr' ? 'Chuyển khoản QR' : $gw->label() }}</a>
                                @endforeach
                                @if ($curGw && $curGw->code() !== 'qr' && !empty($b->providerTxnRef))
                                    <form method="POST" action="{{ route('payment.check', ['bookingId' => $b->bookingId]) }}" style="margin:0">@csrf
                                        <button class="bk-btn bk-btn--ghost bk-btn--inline" type="submit">Tôi đã thanh toán – kiểm tra lại</button></form>
                                @endif
                            </div>
                            @if ($curGw && !empty($b->responseCode) && $b->responseCode !== '00')
                                <p class="bk-hint" role="alert">Lần thanh toán trước chưa thành công: {{ $curGw->describe($b->responseCode) }}. Bạn có thể thử lại hoặc chọn cách khác.</p>
                            @endif
                        @endif
                        @if ($isOnline && $holdAt && !$holdPassed)
                            <p>Hoàn tất thanh toán trước <b>{{ $holdAt->format('H:i d/m/Y') }}</b>.
                                Còn lại: <b class="bk-countdown" data-deadline="{{ $holdAt->toIso8601String() }}">…</b></p>
                            <p class="bk-hint">Quá hạn mà chưa nhận được tiền, đơn tự hủy và chỗ được trả lại.</p>
                        @elseif ($holdAt && !$holdPassed)
                            <p>Đến văn phòng để thanh toán và xác nhận đơn trước
                                <b>{{ $holdAt->format('H:i d/m/Y') }}</b>.
                                Còn lại: <b class="bk-countdown" data-deadline="{{ $holdAt->toIso8601String() }}">…</b>
                            </p>
                            <p class="bk-hint">Quá hạn mà đơn chưa được xác nhận, đơn sẽ tự hủy và chỗ được trả lại.</p>
                        @elseif ($holdPassed)
                            <p>Thời gian giữ chỗ đã hết. Đơn sẽ được hệ thống tự hủy trong ít phút nữa. Nếu bạn vẫn
                                muốn đi, hãy <a href="{{ route('contact') }}">liên hệ ngay</a> hoặc đặt lại tour.</p>
                        @else
                            <p>Vui lòng đến văn phòng để thanh toán và xác nhận đơn.</p>
                        @endif
                        @if ($siteAddress || $sitePhone || $siteEmail)
                            <ul class="bk-contact">
                                @if ($siteAddress)
                                    <li><i class="fal fa-map-marker-alt" aria-hidden="true"></i>{{ $siteAddress }}
                                    </li>
                                @endif
                                @if ($sitePhone)
                                    <li><i class="fal fa-phone" aria-hidden="true"></i><a
                                            href="tel:{{ preg_replace('/\s+/', '', $sitePhone) }}">{{ $sitePhone }}</a>
                                    </li>
                                @endif
                                @if ($siteEmail)
                                    <li><i class="fal fa-envelope" aria-hidden="true"></i><a
                                            href="mailto:{{ $siteEmail }}">{{ $siteEmail }}</a></li>
                                @endif
                            </ul>
                        @endif
                    </section>
                @elseif ($status === 'y')
                    <section class="bk-next" aria-labelledby="bkNextH">
                        <h2 id="bkNextH"><i class="fal fa-flag" aria-hidden="true"></i> Bước tiếp theo</h2>
                        <p>Đơn đã được xác nhận. Hãy chuẩn bị giấy tờ tùy thân và có mặt đúng ngày
                            <b>{{ $start->format('d/m/Y') }}</b>.
                        </p>
                    </section>
                @elseif ($status === 'f' && !$noShow)
                    <section class="bk-next bk-next--done" aria-labelledby="bkNextH">
                        <h2 id="bkNextH"><i class="fal fa-star" aria-hidden="true"></i> Chuyến đi thế nào?</h2>
                        <p>Cảm ơn bạn đã đồng hành. Một đánh giá ngắn giúp những du khách sau chọn tour dễ hơn.</p>
                        <a href="{{ route('tour-detail', ['id' => $b->tourId]) }}#danh-gia"
                            class="bk-btn bk-btn--cta bk-btn--inline">Viết đánh giá</a>
                    </section>
                @endif

                @if (!empty($adjust['can']))
                    <section class="bk-card" aria-labelledby="bkAdjH">
                        <h2 id="bkAdjH">Thay đổi số khách</h2>
                        @if (session('adjustQuote'))
                            @php $q = session('adjustQuote'); $mm = fn ($v) => number_format((float) $v, 0, ',', '.') . ' đ'; @endphp
                            <div style="padding:10px 12px;background:#F4F8F6;border-radius:8px;margin-bottom:10px">
                                <b>Xem trước:</b> {{ $q['people']['old'][0] }}+{{ $q['people']['old'][1] }} → {{ $q['people']['new'][0] }}+{{ $q['people']['new'][1] }} người · tổng tiền {{ $mm($q['oldTotal']) }} → <b>{{ $mm($q['newTotal']) }}</b>
                                @if ($q['moneyStatus'] === 'pending_collect')<br>Bạn cần thanh toán thêm <b>{{ $mm($q['settleAmount']) }}</b>.
                                @elseif ($q['moneyStatus'] === 'pending_refund')<br>Được hoàn <b>{{ $mm($q['settleAmount']) }}</b> ({{ $q['refundPercent'] }}% phần dư theo chính sách hủy).
                                @elseif ($q['paid'])<br>Không phát sinh thu / hoàn tiền.@endif
                            </div>
                        @endif
                        <form method="POST" action="{{ route('booking.adjust', $b->bookingId) }}" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">
                            @csrf
                            <label>Người lớn<br><input type="number" name="numAdults" min="1" max="50" value="{{ old('numAdults', $b->numAdults) }}" required style="width:90px"></label>
                            <label>Trẻ em<br><input type="number" name="numChildren" min="0" max="50" value="{{ old('numChildren', $b->numChildren) }}" required style="width:90px"></label>
                            @if (session('adjustQuote'))<input type="hidden" name="confirm" value="1"><button class="bk-btn" type="submit">Xác nhận thay đổi</button>
                            @else<button class="bk-btn" type="submit">Xem giá mới</button>@endif
                        </form>
                        <p style="margin:8px 0 0;font-size:13px;color:#6b8592">Giá tính theo giá bạn đã đồng ý lúc đặt. Cho phép đến {{ \App\Services\BookingAdjustmentService::minDays() }} ngày trước khởi hành.</p>
                    </section>
                @endif
                @if (!empty($complaint['can']))
                    <section class="bk-card"><h2>Chuyến đi chưa như mong đợi?</h2>
                        <p>Nếu dịch vụ không đúng mô tả, bạn có thể gửi khiếu nại trong {{ \App\Services\ComplaintService::windowDays() }} ngày sau khi kết thúc chuyến đi.</p>
                        <a class="bk-btn" href="{{ route('complaint.index', ['bookingId' => $b->bookingId]) }}">Gửi khiếu nại</a></section>
                @endif

                @if (!empty($tourVersion))
                    @php $tv = $tourVersion; $snap = $tv['snapshot']; @endphp
                    <section class="bk-card" aria-labelledby="bkVerH">
                        <h2 id="bkVerH">Nội dung tour bạn đã đặt <small style="font-weight:400">(phiên bản V{{ $tv['version'] }})</small></h2>
                        @if ($tv['outdated'])
                            <p style="padding:10px 12px;background:#FFF6D6;border-radius:8px;margin:0 0 10px">Tour này đã được cập nhật sau khi bạn đặt (thay đổi: {{ implode(', ', array_slice($tv['changes'], 0, 5)) }}). <b>Đơn của bạn vẫn áp dụng đúng nội dung dưới đây</b>, là bản bạn đã đồng ý khi đặt. Cần hỗ trợ, hãy liên hệ Travela.</p>
                        @else
                            <p style="margin:0 0 10px">Đây là nội dung và giá tại thời điểm bạn đặt; hiện vẫn trùng với trang tour.</p>
                        @endif
                        <details>
                            <summary style="cursor:pointer;font-weight:600">Xem giá, dịch vụ và lịch trình đã chốt</summary>
                            <dl class="bk-dl" style="margin-top:10px">
                                @if (isset($snap['priceAdult']))<div><dt>Giá người lớn</dt><dd>{{ number_format((float) $snap['priceAdult'], 0, ',', '.') }} đ</dd></div>@endif
                                @if (isset($snap['priceChild']))<div><dt>Giá trẻ em</dt><dd>{{ number_format((float) $snap['priceChild'], 0, ',', '.') }} đ</dd></div>@endif
                                @if (!empty($snap['includes']))<div><dt>Giá đã bao gồm</dt><dd>{!! nl2br(e(strip_tags($snap['includes']))) !!}</dd></div>@endif
                                @if (!empty($snap['excludes']))<div><dt>Giá không bao gồm</dt><dd>{!! nl2br(e(strip_tags($snap['excludes']))) !!}</dd></div>@endif
                            </dl>
                            @foreach ((array) ($snap['timeline'] ?? []) as $i => $day)
                                <div style="margin-top:10px"><b>Ngày {{ $i + 1 }}: {{ $day['title'] }}</b>
                                    <div>{!! strip_tags((string) $day['description'], '<p><br><ul><ol><li><b><strong><i><em>') !!}</div></div>
                            @endforeach
                        </details>
                    </section>
                @endif

                <section class="bk-card" aria-labelledby="bkInfoH">
                    <h2 id="bkInfoH">Thông tin liên hệ</h2>
                    <dl class="bk-dl">
                        <div>
                            <dt>Họ và tên</dt>
                            <dd>{{ $b->fullName }}</dd>
                        </div>
                        <div>
                            <dt>Số điện thoại</dt>
                            <dd>{{ $b->phoneNumber }}</dd>
                        </div>
                        <div>
                            <dt>Email</dt>
                            <dd>{{ $b->email }}</dd>
                        </div>
                        <div>
                            <dt>Địa chỉ</dt>
                            <dd>{{ $b->address }}</dd>
                        </div>
                    </dl>
                </section>

                <section class="bk-card" aria-labelledby="bkPayH">
                    <h2 id="bkPayH">Thanh toán</h2>
                    <dl class="bk-dl">
                        <div>
                            <dt>Phương thức</dt>
                            <dd>{{ \App\Services\Payments\OnlinePaymentService::label($b->paymentMethod ?? 'office-payment') }}{{ !empty($b->bankCode) && ($b->paymentStatus ?? 'n') !== 'n' ? ' · ' . $b->bankCode : '' }}</dd>
                        </div>
                        <div>
                            <dt>Ngày đặt</dt>
                            <dd>{{ !empty($b->bookingDate) ? \Carbon\Carbon::parse($b->bookingDate)->format('H:i d/m/Y') : '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt>Trạng thái thanh toán</dt>
                            <dd>{{ $payLabel }}</dd>
                        </div>
                        @if (!empty($refund))
                        <div>
                            <dt>Hoàn tiền</dt>
                            <dd>@if ((int) $refund->amount === 0)Không phát sinh hoàn tiền theo chính sách hủy ({{ $refund->percent }}%)@else{{ $money($refund->amount) }} ({{ $refund->percent }}%) · {{ $refund->status === 'refunded' ? 'đã hoàn' . ($refund->processedAt ? ' ngày ' . \Carbon\Carbon::parse($refund->processedAt)->format('d/m/Y') : '') : 'Travela đang xử lý' }}@endif</dd>
                        @endif
                        </div>
                    </dl>
                </section>

                {{-- Hủy đơn --}}
                @if ($canCancel)
                    <section class="bk-card bk-card--danger" aria-labelledby="bkCancelH">
                        <h2 id="bkCancelH">Cần hủy đơn?</h2>
                        <p>{{ $policy['message'] }} Chỗ sẽ được trả lại và mã giảm giá (nếu có) được hoàn lại.</p>
                        @if ($policy['paid'])
                            <p class="bk-hint">Chính sách hoàn tiền: còn từ 14 ngày hoàn 100%, 7–13 ngày hoàn 50%, dưới {{ $cancelDays }} ngày liên hệ Travela.</p>
                        @endif
                        <button type="button" class="bk-btn bk-btn--danger bk-btn--inline" data-open-cancel>Hủy đơn
                            này</button>
                    </section>
                @elseif (in_array($status, ['n', 'y'], true))
                    <section class="bk-card" aria-labelledby="bkCancelH">
                        <h2 id="bkCancelH">Cần hủy đơn?</h2>
                        <p>{{ $policy['message'] ?? '' }} Vui lòng <a href="{{ route('contact') }}">liên hệ</a> để được hỗ trợ.</p>
                    </section>
                @endif
            </div>

            {{-- ================= CỘT PHẢI ================= --}}
            <aside class="bk-side" aria-label="Tóm tắt tour">
                <div class="bk-sum">
                    <div class="bk-sum__tour">
                        <img src="{{ $coverUrl }}" alt="" width="96" height="72" decoding="async">
                        <div>
                            <small>Mã tour #{{ $b->tourId }}</small>
                            <h2><a href="{{ route('tour-detail', ['id' => $b->tourId]) }}">{{ $b->title }}</a>
                            </h2>
                        </div>
                    </div>

                    <ul class="bk-sum__meta">
                        <li><i class="fal fa-map-marker-alt" aria-hidden="true"></i>{{ $b->destination }}</li>
                        <li><i class="fal fa-calendar-alt" aria-hidden="true"></i>{{ $start->format('d/m/Y') }} →
                            {{ $end->format('d/m/Y') }}</li>
                        <li><i class="fal fa-clock" aria-hidden="true"></i>{{ $b->time }}</li>
                    </ul>

                    {{-- Chỉ hiện số khách và tổng tiền đã chốt lúc đặt (giá tour hiện tại có thể đã đổi nên không tính lại từng dòng) --}}
                    <dl class="bk-lines">
                        <div>
                            <dt>Người lớn</dt>
                            <dd>{{ (int) $b->numAdults }} khách</dd>
                        </div>
                        @if ((int) $b->numChildren > 0)
                            <div>
                                <dt>Trẻ em</dt>
                                <dd>{{ (int) $b->numChildren }} bé</dd>
                            </div>
                        @endif
                        <div class="bk-total">
                            <dt>Tổng cộng</dt>
                            <dd>{{ $money($b->totalPrice) }}</dd>
                        </div>
                    </dl>
                    <p class="bk-hint">Tổng {{ $guests }} khách · đã gồm ưu đãi nếu có.</p>

                    <a class="bk-btn bk-btn--ghost bk-btn--block" href="{{ route('my-tours') }}">
                        <i class="fal fa-arrow-left" aria-hidden="true"></i> Tất cả đơn của tôi</a>
                    <button type="button" class="bk-link bk-link--center" onclick="window.print()">
                        <i class="fal fa-print" aria-hidden="true"></i> In / lưu PDF đơn này</button>
                    <a class="bk-sum__help" href="{{ route('contact') }}"><i class="fal fa-comment-dots"
                            aria-hidden="true"></i> Cần hỗ trợ về đơn này?</a>
                </div>
                @if (in_array($status, ['n', 'y'], true))
                    {{-- Tiện ích sau khi đặt: thêm vào lịch (.ics tạo ngay trên trình duyệt) và gọi văn phòng --}}
                    <section class="bk-tools" aria-label="Tiện ích cho đơn này">
                        <button type="button" class="bk-tool" data-ics data-title="{{ $b->title }}"
                            data-start="{{ $start->format('Y-m-d') }}" data-end="{{ $end->format('Y-m-d') }}"
                            data-place="{{ $b->destination }}" data-code="{{ $code }}"
                            data-url="{{ route('tour-detail', ['id' => $b->tourId]) }}">
                            <i class="fal fa-calendar-plus" aria-hidden="true"></i> Thêm vào lịch
                        </button>
                        @if ($sitePhone)
                            <a class="bk-tool" href="tel:{{ preg_replace('/\s+/', '', $sitePhone) }}"><i
                                    class="fal fa-phone" aria-hidden="true"></i> Gọi văn phòng</a>
                        @endif
                    </section>
                @endif
            </aside>
        </div>
    </div>
</main>

{{-- Hộp xác nhận hủy: thao tác không hoàn tác nên luôn hỏi lại --}}
@if ($canCancel)
    <dialog class="bk-dialog" id="bkCancelDialog" aria-labelledby="bkCancelTitle">
        <form method="POST" action="{{ route('cancel-booking') }}">
            @csrf
            <input type="hidden" name="bookingId" value="{{ $bookingId }}">
            <h2 id="bkCancelTitle">Hủy đơn {{ $code }}?</h2>
            <p>Chỗ của bạn sẽ được trả lại và <b>không thể khôi phục</b>. Muốn đi tiếp, bạn cần đặt lại tour.</p>
            @if ($policy['paid'])
                <p><b>Số tiền được hoàn: {{ $money(floor((float) ($b->amount ?? $b->totalPrice) * $policy['percent'] / 100)) }} ({{ $policy['percent'] }}%).</b></p>
            @endif
            <div class="bk-dialog__actions">
                <button type="button" class="bk-btn bk-btn--ghost" data-close-cancel>Giữ đơn</button>
                <button type="submit" class="bk-btn bk-btn--danger">Xác nhận hủy</button>
            </div>
        </form>
    </dialog>
@endif

@include('clients.blocks.footer')
