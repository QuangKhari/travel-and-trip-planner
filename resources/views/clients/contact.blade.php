@include('clients.blocks.header')

@php
    // Biến từ ContactController::index: $prefill, $topics, $topic, $bookingCode
    $siteName = config('site.name');
    $siteEmail = config('site.email');
    $sitePhone = config('site.phone');
    $siteAddress = config('site.address');
    $siteZalo = config('site.zalo');
    $siteMessenger = config('site.messenger');
    $siteHours = config('site.hours');
    $mapsUrl =
        config('site.maps_url') ?:
        ($siteAddress
            ? 'https://www.google.com/maps/search/?api=1&query=' . urlencode($siteAddress)
            : null);
    $cancelDays = \App\Support\CancelPolicy::MIN_DAYS;
    $holdHours = (int) config('travela.hold_hours', 48);

    $sent = session('contact_sent'); // mảng thông tin sau khi gửi thành công
    $failed = session('contact_failed'); // thông báo khi máy chủ không gửi được thư
    $hasChannel = $sitePhone || $siteZalo || $siteMessenger || $siteEmail;

    $topicIcons = [
        'advice' => 'fa-compass',
        'booking' => 'fa-ticket-alt',
        'policy' => 'fa-file-contract',
        'other' => 'fa-comment-dots',
    ];
    $selectedTopic = old('topic', $topic);
    $codeValue = old('booking_code', $bookingCode);
    $messageValue = old('message');
@endphp

