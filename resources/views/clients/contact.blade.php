@include('clients.blocks.header')
@include('clients.blocks.banner')

@php
    $siteEmail = config('site.email');
    $sitePhone = config('site.phone');
    $siteAddress = config('site.address');
@endphp

<section class="tv-contact">
    <div class="container">
        <div class="tv-contact__grid">

            <div class="tv-contact__info">
                <h2 class="td-h2">Chúng tôi luôn sẵn sàng giúp bạn</h2>
                <p class="tv-muted">Bạn cần tư vấn chọn tour, hỏi về đơn đã đặt hay có góp ý? Hãy gửi tin nhắn, chúng tôi
                    sẽ phản hồi qua email.</p>

                <ul class="tv-contact__list">
                    @if ($siteEmail)
                        <li><span><i class="fal fa-envelope"></i></span>
                            <div><small>Email</small><a href="mailto:{{ $siteEmail }}">{{ $siteEmail }}</a></div>
                        </li>
                    @endif
                    @if ($sitePhone)
                        <li><span><i class="fal fa-phone"></i></span>
                            <div><small>Điện thoại</small><a
                                    href="tel:{{ preg_replace('/\s+/', '', $sitePhone) }}">{{ $sitePhone }}</a></div>
                        </li>
                    @endif
                    @if ($siteAddress)
                        <li><span><i class="fal fa-map-marker-alt"></i></span>
                            <div><small>Văn phòng</small><b>{{ $siteAddress }}</b></div>
                        </li>
                    @endif
                </ul>
                @if (!$siteEmail && !$sitePhone && !$siteAddress)
                    <p class="tv-muted-sm">Thông tin liên hệ sẽ hiển thị ở đây sau khi cấu hình trong file .env
                        (SITE_EMAIL, SITE_PHONE, SITE_ADDRESS).</p>
                @endif
            </div>

            {{-- GIỮ NGUYÊN id/name: #contactForm, #name, #phone_number, #email, #message, #msgSubmit (custom-js.js dùng) --}}
            <div class="tv-contact__form">
                <form id="contactForm" class="contactForm" name="contactForm" action="{{ route('contact.send') }}"
                    method="post">
                    @csrf
                    <h2 class="td-h3">Gửi tin nhắn</h2>
                    <p class="tv-muted-sm">Email của bạn sẽ không được công khai. Các trường có dấu * là bắt buộc.</p>

                    <div class="tv-fieldrow">
                        <label for="name">Họ và tên <em>*</em></label>
                        <input type="text" id="name" name="name" value="{{ old('name') }}"
                            placeholder="Nguyễn Văn A" required>
                        <div class="help-block with-errors"></div>
                    </div>
                    <div class="tv-fieldrow tv-fieldrow--2">
                        <div>
                            <label for="phone_number">Số điện thoại <em>*</em></label>
                            <input type="text" id="phone_number" name="phone_number"
                                value="{{ old('phone_number') }}" placeholder="09xx xxx xxx" required>
                            <div class="help-block with-errors"></div>
                        </div>
                        <div>
                            <label for="email">Email <em>*</em></label>
                            <input type="email" id="email" name="email" value="{{ old('email') }}"
                                placeholder="ban@example.com" required>
                            <div class="help-block with-errors"></div>
                        </div>
                    </div>
                    <div class="tv-fieldrow">
                        <label for="message">Nội dung <em>*</em></label>
                        <textarea name="message" id="message" rows="5" maxlength="2000" placeholder="Bạn muốn hỏi điều gì?" required>{{ old('message') }}</textarea>
                        <div class="help-block with-errors"></div>
                    </div>
                    <button type="submit" class="tv-btn-solid">Gửi tin nhắn</button>
                    <div id="msgSubmit" class="hidden"></div>
                </form>
            </div>
        </div>
    </div>
</section>

@if ($siteAddress)
    <div class="tv-contact__map">
        <iframe title="Bản đồ văn phòng" loading="lazy" referrerpolicy="no-referrer-when-downgrade"
            src="https://www.google.com/maps?q={{ urlencode($siteAddress) }}&output=embed&hl=vi"></iframe>
    </div>
@endif

@include('clients.blocks.footer')
