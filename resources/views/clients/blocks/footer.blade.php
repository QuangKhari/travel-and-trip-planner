{{-- Footer (Travel theme). Thông tin liên hệ lấy từ config/site.php (.env: SITE_EMAIL, SITE_PHONE, SITE_ADDRESS...). --}}
@php
    $siteEmail = config('site.email');
    $sitePhone = config('site.phone');
    $siteAddress = config('site.address');
    $social = array_filter([
        'fa-facebook-f' => config('site.facebook'),
        'fa-youtube' => config('site.youtube'),
        'fa-instagram' => config('site.instagram'),
    ]);
@endphp
<footer class="tv-footer">
    <div class="container">
        <div class="tv-footer__grid">
            <div class="tv-footer__brand">
                <a href="{{ route('home') }}" class="tv-footer__logo"><img
                        src="{{ asset('clients/assets/images/logos/logo.png') }}" alt="{{ config('site.name') }}"></a>
                <p>Chọn tour, xem lịch trình từng ngày và đặt chỗ trong vài bước. Mọi thông tin về giá và chỗ trống đều
                    lấy trực tiếp từ hệ thống.</p>
                @if (count($social))
                    <div class="tv-footer__social">
                        @foreach ($social as $icon => $url)
                            <a href="{{ $url }}" target="_blank" rel="noopener"
                                aria-label="{{ $icon }}"><i class="fab {{ $icon }}"></i></a>
                        @endforeach
                    </div>
                @endif
            </div>

            <nav class="tv-footer__col" aria-label="Khám phá">
                <h5>Khám phá</h5>
                <ul>
                    <li><a href="{{ route('tours') }}">Tất cả tour</a></li>
                    <li><a href="{{ route('destination') }}">Điểm đến</a></li>
                    <li><a href="{{ route('about') }}">Giới thiệu</a></li>
                    <li><a href="{{ route('contact') }}">Liên hệ</a></li>
                </ul>
            </nav>

            <nav class="tv-footer__col" aria-label="Tài khoản">
                <h5>Tài khoản</h5>
                <ul>
                    @if (session()->has('username'))
                        <li><a href="{{ route('user-profile') }}">Thông tin cá nhân</a></li>
                        <li><a href="{{ route('my-tours') }}">Tour đã đặt</a></li>
                    @else
                        <li><a href="{{ route('login') }}">Đăng nhập / Đăng ký</a></li>
                        <li><a href="{{ route('password.request') }}">Quên mật khẩu</a></li>
                    @endif
                </ul>
            </nav>

            <div class="tv-footer__col">
                <h5>Liên hệ</h5>
                <ul class="tv-footer__contact">
                    @if ($siteAddress)
                        <li><i class="fal fa-map-marker-alt"></i><span>{{ $siteAddress }}</span></li>
                    @endif
                    @if ($siteEmail)
                        <li><i class="fal fa-envelope"></i><a
                                href="mailto:{{ $siteEmail }}">{{ $siteEmail }}</a></li>
                    @endif
                    @if ($sitePhone)
                        <li><i class="fal fa-phone"></i><a
                                href="tel:{{ preg_replace('/\s+/', '', $sitePhone) }}">{{ $sitePhone }}</a></li>
                    @endif
                    @if (!$siteAddress && !$siteEmail && !$sitePhone)
                        <li><i class="fal fa-comment-alt-lines"></i><a href="{{ route('contact') }}">Gửi tin nhắn cho
                                chúng tôi</a></li>
                    @endif
                </ul>
            </div>
        </div>

        <div class="tv-footer__bottom">
            <span>&copy; {{ date('Y') }} {{ config('site.name') }}. Mọi quyền được bảo lưu.</span>
        </div>
    </div>
</footer>

</div>
<!--End pagewrapper-->


<!-- Jquery -->
<script src="{{ asset('clients/assets/js/jquery-3.6.0.min.js') }}"></script>
<!-- Bootstrap -->
<script src="{{ asset('clients/assets/js/bootstrap.min.js') }}"></script>
<!-- Appear Js -->
<script src="{{ asset('clients/assets/js/appear.min.js') }}"></script>
<!-- Slick -->
<script src="{{ asset('clients/assets/js/slick.min.js') }}"></script>
<!-- Magnific Popup -->
<script src="{{ asset('clients/assets/js/jquery.magnific-popup.min.js') }}"></script>
<!-- Nice Select -->
<script src="{{ asset('clients/assets/js/jquery.nice-select.min.js') }}"></script>
<!-- Image Loader -->
<script src="{{ asset('clients/assets/js/imagesloaded.pkgd.min.js') }}"></script>
<!-- Skillbar -->
<script src="{{ asset('clients/assets/js/skill.bars.jquery.min.js') }}"></script>
<!-- Isotope -->
<script src="{{ asset('clients/assets/js/isotope.pkgd.min.js') }}"></script>
<!--  AOS Animation -->
<script src="{{ asset('clients/assets/js/aos.js') }}"></script>
<!-- Custom script -->
<script src="{{ asset('clients/assets/js/script.js') }}"></script>

<script src="{{ asset('clients/assets/js/custom-js.js') }}"></script>
<script src="{{ asset('clients/assets/js/travel-theme.js') }}?v=3"></script>

<script src="{{ asset('clients/assets/js/jquery.datetimepicker.full.min.js') }}"></script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>

</body>

</html>
