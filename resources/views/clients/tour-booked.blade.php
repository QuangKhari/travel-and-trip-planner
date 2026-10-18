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

    $start = \Carbon\Carbon::parse($b->startDate);
    $end = \Carbon\Carbon::parse($b->endDate);
    $guests = (int) $b->numAdults + (int) $b->numChildren;
    $holdAt = $status === 'n' && !empty($b->holdExpiresAt) ? \Carbon\Carbon::parse($b->holdExpiresAt) : null;
    $holdPassed = $holdAt && $holdAt->isPast();
    $cancelDays = \App\Support\CancelPolicy::MIN_DAYS;
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
        @if ($status === 'c')
            <div class="bk-alert bk-alert--bad" role="status">
                <b>Đơn này đã được hủy.</b> Chỗ đã được trả lại cho tour. Bạn có thể
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
                        @if ($holdAt && !$holdPassed)
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
                @elseif ($status === 'f')
                    <section class="bk-next bk-next--done" aria-labelledby="bkNextH">
                        <h2 id="bkNextH"><i class="fal fa-star" aria-hidden="true"></i> Chuyến đi thế nào?</h2>
                        <p>Cảm ơn bạn đã đồng hành. Một đánh giá ngắn giúp những du khách sau chọn tour dễ hơn.</p>
                        <a href="{{ route('tour-detail', ['id' => $b->tourId]) }}#danh-gia"
                            class="bk-btn bk-btn--cta bk-btn--inline">Viết đánh giá</a>
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
                            <dd>Thanh toán tại văn phòng</dd>
                        </div>
                        <div>
                            <dt>Ngày đặt</dt>
                            <dd>{{ !empty($b->bookingDate) ? \Carbon\Carbon::parse($b->bookingDate)->format('H:i d/m/Y') : '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt>Trạng thái thanh toán</dt>
                            <dd>{{ ($b->paymentStatus ?? 'n') === 'y' ? 'Đã thanh toán' : 'Chưa thanh toán' }}</dd>
                        </div>
                    </dl>
                </section>

                {{-- Hủy đơn --}}
                @if ($canCancel)
                    <section class="bk-card bk-card--danger" aria-labelledby="bkCancelH">
                        <h2 id="bkCancelH">Cần hủy đơn?</h2>
                        <p>Bạn có thể tự hủy khi còn hơn {{ $cancelDays }} ngày trước ngày khởi hành. Chỗ sẽ được trả
                            lại và mã giảm giá (nếu có) được hoàn lại.</p>
                        <button type="button" class="bk-btn bk-btn--danger bk-btn--inline" data-open-cancel>Hủy đơn
                            này</button>
                    </section>
                @elseif (in_array($status, ['n', 'y'], true))
                    <section class="bk-card" aria-labelledby="bkCancelH">
                        <h2 id="bkCancelH">Cần hủy đơn?</h2>
                        <p>Đơn chỉ tự hủy được khi còn hơn {{ $cancelDays }} ngày trước ngày khởi hành. Với đơn này,
                            vui lòng <a href="{{ route('contact') }}">liên hệ</a> để được hỗ trợ.</p>
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
            <div class="bk-dialog__actions">
                <button type="button" class="bk-btn bk-btn--ghost" data-close-cancel>Giữ đơn</button>
                <button type="submit" class="bk-btn bk-btn--danger">Xác nhận hủy</button>
            </div>
        </form>
    </dialog>
@endif

@include('clients.blocks.footer')
