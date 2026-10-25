{{--
  Footer dùng chung cho trang chủ và mọi trang con.
  Thông tin liên hệ lấy từ config/site.php (.env: SITE_EMAIL, SITE_PHONE, SITE_ZALO, SITE_MESSENGER, SITE_ADDRESS, SITE_HOURS,
  SITE_FACEBOOK, SITE_YOUTUBE, SITE_INSTAGRAM). Khối nào chưa cấu hình thì tự ẩn, riêng dòng "Gửi tin nhắn" luôn có.
--}}
@php
    $sfEmail = config('site.email');
    $sfPhone = config('site.phone');
    $sfZalo = config('site.zalo');
    $sfMessenger = config('site.messenger');
    $sfAddress = config('site.address');
    $sfHours = config('site.hours');
    $sfTel = $sfPhone ? preg_replace('/[^\d+]/', '', $sfPhone) : null;
    $sfSocial = array_filter([
        ['icon' => 'fab fa-facebook-f', 'label' => 'Facebook', 'url' => config('site.facebook')],
        ['icon' => 'fab fa-youtube', 'label' => 'YouTube', 'url' => config('site.youtube')],
        ['icon' => 'fab fa-instagram', 'label' => 'Instagram', 'url' => config('site.instagram')],
    ], fn($s) => !empty($s['url']));
