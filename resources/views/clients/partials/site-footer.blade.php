{{--
  Footer dùng chung cho trang chủ và mọi trang con (CSS: tv3.css, phần FOOTER).
  Thông tin liên hệ lấy từ config/site.php (.env: SITE_EMAIL, SITE_PHONE, SITE_ZALO, SITE_MESSENGER, SITE_ADDRESS, SITE_HOURS,
  SITE_FACEBOOK, SITE_YOUTUBE, SITE_INSTAGRAM). Mục nào chưa cấu hình thì tự ẩn, riêng dòng "Gửi tin nhắn" luôn có.
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
    $siteStats = app(\App\Services\Content\SiteStats::class)->get();
@endphp

{{-- Dải mời liên hệ nằm đè mép trên footer: cuối trang không bao giờ là "ngõ cụt" --}}
<section class="tvf3-band" aria-label="Liên hệ tư vấn">
    <div class="v3-wrap">
        <div class="tvf3-band__box">
            <div class="tvf3-band__t">
                <svg class="tvf3-band__ico" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.2"
                    stroke-linejoin="round" aria-hidden="true">
                    <path d="M5 22 43 6 33 42 22 29z" />
                    <path d="M22 29 43 6M22 29v11l6-6" />
                </svg>
                <div>
                    <h2>Đồng hành cùng {{ config('site.name') }}</h2>
                    <p>Chưa chắc nên chọn tour nào? Hỏi chúng tôi một câu, tư vấn viên sẽ trả lời bạn.</p>
                </div>
            </div>
            <div class="tvf3-band__btns">
                <a class="v3-btn v3-btn--teal" href="{{ route('contact') }}"><i class="fas fa-comment-dots"
                        aria-hidden="true"></i> Nhắn tư vấn viên</a>
                <a class="v3-btn v3-btn--line" href="{{ route('ask.index') }}">Xem hỏi đáp</a>
            </div>
            <svg class="tvf3-band__trail" viewBox="0 0 140 46" fill="none" aria-hidden="true">
                <path d="M2 40c30-2 60-14 96-30" stroke="currentColor" stroke-width="1.6" stroke-dasharray="2 6"
                    stroke-linecap="round" />
                <path d="M104 8l14-6-4 13-4-4-6 3z" fill="currentColor" />
            </svg>
        </div>
    </div>
</section>

<footer class="tvf" id="tvFooter">
    {{-- Nét vẽ mái chùa, tháp cổ ở góc phải (trang trí) --}}
    <svg class="tvf3-art" viewBox="0 0 460 200" fill="none" stroke="currentColor" stroke-width="1.5"
        stroke-linejoin="round" aria-hidden="true">
        <path d="M0 196h460" />
        <path d="M40 196v-60h40v60M30 136h60l-8-10H38zM44 126l16-16 16 16M60 110V96" />
        <path d="M120 196v-90h60v90M108 106h84l-12-14h-60zM124 92l26-24 26 24M134 82h32M150 68V48M144 54h12" />
        <path d="M135 196v-40h30v40M150 156v40" />
        <path d="M220 196v-50h30v50M212 146h46l-8-10h-30zM226 136l9-14 9 14" />
        <path d="M290 196v-120h44v120M280 76h64l-10-14h-44zM292 62l20-26 20 26M312 36V14M304 22h16" />
        <path d="M290 116h44M290 156h44M300 196v-28h24v28" />
        <path d="M370 196v-70h50v70M360 126h70l-10-12h-50zM374 114l21-20 21 20M395 94V80" />
        <path d="M385 196v-34h20v34" />
    </svg>

    <div class="container">
        <div class="tvf3-grid">
            <div class="tvf3-brand">
                <a href="{{ route('home') }}" class="tvf3-logo" aria-label="Trang chủ {{ config('site.name') }}">
                    <svg viewBox="0 0 40 40" aria-hidden="true">
                        <rect width="40" height="40" rx="13" fill="#32C9C9" />
                        <circle cx="26" cy="14" r="5" fill="#DDAF32" />
                        <path d="M6 25c4-4 7-4 11 0s7 4 11 0 5-3 6-2v5c-1-1-2-1-6 2s-7 3-11 0-7-4-11 0z"
                            fill="#fff" />
                        <path d="M6 32c4-4 7-4 11 0s7 4 11 0 5-3 6-2v3c-1-1-2-1-6 2s-7 3-11 0-7-4-11 0z"
                            fill="#fff" opacity=".55" />
                    </svg>
                    {{ config('site.name') }}
                </a>
                <p class="tvf3-tag">Khám phá Việt Nam cùng bạn!</p>
                <p class="tvf3-desc">Chọn tour, xem lịch trình từng ngày và đặt chỗ trong vài bước. Giá và số chỗ lấy
                    trực tiếp từ hệ thống.</p>
                <ul class="tvf3-icons" aria-label="Kênh liên hệ">
                    @foreach ($sfSocial as $s)
                        <li><a href="{{ $s['url'] }}" target="_blank" rel="noopener"
                                aria-label="{{ $s['label'] }}"><i class="{{ $s['icon'] }}" aria-hidden="true"></i></a>
                        </li>
                    @endforeach
                    @if ($sfMessenger)
                        <li><a href="https://m.me/{{ $sfMessenger }}" target="_blank" rel="noopener"
                                aria-label="Nhắn qua Messenger"><i class="fab fa-facebook-messenger"
                                    aria-hidden="true"></i></a></li>
                    @endif
                    @if ($sfZalo)
                        <li><a href="https://zalo.me/{{ preg_replace('/\D/', '', $sfZalo) }}" target="_blank"
                                rel="noopener" aria-label="Nhắn qua Zalo"><span class="tvf3-zalo"
                                    aria-hidden="true">Zalo</span></a></li>
                    @endif
                    @if ($sfEmail)
                        <li><a href="mailto:{{ $sfEmail }}" aria-label="Gửi email"><i class="fas fa-envelope"
                                    aria-hidden="true"></i></a></li>
                    @endif
                    <li><a href="{{ route('contact') }}" aria-label="Trang liên hệ"><i class="fas fa-paper-plane"
                                aria-hidden="true"></i></a></li>
                </ul>
            </div>

            <nav class="tvf3-col" aria-label="Liên kết nhanh">
                <h3>Liên kết nhanh</h3>
                <ul>
                    <li><a href="{{ route('home') }}">Trang chủ</a></li>
                    <li><a href="{{ route('about') }}">Giới thiệu</a></li>
                    <li><a href="{{ route('tours') }}">Tours</a></li>
                    <li><a href="{{ route('destination') }}">Điểm đến</a></li>
                    <li><a href="{{ route('blogs') }}">Blog</a></li>
                    <li><a href="{{ route('contact') }}">Liên hệ</a></li>
                </ul>
            </nav>

            <nav class="tvf3-col" aria-label="Khám phá">
                <h3>Khám phá</h3>
                <ul>
                    <li><a href="{{ route('foods.index') }}">Ẩm thực</a></li>
                    <li><a href="{{ route('experiences.index') }}">Trải nghiệm</a></li>
                    <li><a href="{{ route('events.index') }}">Lễ hội</a></li>
                    <li><a href="{{ route('itineraries.index') }}">Lịch trình</a></li>
                    <li><a href="{{ route('seasons.matrix') }}">Nên đi tháng nào</a></li>
                    <li><a href="{{ route('planner') }}">Lên kế hoạch</a></li>
                </ul>
            </nav>

            <nav class="tvf3-col" aria-label="Hỗ trợ">
                <h3>Hỗ trợ</h3>
                <ul>
                    <li><a href="{{ route('ask.index') }}">Câu hỏi thường gặp</a></li>
                    <li><a href="{{ route('guides.index') }}">Cẩm nang</a></li>
                    @if (session()->has('username'))
                        <li><a href="{{ route('my-tours') }}">Tour đã đặt</a></li>
                    @else
                        <li><a href="{{ route('login') }}">Đăng nhập / Đăng ký</a></li>
                    @endif
                    <li><a href="{{ route('team') }}">Hướng dẫn viên</a></li>
                    <li><a href="{{ route('data.sources') }}">Nguồn dữ liệu</a></li>
                </ul>
            </nav>

            <div class="tvf3-col tvf3-contact">
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

        @if ($siteStats)
            <p class="tvf3-stats" aria-label="Quy mô nội dung">
                @foreach ($siteStats as $label => $n)
                    <span><b>{{ number_format($n, 0, ',', '.') }}</b> {{ mb_strtolower($label) }}</span>
                @endforeach
            </p>
        @endif

        <div class="tvf3-bottom">
            <span>&copy; {{ date('Y') }} {{ config('site.name') }}. Mọi quyền được bảo lưu.</span>
            <a href="#top" class="tvf3-top" data-scroll-top aria-label="Lên đầu trang"><i class="fas fa-arrow-up"
                    aria-hidden="true"></i></a>
        </div>
    </div>
</footer>