<main class="ct" id="ctPage">

    {{-- ===== Đầu trang: nói rõ ngay "liên hệ bằng cách nào, bao lâu có trả lời" ===== --}}
    <section class="ct-hero">
        <div class="container">
            <nav class="ct-crumb" aria-label="breadcrumb">
                <a href="{{ route('home') }}">Trang chủ</a><i class="fas fa-chevron-right" aria-hidden="true"></i>
                <span aria-current="page">Liên hệ</span>
            </nav>
            <h1>Chúng tôi có thể giúp gì cho bạn?</h1>
            <p class="ct-hero__lead">
                Chọn cách liên hệ thuận tiện nhất, hoặc để lại tin nhắn bên dưới.
                @if ($siteHours)
                    <span class="ct-hours"><i class="fal fa-clock" aria-hidden="true"></i> {{ $siteHours }}</span>
                @endif
            </p>
        </div>
    </section>

    <div class="container">

        {{-- ===== Các kênh liên hệ nhanh: mỗi thẻ là một nút bấm lớn ===== --}}
        @if ($hasChannel)
            <ul class="ct-channels" aria-label="Kênh liên hệ nhanh">
                @if ($sitePhone)
                    <li>
                        <a class="ct-ch ct-ch--phone" href="tel:{{ preg_replace('/\s+/', '', $sitePhone) }}">
                            <span class="ct-ch__ic" aria-hidden="true"><i class="fal fa-phone"></i></span>
                            <span class="ct-ch__tx"><small>Gọi điện</small><b>{{ $sitePhone }}</b></span>
                            <i class="fal fa-arrow-right ct-ch__go" aria-hidden="true"></i>
                        </a>
                    </li>
                @endif
                @if ($siteZalo)
                    <li>
                        <a class="ct-ch ct-ch--zalo" href="https://zalo.me/{{ preg_replace('/\D+/', '', $siteZalo) }}"
                            target="_blank" rel="noopener">
                            <span class="ct-ch__ic" aria-hidden="true"><b>Z</b></span>
                            <span class="ct-ch__tx"><small>Nhắn Zalo</small><b>{{ $siteZalo }}</b></span>
                            <i class="fal fa-arrow-right ct-ch__go" aria-hidden="true"></i>
                        </a>
                    </li>
                @endif
                @if ($siteMessenger)
                    <li>
                        <a class="ct-ch ct-ch--mess" href="https://m.me/{{ $siteMessenger }}" target="_blank"
                            rel="noopener">
                            <span class="ct-ch__ic" aria-hidden="true"><i class="fab fa-facebook-messenger"></i></span>
                            <span class="ct-ch__tx"><small>Messenger</small><b>Nhắn qua Facebook</b></span>
                            <i class="fal fa-arrow-right ct-ch__go" aria-hidden="true"></i>
                        </a>
                    </li>
                @endif
                @if ($siteEmail)
                    <li>
                        <a class="ct-ch ct-ch--mail" href="mailto:{{ $siteEmail }}">
                            <span class="ct-ch__ic" aria-hidden="true"><i class="fal fa-envelope"></i></span>
                            <span class="ct-ch__tx"><small>Gửi email</small><b>{{ $siteEmail }}</b></span>
                            <i class="fal fa-arrow-right ct-ch__go" aria-hidden="true"></i>
                        </a>
                    </li>
                @endif
            </ul>
        @endif

        <div class="ct-layout">

            {{-- ================= BIỂU MẪU / MÀN HÌNH ĐÃ GỬI ================= --}}
            <div class="ct-main">
                @if ($sent)
                    <section class="ct-done" id="ctDone" tabindex="-1" role="status" aria-live="polite">
                        <span class="ct-done__ic" aria-hidden="true"><i class="fas fa-check"></i></span>
                        <h2>Đã gửi tin nhắn!</h2>
                        <p>Cảm ơn bạn. Chúng tôi sẽ phản hồi
                            @if (!empty($sent['email']))
                                qua <b>{{ $sent['email'] }}</b>
                                @if (!empty($sent['phone']))
                                    hoặc số <b>{{ $sent['phone'] }}</b>
                                @endif
                            @else
                                sớm nhất có thể
                            @endif
                            @if ($siteHours)
                                trong giờ làm việc ({{ $siteHours }}).
                            @else
                                trong giờ làm việc.
                            @endif
                        </p>
                        @if (!empty($sent['topic']))
                            <p class="ct-done__meta">
                                Chủ đề: <b>{{ $sent['topic'] }}</b>
                                @if (!empty($sent['code']))
                                    · Mã đơn: <b>{{ $sent['code'] }}</b>
                                @endif
                            </p>
                        @endif
                        <div class="ct-done__act">
                            <a class="ct-btn ct-btn--cta" href="{{ route('tours') }}">Xem các tour đang mở</a>
                            <a class="ct-btn ct-btn--ghost" href="{{ route('contact') }}#ctForm">Gửi tin nhắn khác</a>
                        </div>
                    </section>
                @else
                    <section class="ct-card" aria-labelledby="ctFormH">
                        <h2 id="ctFormH">Gửi tin nhắn cho chúng tôi</h2>
                        <p class="ct-card__sub">Điền thông tin dưới đây, chúng tôi phản hồi qua email hoặc điện thoại.
                            Các ô có dấu <em aria-hidden="true">*</em> là bắt buộc.</p>

                        @if ($failed)
                            <div class="ct-alert ct-alert--bad" role="alert">{{ $failed }}</div>
                        @endif
                        @if ($errors->any())
                            <div class="ct-alert ct-alert--bad" role="alert" tabindex="-1" id="ctServerErrors">
                                <b>Cần kiểm tra lại {{ $errors->count() }} mục:</b>
                                <ul>
                                    @foreach ($errors->all() as $m)
                                        <li>{{ $m }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <form id="ctForm" class="ct-form" action="{{ route('contact.send') }}" method="POST"
                            novalidate>
                            @csrf

                            {{-- Ô bẫy robot: ẩn với người thật --}}
                            <div class="ct-hp" aria-hidden="true">
                                <label>Website <input type="text" name="website" tabindex="-1"
                                        autocomplete="off"></label>
                            </div>

                            <fieldset class="ct-topics">
                                <legend>Bạn cần hỗ trợ về điều gì?</legend>
                                <div class="ct-topics__row">
                                    @foreach ($topics as $key => $label)
                                        <label class="ct-topic">
                                            <input type="radio" name="topic" value="{{ $key }}"
                                                {{ $selectedTopic === $key ? 'checked' : '' }}>
                                            <span><i class="fal {{ $topicIcons[$key] ?? 'fa-comment-dots' }}"
                                                    aria-hidden="true"></i>{{ $label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>

                            {{-- Chỉ hiện khi chọn "Hỗ trợ đơn đã đặt" (JS bật/tắt; không có JS thì luôn hiện) --}}
                            <div class="ct-field ct-field--code" id="ctCodeWrap">
                                <label for="ctCode">Mã đơn (nếu có)</label>
                                <input type="text" id="ctCode" name="booking_code" maxlength="40"
                                    autocomplete="off" value="{{ $codeValue }}"
                                    placeholder="Ví dụ: TR-240915-AB12"
                                    aria-describedby="ctHint-code ctErr-booking_code">
                                <p class="ct-hint" id="ctHint-code">Có trong email xác nhận hoặc trang “Chi tiết đơn”.
                                    Giúp chúng tôi tìm đơn của bạn nhanh hơn.</p>
                                <p class="ct-err" id="ctErr-booking_code">{{ $errors->first('booking_code') }}</p>
                            </div>

                            <div class="ct-grid">
                                <div class="ct-field {{ $errors->has('name') ? 'has-error' : '' }}">
                                    <label for="ctName">Họ và tên <em aria-hidden="true">*</em></label>
                                    <input type="text" id="ctName" name="name" maxlength="100"
                                        autocomplete="name" value="{{ old('name', $prefill['name']) }}"
                                        placeholder="Nguyễn Văn A" required aria-describedby="ctErr-name"
                                        @if ($errors->has('name')) aria-invalid="true" @endif>
                                    <p class="ct-err" id="ctErr-name">{{ $errors->first('name') }}</p>
                                </div>

                                <div class="ct-field {{ $errors->has('phone_number') ? 'has-error' : '' }}">
                                    <label for="ctPhone">Số điện thoại <em aria-hidden="true">*</em></label>
                                    <input type="tel" id="ctPhone" name="phone_number" inputmode="tel"
                                        maxlength="15" autocomplete="tel"
                                        value="{{ old('phone_number', $prefill['phone_number']) }}"
                                        placeholder="0901234567" required aria-describedby="ctErr-phone_number"
                                        @if ($errors->has('phone_number')) aria-invalid="true" @endif>
                                    <p class="ct-err" id="ctErr-phone_number">{{ $errors->first('phone_number') }}
                                    </p>
                                </div>
                            </div>

                            <div class="ct-field {{ $errors->has('email') ? 'has-error' : '' }}">
                                <label for="ctEmail">Email <em aria-hidden="true">*</em></label>
                                <input type="email" id="ctEmail" name="email" maxlength="255"
                                    autocomplete="email" value="{{ old('email', $prefill['email']) }}"
                                    placeholder="ban@example.com" required aria-describedby="ctErr-email"
                                    @if ($errors->has('email')) aria-invalid="true" @endif>
                                <p class="ct-err" id="ctErr-email">{{ $errors->first('email') }}</p>
                            </div>

                            <div class="ct-field {{ $errors->has('message') ? 'has-error' : '' }}">
                                <label for="ctMsg">Nội dung <em aria-hidden="true">*</em></label>
                                <textarea id="ctMsg" name="message" rows="5" maxlength="2000" required
                                    placeholder="Bạn muốn hỏi điều gì? Càng cụ thể, chúng tôi càng trả lời nhanh và đúng ý."
                                    aria-describedby="ctCount ctErr-message" @if ($errors->has('message')) aria-invalid="true" @endif>{{ $messageValue }}</textarea>
                                <div class="ct-field__foot">
                                    <p class="ct-err" id="ctErr-message">{{ $errors->first('message') }}</p>
                                    <span class="ct-count" id="ctCount"
                                        aria-live="off">{{ mb_strlen((string) $messageValue) }}/2000</span>
                                </div>
                            </div>

                            <button type="submit" class="ct-btn ct-btn--cta" id="ctSubmit">
                                <i class="fas fa-paper-plane" aria-hidden="true"></i> <span>Gửi tin nhắn</span>
                            </button>
                            <p class="ct-privacy"><i class="fal fa-lock" aria-hidden="true"></i> Thông tin của bạn
                                chỉ
                                dùng để phản hồi yêu cầu này.</p>
                        </form>
                    </section>
                @endif
            </div>

            {{-- ================= CỘT PHẢI: thông tin + FAQ ================= --}}
            <aside class="ct-side">
                @if ($siteAddress || $siteHours)
                    <section class="ct-card ct-card--info" aria-labelledby="ctOfficeH">
                        <h2 id="ctOfficeH">Văn phòng {{ $siteName }}</h2>
                        <ul class="ct-info">
                            @if ($siteAddress)
                                <li><i class="fal fa-map-marker-alt" aria-hidden="true"></i>
                                    <div><small>Địa chỉ</small><b>{{ $siteAddress }}</b></div>
                                </li>
                            @endif
                            @if ($siteHours)
                                <li><i class="fal fa-clock" aria-hidden="true"></i>
                                    <div><small>Giờ làm việc</small><b>{{ $siteHours }}</b></div>
                                </li>
                            @endif
                        </ul>
                        @if ($mapsUrl)
                            <a class="ct-btn ct-btn--ghost ct-btn--block" href="{{ $mapsUrl }}" target="_blank"
                                rel="noopener"><i class="fal fa-directions" aria-hidden="true"></i> Chỉ đường trên
                                bản đồ</a>
                        @endif
                    </section>
                @endif

                <section class="ct-card" aria-labelledby="ctFaqH">
                    <h2 id="ctFaqH">Có thể bạn muốn biết</h2>
                    <div class="ct-faq">
                        <details>
                            <summary>Chỗ được giữ trong bao lâu?</summary>
                            <p>Sau khi đặt, chỗ được giữ {{ $holdHours }} giờ để bạn đến văn phòng thanh toán và xác
                                nhận. Quá hạn mà đơn chưa được xác nhận, đơn sẽ tự hủy.</p>
                        </details>
                        <details>
                            <summary>Tôi có thể hủy tour không?</summary>
                            <p>Bạn tự hủy được trong mục “Tour đã đặt” khi còn hơn {{ $cancelDays }} ngày trước ngày
                                khởi hành. Gần ngày hơn, hãy nhắn cho chúng tôi để được hỗ trợ.</p>
                        </details>
                        <details>
                            <summary>Thanh toán như thế nào?</summary>
                            <p>Hiện hỗ trợ thanh toán tại văn phòng. Thanh toán trực tuyến (VNPay) sẽ sớm được hỗ
                                trợ.</p>
                        </details>
                        <details>
                            <summary>Tôi đặt giúp người khác được không?</summary>
                            <p>Được. Ở bước đặt tour, bạn có thể sửa họ tên, số điện thoại, email để nhập thông tin của
                                người đi cùng.</p>
                        </details>
                        <details>
                            <summary>Tôi không thấy email xác nhận?</summary>
                            <p>Hãy kiểm tra thư mục Spam/Quảng cáo. Nếu vẫn không thấy, gửi cho chúng tôi mã đơn ở biểu
                                mẫu bên cạnh để được hỗ trợ.</p>
                        </details>
                        <details>
                            <summary>Khi nào tôi đánh giá được tour?</summary>
                            <p>Sau khi tour hoàn tất, vào “Tour đã đặt” và bấm “Viết đánh giá”.</p>
                        </details>
                    </div>
                </section>
            </aside>
        </div>
    </div>
</main>

@include('clients.blocks.footer')
