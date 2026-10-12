@include('clients.blocks.header')

@php
    $siteEmail = config('site.email');
    $sitePhone = config('site.phone');
    $siteAddress = config('site.address');
    $siteZalo = config('site.zalo');
    $siteMessenger = config('site.messenger');
    $cancelDays = \App\Support\CancelPolicy::MIN_DAYS;
    $holdHours = (int) config('travela.hold_hours', 48);
@endphp

<main class="px-contact">
    <section class="px-contact__top"
        style="background-image:url('{{ asset('clients/assets/images/banner/banner-contact.jpg') }}')">
        <div class="container">
            <span class="px-eyebrow"><i class="fas fa-circle" aria-hidden="true"></i> Hỗ trợ trực tuyến</span>
            <h1>Trò chuyện với {{ config('site.name') }}</h1>
            <p>Nhắn trực tiếp như một cuộc trò chuyện. Chọn câu hỏi nhanh bên dưới tin nhắn để nhận câu trả lời ngay.
            </p>
        </div>
        <svg class="px-wave" viewBox="0 0 1440 80" preserveAspectRatio="none" aria-hidden="true">
            <path d="M0 40 Q180 80 380 48 T760 44 T1130 28 T1440 48 V80 H0Z" />
        </svg>
    </section>

    <div class="container">
        <div class="px-contact__shell">
            {{-- Khung chat kiểu Messenger (dùng chung bộ máy travel-chat.js với bong bóng nổi) --}}
            <div class="px-contact__chatwrap">
                <div class="px-contact__chat" data-travel-chat="page" aria-label="Khung chat hỗ trợ"></div>
            </div>

            <aside class="px-contact__side">
                <section>
                    <h2>Cách khác để liên hệ</h2>
                    <ul class="tc-ways">
                        @if ($sitePhone)
                            <li><i class="fal fa-phone"></i>
                                <div><small>Điện thoại</small><a
                                        href="tel:{{ preg_replace('/\s+/', '', $sitePhone) }}">{{ $sitePhone }}</a>
                                </div>
                            </li>
                        @endif
                        @if ($siteEmail)
                            <li><i class="fal fa-envelope"></i>
                                <div><small>Email</small><a href="mailto:{{ $siteEmail }}">{{ $siteEmail }}</a>
                                </div>
                            </li>
                        @endif
                        @if ($siteZalo)
                            <li><i class="fal fa-comment-dots"></i>
                                <div><small>Zalo</small><a
                                        href="https://zalo.me/{{ preg_replace('/\D+/', '', $siteZalo) }}"
                                        target="_blank" rel="noopener">{{ $siteZalo }}</a></div>
                            </li>
                        @endif
                        @if ($siteMessenger)
                            <li><i class="fab fa-facebook-messenger"></i>
                                <div><small>Messenger</small><a href="https://m.me/{{ $siteMessenger }}" target="_blank"
                                        rel="noopener">Nhắn qua Messenger</a></div>
                            </li>
                        @endif
                        @if ($siteAddress)
                            <li><i class="fal fa-map-marker-alt"></i>
                                <div><small>Văn phòng</small><b>{{ $siteAddress }}</b></div>
                            </li>
                        @endif
                    </ul>
                    @if (!$sitePhone && !$siteEmail && !$siteZalo && !$siteMessenger && !$siteAddress)
                        <p class="tv-muted-sm">Hiện bạn có thể nhắn trực tiếp ở khung chat bên cạnh. Chúng tôi sẽ phản
                            hồi qua email hoặc điện thoại bạn để lại.</p>
                    @endif
                </section>

                <section>
                    <h2>Câu hỏi thường gặp</h2>
                    <div class="tc-faq">
                        <details>
                            <summary>Chỗ được giữ trong bao lâu?</summary>
                            <p>Sau khi đặt, chỗ được giữ {{ $holdHours }} giờ để bạn hoàn tất thanh toán. Quá hạn mà
                                đơn chưa được xác nhận, đơn sẽ tự hủy.</p>
                        </details>
                        <details>
                            <summary>Tôi có thể hủy tour không?</summary>
                            <p>Bạn tự hủy được trong mục “Tour đã đặt” khi còn hơn {{ $cancelDays }} ngày trước ngày
                                khởi hành. Gần ngày hơn, hãy nhắn cho chúng tôi để được hỗ trợ.</p>
                        </details>
                        <details>
                            <summary>Thanh toán như thế nào?</summary>
                            <p>Hiện hỗ trợ thanh toán tại văn phòng. Thanh toán trực tuyến (VNPay) sẽ sớm được hỗ trợ.
                            </p>
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
