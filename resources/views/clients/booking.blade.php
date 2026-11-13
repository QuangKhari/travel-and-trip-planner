@include('clients.blocks.header')
@php
    // Biến từ BookingController::index: $tour, $prefill, $limit, $adults, $children
    $holdHours = (int) config('travela.hold_hours', 48);
    $cancelDays = \App\Support\CancelPolicy::MIN_DAYS;
    $cover = \App\Support\TourImage::url($tour->images->first() ?? null, 600);
    $start = \Carbon\Carbon::parse($tour->startDate);
    $end = \Carbon\Carbon::parse($tour->endDate);
    $sitePhone = config('site.phone');
    $siteAddress = config('site.address');

    // Dữ liệu người dùng vừa nhập (nếu server trả lỗi) luôn ưu tiên hơn dữ liệu điền sẵn
    $adultsNow = (int) old('numAdults', $adults);
    $childrenNow = (int) old('numChildren', $children);
    $hasPrefill = collect($prefill)->filter()->isNotEmpty() && !old('fullName');
    $subtotal = $adultsNow * (int) $tour->priceAdult + $childrenNow * (int) $tour->priceChild;
    $money = fn($n) => number_format((int) $n, 0, ',', '.') . 'đ';
@endphp

<main class="bk wcx" id="bkPage">
    <div class="container">

        <nav class="bk-crumb" aria-label="breadcrumb">
            <a href="{{ route('home') }}">Trang chủ</a><i class="fas fa-chevron-right" aria-hidden="true"></i>
            <a href="{{ route('tours') }}">Tour</a><i class="fas fa-chevron-right" aria-hidden="true"></i>
            <a
                href="{{ route('tour-detail', ['id' => $tour->tourId]) }}">{{ \Illuminate\Support\Str::limit($tour->title, 40) }}</a>
            <i class="fas fa-chevron-right" aria-hidden="true"></i>
            <span aria-current="page">Đặt tour</span>
        </nav>

        <header class="bk-head">
            <h1 class="wcx-title">Hoàn tất đặt tour</h1>
            <p>Ba bước, khoảng một phút. Bạn <b>chưa bị tính phí</b> cho tới khi xác nhận ở bước cuối.</p>
        </header>

        @php
            $deps = collect($departures ?? [])->filter(fn($d) => $d->bookable)->values();
            $depNow = $departure->availabilityId ?? null;
            $stepNames = [1 => 'Ngày & số khách', 2 => 'Liên hệ & mã giảm giá', 3 => 'Xác nhận & thanh toán'];
            $dateVi = fn($d) => \Carbon\Carbon::parse($d)->locale('vi')->translatedFormat('D, d/m/Y');
        @endphp
        {{-- C-05: wizard 3 bước. Không có JavaScript thì cả 3 bước cùng hiện và biểu mẫu vẫn gửi bình thường. --}}
        <ol class="bk-steps" aria-label="Các bước đặt tour" id="bkSteps">
            @foreach ($stepNames as $n => $label)
                <li data-step-tab="{{ $n }}" class="{{ $n < $step ? 'is-done' : ($n === $step ? 'is-now' : '') }}" @if ($n === $step) aria-current="step" @endif>
                    <button type="button" class="bk-steps__btn" data-bk-goto="{{ $n }}" @disabled($n > $step)><span>{{ $n }}</span> {{ $label }}</button>
                </li>
            @endforeach
        </ol>

        @if (!empty($fromTrip))
            <div class="bk-alert bk-alert--trip" role="status">
                <i class="fal fa-route" aria-hidden="true"></i>
                Đặt từ chuyến đi <b>{{ $fromTrip['title'] }}</b>: ngày khởi hành và số khách đã điền sẵn theo chuyến của bạn, đơn sẽ được gắn với chuyến này.
            </div>
        @endif

        {{-- Lỗi do server trả về (JS bị tắt hoặc dữ liệu bị sửa): gom lại một chỗ, tự cuộn tới --}}
        @if ($errors->any())
            <div class="bk-alert bk-alert--bad" role="alert" tabindex="-1" id="bkServerErrors">
                <b>Cần kiểm tra lại {{ $errors->count() }} mục:</b>
                <ul>
                    @foreach ($errors->all() as $msg)
                        <li>{{ $msg }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form id="bkForm" class="bk-form" action="{{ route('create-booking') }}" method="POST" novalidate data-wizard
            data-step="{{ $step }}"
            data-price-adult="{{ (int) $tour->priceAdult }}" data-price-child="{{ (int) $tour->priceChild }}"
            data-limit="{{ $limit }}" data-quote-url="{{ route('booking.quote') }}">
            @csrf
            <input type="hidden" name="requestToken" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <input type="hidden" name="tourId" value="{{ $tour->tourId }}">
            @if (!empty($fromTrip))
                <input type="hidden" name="tripId" value="{{ $fromTrip['id'] }}">
            @endif
            @if ($deps->isEmpty() && !empty($departure))
                <input type="hidden" name="availabilityId" value="{{ $departure->availabilityId }}">
            @endif
            <input type="hidden" name="numAdults" id="bkAdults" value="{{ $adultsNow }}">
            <input type="hidden" name="numChildren" id="bkChildren" value="{{ $childrenNow }}">

            <div class="bk-layout">

                {{-- ================= CỘT TRÁI: các bước ================= --}}
                <div class="bk-main">

                    {{-- ===== BƯỚC 1: ngày khởi hành + số khách ===== --}}
                    <div class="bk-steppanel" data-panel="1" id="bkPanel1" role="group" aria-labelledby="bkS1">
                        <h2 class="sr-only" id="bkS1">Bước 1: {{ $stepNames[1] }}</h2>

                        <section class="bk-card" aria-labelledby="bkHDate">
                            <h2 id="bkHDate"><span class="bk-no">1</span> Ngày khởi hành</h2>
                            @if ($deps->count() > 1)
                                <div class="bk-dates" role="radiogroup" aria-label="Chọn ngày khởi hành">
                                    @foreach ($deps as $d)
                                        @php $on = (int) $d->availabilityId === (int) $depNow; @endphp
                                        <label class="bk-date {{ $on ? 'is-on' : '' }}">
                                            <input type="radio" name="availabilityId" value="{{ $d->availabilityId }}" @checked($on)
                                                data-start="{{ \Carbon\Carbon::parse($d->departureDate)->format('d/m/Y') }}"
                                                data-end="{{ \Carbon\Carbon::parse($d->endDate)->format('d/m/Y') }}"
                                                data-remaining="{{ (int) $d->remaining }}">
                                            <b>{{ $dateVi($d->departureDate) }}</b>
                                            <small>về {{ \Carbon\Carbon::parse($d->endDate)->format('d/m') }}</small>
                                            <span class="bk-date__seat {{ $d->remaining <= 5 ? 'is-low' : '' }}" data-seat-for="{{ $d->availabilityId }}">còn {{ (int) $d->remaining }} chỗ</span>
                                        </label>
                                    @endforeach
                                </div>
                            @else
                                @if ($deps->count() === 1)
                                    <input type="hidden" name="availabilityId" value="{{ $deps[0]->availabilityId }}">
                                @endif
                                <p class="bk-onedate"><i class="fal fa-calendar-check" aria-hidden="true"></i>
                                    <b>{{ $dateVi($tour->startDate) }}</b> → {{ $end->format('d/m/Y') }} · còn {{ (int) $tour->quantity }} chỗ</p>
                            @endif
                        </section>

                        <section class="bk-card" aria-labelledby="bkH2">
                            <h2 id="bkH2"><span class="bk-no">2</span> Số lượng hành khách</h2>

                            <div class="bk-guests">
                                <div class="bk-guest" role="group" aria-labelledby="bkLblAdults">
                                    <div class="bk-guest__txt">
                                        <b id="bkLblAdults">Người lớn</b>
                                        <small>Từ 12 tuổi · {{ $money($tour->priceAdult) }}/người</small>
                                    </div>
                                    <div class="bk-step">
                                        <button type="button" data-bk-step="-1" data-for="adults"
                                            aria-label="Giảm số người lớn">−</button>
                                        <output id="bkAdultsOut" aria-live="polite">{{ $adultsNow }}</output>
                                        <button type="button" data-bk-step="1" data-for="adults"
                                            aria-label="Tăng số người lớn">+</button>
                                    </div>
                                </div>

                                <div class="bk-guest" role="group" aria-labelledby="bkLblChildren">
                                    <div class="bk-guest__txt">
                                        <b id="bkLblChildren">Trẻ em</b>
                                        <small>Dưới 12 tuổi · {{ $money($tour->priceChild) }}/bé</small>
                                    </div>
                                    <div class="bk-step">
                                        <button type="button" data-bk-step="-1" data-for="children"
                                            aria-label="Giảm số trẻ em">−</button>
                                        <output id="bkChildrenOut" aria-live="polite">{{ $childrenNow }}</output>
                                        <button type="button" data-bk-step="1" data-for="children"
                                            aria-label="Tăng số trẻ em">+</button>
                                    </div>
                                </div>
                            </div>
                            <p class="bk-hint" id="bkGuestNote" aria-live="polite">
                                {{ $adultsNow + $childrenNow }} khách · còn {{ (int) $tour->quantity }} chỗ (tối đa
                                {{ $limit }} khách cho một đơn)
                            </p>
                            <p class="bk-err" id="bkErr-seats" role="alert"></p>
                        </section>

                        <div class="bk-nav">
                            <span></span>
                            <button type="button" class="bk-btn bk-btn--cta bk-btn--inline" data-bk-next="2">Tiếp tục: thông tin liên hệ <i class="fal fa-arrow-right" aria-hidden="true"></i></button>
                        </div>
                    </div>

                    {{-- ===== BƯỚC 2: liên hệ + mã giảm giá ===== --}}
                    <div class="bk-steppanel" data-panel="2" id="bkPanel2" role="group" aria-labelledby="bkS2">
                        <h2 class="sr-only" id="bkS2">Bước 2: {{ $stepNames[2] }}</h2>
                        <section class="bk-card" aria-labelledby="bkH1">
                            <h2 id="bkH1"><span class="bk-no">3</span> Thông tin liên hệ</h2>
                            @if ($hasPrefill)
                                <p class="bk-note"><i class="fal fa-user-check" aria-hidden="true"></i>
                                    Đã điền sẵn từ tài khoản của bạn. Bạn có thể sửa nếu đặt giúp người khác.</p>
                            @endif

                            <div class="bk-grid">
                                <div class="bk-field {{ $errors->has('fullName') ? 'has-error' : '' }}">
                                    <label for="bkName">Họ và tên <em aria-hidden="true">*</em></label>
                                    <input type="text" id="bkName" name="fullName" autocomplete="name" maxlength="255"
                                        value="{{ old('fullName', $prefill['fullName']) }}" placeholder="Nguyễn Văn A"
                                        required aria-describedby="bkErr-fullName"
                                        @if ($errors->has('fullName')) aria-invalid="true" @endif>
                                    <p class="bk-err" id="bkErr-fullName">{{ $errors->first('fullName') }}</p>
                                </div>

                                <div class="bk-field {{ $errors->has('tel') ? 'has-error' : '' }}">
                                    <label for="bkTel">Số điện thoại <em aria-hidden="true">*</em></label>
                                    <input type="tel" id="bkTel" name="tel" inputmode="numeric"
                                        autocomplete="tel" maxlength="14" value="{{ old('tel', $prefill['tel']) }}"
                                        placeholder="0901234567" required aria-describedby="bkHint-tel bkErr-tel"
                                        @if ($errors->has('tel')) aria-invalid="true" @endif>
                                    <p class="bk-hint" id="bkHint-tel">Nhân viên sẽ gọi số này để xác nhận đơn.</p>
                                    <p class="bk-err" id="bkErr-tel">{{ $errors->first('tel') }}</p>
                                </div>

                                <div class="bk-field {{ $errors->has('email') ? 'has-error' : '' }}">
                                    <label for="bkEmail">Email <em aria-hidden="true">*</em></label>
                                    <input type="email" id="bkEmail" name="email" autocomplete="email" maxlength="50"
                                        value="{{ old('email', $prefill['email']) }}" placeholder="ban@example.com"
                                        required aria-describedby="bkHint-email bkErr-email"
                                        @if ($errors->has('email')) aria-invalid="true" @endif>
                                    <p class="bk-hint" id="bkHint-email">Xác nhận đặt tour sẽ được gửi tới email này.</p>
                                    <p class="bk-err" id="bkErr-email">{{ $errors->first('email') }}</p>
                                </div>

                                <div class="bk-field {{ $errors->has('address') ? 'has-error' : '' }}">
                                    <label for="bkAddress">Địa chỉ <em aria-hidden="true">*</em></label>
                                    <input type="text" id="bkAddress" name="address" autocomplete="street-address"
                                        maxlength="255" value="{{ old('address', $prefill['address']) }}"
                                        placeholder="Số nhà, đường, quận/huyện, tỉnh/thành" required
                                        aria-describedby="bkErr-address"
                                        @if ($errors->has('address')) aria-invalid="true" @endif>
                                    <p class="bk-err" id="bkErr-address">{{ $errors->first('address') }}</p>
                                </div>
                            </div>
                        </section>

                        <section class="bk-card" aria-labelledby="bkHCoupon">
                            <h2 id="bkHCoupon"><span class="bk-no">4</span> Mã giảm giá <small class="bk-opt">không bắt buộc</small></h2>
                            <div class="bk-coupon">
                                <label for="bkCoupon" class="sr-only">Mã giảm giá</label>
                                <div class="bk-coupon__row">
                                    <input type="text" id="bkCoupon" name="couponCode" maxlength="50"
                                        autocomplete="off" value="{{ old('couponCode') }}"
                                        placeholder="Nhập mã (nếu có)" aria-describedby="bkCouponMsg">
                                    <button type="button" id="bkCouponBtn" class="bk-btn bk-btn--ghost">Áp dụng</button>
                                </div>
                                <p class="bk-coupon__msg" id="bkCouponMsg" role="status">{{ $errors->first('couponCode') }}</p>
                                <button type="button" class="bk-link" id="bkCouponRemove" hidden>Bỏ mã giảm giá</button>
                            </div>
                        </section>

                        <div class="bk-nav">
                            <button type="button" class="bk-btn bk-btn--ghost bk-btn--inline" data-bk-back="1"><i class="fal fa-arrow-left" aria-hidden="true"></i> Quay lại</button>
                            <button type="button" class="bk-btn bk-btn--cta bk-btn--inline" data-bk-next="3">Tiếp tục: xác nhận <i class="fal fa-arrow-right" aria-hidden="true"></i></button>
                        </div>
                    </div>

                    {{-- ===== BƯỚC 3: xác nhận + thanh toán ===== --}}
                    <div class="bk-steppanel" data-panel="3" id="bkPanel3" role="group" aria-labelledby="bkS3">
                        <h2 class="sr-only" id="bkS3">Bước 3: {{ $stepNames[3] }}</h2>

                        <section class="bk-card bk-review" aria-labelledby="bkHReview" id="bkReview">
                            <h2 id="bkHReview"><span class="bk-no">5</span> Kiểm tra lại đơn</h2>
                            <dl class="bk-review__list">
                                <div><dt>Ngày đi</dt><dd><span data-rv="date">{{ $start->format('d/m/Y') }} → {{ $end->format('d/m/Y') }}</span>
                                    <button type="button" class="bk-link" data-bk-goto="1">Sửa</button></dd></div>
                                <div><dt>Hành khách</dt><dd><span data-rv="guests">{{ $adultsNow }} người lớn{{ $childrenNow ? ', ' . $childrenNow . ' trẻ em' : '' }}</span>
                                    <button type="button" class="bk-link" data-bk-goto="1">Sửa</button></dd></div>
                                <div><dt>Liên hệ</dt><dd><span data-rv="contact">{{ old('fullName', $prefill['fullName']) }}</span>
                                    <button type="button" class="bk-link" data-bk-goto="2">Sửa</button></dd></div>
                                <div><dt>Mã giảm giá</dt><dd><span data-rv="coupon">{{ old('couponCode') ?: 'Không dùng' }}</span>
                                    <button type="button" class="bk-link" data-bk-goto="2">Sửa</button></dd></div>
                            </dl>
                        </section>

                        <section class="bk-card" aria-labelledby="bkH3">
                            <h2 id="bkH3"><span class="bk-no">6</span> Phương thức thanh toán</h2>

                            <label class="bk-pay {{ old('payment', 'office-payment') === 'office-payment' ? 'is-on' : '' }}">
                                <input type="radio" name="payment" value="office-payment" @checked(old('payment', 'office-payment') === 'office-payment')>
                                <span class="bk-pay__ico" aria-hidden="true"><i class="fal fa-building"></i></span>
                                <span class="bk-pay__txt">
                                    <b>Thanh toán tại văn phòng</b>
                                    <small>Giữ chỗ trước, thanh toán khi đến văn phòng trong {{ $holdHours }}
                                        giờ.</small>
                                    @if ($siteAddress || $sitePhone)
                                        <small class="bk-pay__where">
                                            @if ($siteAddress)
                                                <i class="fal fa-map-marker-alt" aria-hidden="true"></i>
                                                {{ $siteAddress }}
                                            @endif
                                            @if ($sitePhone)
                                                <i class="fal fa-phone" aria-hidden="true"></i> {{ $sitePhone }}
                                            @endif
                                        </small>
                                    @endif
                                </span>
                            </label>
                            @php
                                $payGw = app(\App\Services\Payments\OnlinePaymentService::class)->enabledGateways();
                                $payInfo = [
                                    'momo' => ['fa-wallet', 'Thanh toán bằng ví MoMo', 'Quét mã QR bằng app MoMo hoặc dùng thẻ ATM liên kết.'],
                                    'zalopay' => ['fa-mobile', 'Thanh toán bằng ZaloPay', 'Ví ZaloPay, thẻ ATM nội địa hoặc thẻ quốc tế trên cổng ZaloPay.'],
                                    'qr' => ['fa-qrcode', 'Chuyển khoản bằng mã QR', 'Quét mã VietQR bằng app ngân hàng bất kỳ, số tiền và nội dung đã điền sẵn.'],
                                ];
                            @endphp
                            @foreach ($payGw as $code => $gw)
                                <label class="bk-pay {{ old('payment') === $code ? 'is-on' : '' }}" style="margin-top:10px">
                                    <input type="radio" name="payment" value="{{ $code }}" @checked(old('payment') === $code)>
                                    <span class="bk-pay__ico" aria-hidden="true"><i class="fal {{ $payInfo[$code][0] }}"></i></span>
                                    <span class="bk-pay__txt">
                                        <b>{{ $payInfo[$code][1] }}</b>
                                        <small>{{ $payInfo[$code][2] }} Chỗ được giữ {{ $gw->holdMinutes() }} phút để bạn thanh toán; đơn được xác nhận ngay khi nhận được tiền.</small>
                                    </span>
                                </label>
                            @endforeach
                            @error('payment')
                                <p class="bk-err is-on">{{ $message }}</p>
                            @enderror
                        </section>

                        <section class="bk-card bk-card--terms" aria-labelledby="bkH4">
                            <h2 id="bkH4" class="sr-only">Điều kiện đặt tour</h2>
                            <label class="bk-check" for="bkAgree">
                                <input type="checkbox" id="bkAgree" name="agree" value="1" required
                                    aria-describedby="bkErr-agree">
                                <span>Tôi đã đọc và đồng ý với điều kiện đặt tour và chính sách hủy tour.</span>
                            </label>
                            <p class="bk-err" id="bkErr-agree"></p>

                            <details class="bk-terms">
                                <summary>Xem điều kiện đặt tour <i class="fal fa-chevron-down" aria-hidden="true"></i>
                                </summary>
                                <ul>
                                    <li>Chỗ của bạn được giữ <b>{{ $holdHours }} giờ</b> kể từ lúc đặt (thanh toán trực tuyến: theo thời gian ghi ở từng cách thanh toán). Quá hạn mà đơn
                                        chưa được xác nhận, đơn sẽ tự hủy và chỗ được trả lại.</li>
                                    <li>Bạn có thể tự hủy đơn trong mục “Tour đã đặt” khi còn hơn
                                        <b>{{ $cancelDays }} ngày</b> trước ngày khởi hành. Gần ngày hơn, vui lòng liên
                                        hệ để được hỗ trợ.
                                    </li>
                                    <li>Giá cuối cùng do hệ thống tính lại khi bạn xác nhận (đã gồm mã giảm giá hợp lệ nếu
                                        có).</li>
                                    <li>Nhân viên có thể liên hệ qua số điện thoại hoặc email ở trên để xác nhận đơn.</li>
                                </ul>
                            </details>
                        </section>

                        <div class="bk-nav">
                            <button type="button" class="bk-btn bk-btn--ghost bk-btn--inline" data-bk-back="2"><i class="fal fa-arrow-left" aria-hidden="true"></i> Quay lại</button>
                            <button type="submit" class="bk-btn bk-btn--cta bk-btn--inline" data-bk-submit>Xác nhận đặt tour</button>
                        </div>
                    </div>
                </div>

                {{-- ================= CỘT PHẢI: tóm tắt đơn (cập nhật theo báo giá của máy chủ) ================= --}}
                <aside class="bk-side" aria-label="Tóm tắt đơn đặt tour">
                    <div class="bk-sum">
                        <div class="bk-sum__tour">
                            <img src="{{ $cover }}" alt="" width="96" height="72"
                                decoding="async">
                            <div>
                                <small>Mã tour #{{ $tour->tourId }}</small>
                                <h2>{{ $tour->title }}</h2>
                            </div>
                        </div>

                        <ul class="bk-sum__meta">
                            <li><i class="fal fa-map-marker-alt" aria-hidden="true"></i>{{ $tour->destination }}</li>
                            <li><i class="fal fa-calendar-alt" aria-hidden="true"></i><span id="bkSumDate">{{ $start->format('d/m/Y') }}
                                → {{ $end->format('d/m/Y') }}</span></li>
                            <li><i class="fal fa-clock" aria-hidden="true"></i>{{ $tour->time }}</li>
                        </ul>

                        <dl class="bk-lines" aria-live="polite">
                            <div id="bkRowAdults">
                                <dt>Người lớn <small id="bkAdultsFormula">{{ $adultsNow }} ×
                                        {{ $money($tour->priceAdult) }}</small></dt>
                                <dd id="bkAdultsSum">{{ $money($adultsNow * (int) $tour->priceAdult) }}</dd>
                            </div>
                            <div id="bkRowChildren" @if ($childrenNow === 0) hidden @endif>
                                <dt>Trẻ em <small id="bkChildrenFormula">{{ $childrenNow }} ×
                                        {{ $money($tour->priceChild) }}</small></dt>
                                <dd id="bkChildrenSum">{{ $money($childrenNow * (int) $tour->priceChild) }}</dd>
                            </div>
                            <div id="bkRowDiscount" hidden>
                                <dt>Giảm giá <small id="bkDiscountLabel"></small></dt>
                                <dd class="is-neg" id="bkDiscountSum">-0đ</dd>
                            </div>
                            <div class="bk-total">
                                <dt>Tổng cộng</dt>
                                <dd id="bkTotal">{{ $money($subtotal) }}</dd>
                            </div>
                        </dl>
                        <p class="bk-sum__quote" id="bkQuoteNote" aria-live="polite">Giá do máy chủ tính lại khi bạn đổi ngày, số khách hoặc mã.</p>

                        <button type="button" class="bk-btn bk-btn--cta" data-bk-cta>Tiếp tục</button>
                        <p class="bk-sum__safe"><i class="fal fa-shield-check" aria-hidden="true"></i>
                            Bạn chưa bị tính phí cho tới bước cuối.</p>
                        <a class="bk-sum__help" href="{{ route('contact') }}"><i class="fal fa-comment-dots"
                                aria-hidden="true"></i> Cần tư vấn trước khi đặt?</a>
                    </div>
                    {{-- Nói trước điều gì sẽ xảy ra sau khi bấm xác nhận: giảm lo lắng, tăng tỉ lệ hoàn tất --}}
                    <section class="bk-after" aria-labelledby="bkAfterH">
                        <h2 id="bkAfterH">Sau khi bạn xác nhận</h2>
                        <ol>
                            <li><b>Nhận mã đơn ngay</b><span>Mã hiện trên màn hình để bạn lưu lại hoặc gửi cho nhân
                                    viên.</span></li>
                            <li><b>Thanh toán</b><span>Trực tuyến ngay (MoMo, ZaloPay, mã QR) hoặc tại văn phòng trong {{ $holdHours }} giờ.</span></li>
                            <li><b>Nhân viên xác nhận đơn</b><span>Bạn theo dõi trạng thái trong mục “Tour đã
                                    đặt”.</span></li>
                        </ol>
                    </section>
                </aside>
            </div>
        </form>
    </div>
</main>

{{-- Thanh tổng tiền + nút của bước hiện tại, cố định ở đáy màn hình điện thoại --}}
<div class="bk-bar" id="bkBar">
    <div><small>Tổng cộng</small><b id="bkBarTotal">{{ $money($subtotal) }}</b></div>
    <button type="button" class="bk-btn bk-btn--cta" data-bk-cta>Tiếp tục</button>
</div>
<link rel="stylesheet" href="{{ asset('clients/assets/css/booking-wizard.css') }}?v=1">
<script src="{{ asset('clients/assets/js/booking-wizard.js') }}?v=1" defer></script>

@include('clients.blocks.footer')