@endphp
<footer class="tvf" id="tvFooter">
    <div class="container">
        {{-- Dải mời liên hệ: luôn có, để cuối trang không bao giờ là "ngõ cụt" --}}
        <div class="tvf-cta">
            <div class="tvf-cta__text">
                <p class="tvf-cta__eyebrow">Cần người đồng hành?</p>
                <h2>Chưa chắc nên chọn tour nào? Hỏi chúng tôi một câu.</h2>
            </div>
            <div class="tvf-cta__btns">
                <a class="tvf-btn tvf-btn--sand" href="{{ route('contact') }}"><i class="fas fa-comment-dots"
                        aria-hidden="true"></i> Nhắn tư vấn viên</a>
                <a class="tvf-btn tvf-btn--line" href="{{ route('ask.index') }}"><i class="fas fa-question-circle"
                        aria-hidden="true"></i> Xem hỏi đáp</a>
            </div>
        </div>

        <div class="tvf-grid">
            <div class="tvf-brand">
                <a href="{{ route('home') }}" class="tvf-logo" aria-label="Trang chủ {{ config('site.name') }}">
                    <img src="{{ asset('clients/assets/images/logos/logo.png') }}" alt="{{ config('site.name') }}"
                        width="155" height="40" decoding="async" loading="lazy">
                </a>
                <p>Chọn tour, xem lịch trình từng ngày và đặt chỗ trong vài bước. Giá và chỗ trống lấy trực tiếp từ hệ
                    thống, không phát sinh khi thanh toán.</p>

                {{-- Hàng biểu tượng liên hệ: luôn thấy được, không phụ thuộc cấu hình mạng xã hội --}}
                <ul class="tvf-icons" aria-label="Kênh liên hệ">
                    @if ($sfTel)
                        <li><a href="tel:{{ $sfTel }}" aria-label="Gọi {{ $sfPhone }}"><i
                                    class="fas fa-phone-alt" aria-hidden="true"></i></a></li>
                    @endif
                    @if ($sfEmail)
                        <li><a href="mailto:{{ $sfEmail }}" aria-label="Gửi email"><i class="fas fa-envelope"
                                    aria-hidden="true"></i></a></li>
                    @endif
                    @if ($sfMessenger)
                        <li><a href="https://m.me/{{ $sfMessenger }}" target="_blank" rel="noopener"
                                aria-label="Nhắn qua Messenger"><i class="fab fa-facebook-messenger"
                                    aria-hidden="true"></i></a></li>
                    @endif
                    @if ($sfZalo)
                        <li><a href="https://zalo.me/{{ preg_replace('/\D/', '', $sfZalo) }}" target="_blank"
                                rel="noopener" aria-label="Nhắn qua Zalo"><span class="tvf-zalo"
                                    aria-hidden="true">Zalo</span></a></li>
                    @endif
                    @foreach ($sfSocial as $s)
                        <li><a href="{{ $s['url'] }}" target="_blank" rel="noopener"
                                aria-label="{{ $s['label'] }}"><i class="{{ $s['icon'] }}" aria-hidden="true"></i></a>
                        </li>
                    @endforeach
                    <li><a href="{{ route('contact') }}" aria-label="Trang liên hệ"><i class="fas fa-paper-plane"
                                aria-hidden="true"></i></a></li>
                </ul>
            </div>

            <nav class="tvf-col" aria-label="Khám phá">
                <h3>Khám phá</h3>
                <ul>
                    <li><a href="{{ route('destination') }}">Điểm đến</a></li>
                    <li><a href="{{ route('foods.index') }}">Ẩm thực</a></li>
                    <li><a href="{{ route('experiences.index') }}">Trải nghiệm</a></li>
                    <li><a href="{{ route('events.index') }}">Lễ hội</a></li>
                    <li><a href="{{ route('itineraries.index') }}">Lịch trình</a></li>
                    <li><a href="{{ route('seasons.matrix') }}">Nên đi tháng nào</a></li>
                </ul>
            </nav>

            <nav class="tvf-col" aria-label="Đặt tour">
                <h3>Đặt tour</h3>
                <ul>
                    <li><a href="{{ route('tours') }}">Tất cả tour</a></li>
                    <li><a href="{{ route('team') }}">Hướng dẫn viên</a></li>
                    @if (session()->has('username'))
                        <li><a href="{{ route('my-tours') }}">Tour đã đặt</a></li>
                        <li><a href="{{ route('journal') }}">Sổ tay du hành</a></li>
                        <li><a href="{{ route('user-profile') }}">Thông tin cá nhân</a></li>
                    @else
                        <li><a href="{{ route('login') }}">Đăng nhập / Đăng ký</a></li>
                        <li><a href="{{ route('password.request') }}">Quên mật khẩu</a></li>
                    @endif
                </ul>
            </nav>

            <nav class="tvf-col" aria-label="Về Travela">
                <h3>Về chúng tôi</h3>
                <ul>
                    <li><a href="{{ route('guides.index') }}">Cẩm nang</a></li>
                    <li><a href="{{ route('blogs') }}">Blog</a></li>
                    <li><a href="{{ route('about') }}">Giới thiệu</a></li>
                    <li><a href="{{ route('contact') }}">Liên hệ</a></li>
                    <li><a href="{{ route('data.sources') }}">Nguồn dữ liệu</a></li>
                </ul>
            </nav>

            <div class="tvf-col tvf-contact">
                <h3>Liên hệ</h3>
                <ul>
                    @if ($sfAddress)
                        <li><i class="fas fa-map-marker-alt" aria-hidden="true"></i><span>{{ $sfAddress }}</span></li>
                    @endif
                    @if ($sfPhone)
                        <li><i class="fas fa-phone-alt" aria-hidden="true"></i><a
                                href="tel:{{ $sfTel }}">{{ $sfPhone }}</a></li>
                    @endif
                    @if ($sfEmail)
                        <li><i class="fas fa-envelope" aria-hidden="true"></i><a
                                href="mailto:{{ $sfEmail }}">{{ $sfEmail }}</a></li>
                    @endif
                    @if ($sfHours)
                        <li><i class="fas fa-clock" aria-hidden="true"></i><span>{{ $sfHours }}</span></li>
                    @endif
                    <li><i class="fas fa-comment-alt" aria-hidden="true"></i><a href="{{ route('contact') }}">Gửi tin
                            nhắn cho chúng tôi</a></li>
                </ul>
            </div>
        </div>

        @include('clients.partials.site-stats')

        <div class="tvf-bottom">
            <span>&copy; {{ date('Y') }} {{ config('site.name') }}. Mọi quyền được bảo lưu.</span>
            <a href="#top" class="tvf-top" data-scroll-top>Lên đầu trang <i class="fas fa-arrow-up"
                    aria-hidden="true"></i></a>
        </div>
    </div>
</footer>
