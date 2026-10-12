@include('clients.blocks.header')

@php
    $holdHours = (int) config('travela.hold_hours', 48);
    $cancelDays = \App\Support\CancelPolicy::MIN_DAYS;
    $img = fn($f) => asset('clients/assets/images/' . $f);
    $heroFile = file_exists(public_path('clients/assets/images/banner/banner-about.jpg'))
        ? 'banner/banner-about.jpg'
        : 'hero/hero.jpg';
@endphp

<main class="px-about">

    {{-- ===== HERO ===== --}}
    <section class="px-ahero">
        <img class="px-ahero__img" src="{{ $img($heroFile) }}" alt="" fetchpriority="high">
        <div class="px-ahero__shade" aria-hidden="true"></div>
        <span class="px-bubble px-bubble--1" aria-hidden="true"></span>
        <span class="px-bubble px-bubble--2" aria-hidden="true"></span>
        <div class="container px-ahero__in">
            <span class="px-eyebrow"><i class="fas fa-circle" aria-hidden="true"></i> Về chúng tôi</span>
            <h1>Dẫn lối bạn đến những <em>chuyến đi đáng nhớ</em></h1>
            <p>{{ config('site.name') }} là nền tảng đặt tour trực tuyến: lịch trình rõ ràng từng ngày, giá và số chỗ
                minh bạch,
                đặt chỗ ngay trên website.</p>
            <div class="px-ahero__cta">
                <a href="{{ route('tours') }}" class="px-btn px-btn--sand">Khám phá tour <i
                        class="fal fa-arrow-right"></i></a>
                <a href="{{ route('contact') }}" class="px-btn px-btn--glass">Nhắn cho chúng tôi</a>
            </div>
        </div>
        <svg class="px-wave" viewBox="0 0 1440 80" preserveAspectRatio="none" aria-hidden="true">
            <path d="M0 40 Q180 80 380 48 T760 44 T1130 28 T1440 48 V80 H0Z" />
        </svg>
    </section>

    {{-- ===== CHÚNG TÔI LÀ AI + SỐ LIỆU THẬT ===== --}}
    <section class="px-asec">
        <div class="container px-aintro">
            <div class="px-aintro__media" data-rv="left">
                <div class="px-collage">
                    <img class="px-collage__a" src="{{ $img('features/features-box.jpg') }}" alt=""
                        loading="lazy">
                    <img class="px-collage__b" src="{{ $img('features/features-two.jpg') }}" alt=""
                        loading="lazy">
                    <span class="px-collage__sun" aria-hidden="true"></span>
                    <div class="px-collage__badge"><i class="fal fa-shield-check" aria-hidden="true"></i>
                        <span><b>Số liệu thật</b><small>lấy trực tiếp từ hệ thống</small></span>
                    </div>
                </div>
            </div>

            <div class="px-aintro__text" data-rv="right">
                <span class="px-kicker">Chúng tôi là ai?</span>
                <h2>Đặt tour dễ hiểu, theo dõi dễ dàng</h2>
                <p>Bạn xem lịch trình từng ngày, bản đồ, hình ảnh và đánh giá của những khách đã đi, so sánh giá và số
                    chỗ còn lại, rồi đặt tour ngay trên website. Chúng tôi chỉ hiển thị thông tin có thật trong hệ
                    thống.</p>

                @php
                    $items = array_filter(
                        [
                            ['n' => $stats['tours'], 'l' => 'Tour đang mở đặt', 'ico' => 'fa-map-marked-alt'],
                            ['n' => $stats['destinations'], 'l' => 'Điểm đến', 'ico' => 'fa-umbrella-beach'],
                            ['n' => $stats['completed'], 'l' => 'Đơn đã hoàn tất', 'ico' => 'fa-suitcase-rolling'],
                            [
                                'n' => $stats['rating'],
                                'l' => 'Điểm đánh giá trung bình',
                                'ico' => 'fa-star',
                                'dec' => 1,
                                'show' => $stats['reviews'] > 0,
                            ],
                        ],
                        fn($i) => ($i['show'] ?? true) && $i['n'] > 0,
                    );
                @endphp
                @if (count($items))
                    <div class="px-stats">
                        @foreach ($items as $it)
                            <div class="px-stat">
                                <i class="fal {{ $it['ico'] }}" aria-hidden="true"></i>
                                <b data-count="{{ $it['n'] }}"
                                    data-decimals="{{ $it['dec'] ?? 0 }}">{{ $it['n'] }}</b>
                                <small>{{ $it['l'] }}</small>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </section>

    {{-- ===== VÌ SAO CHỌN ===== --}}
    <section class="px-asec px-asec--tint">
        <div class="container">
            <div class="px-ahead">
                <span class="px-kicker">Vì sao chọn chúng tôi</span>
                <h2>Rõ ràng từ lúc chọn đến lúc về</h2>
            </div>
            <div class="px-why">
                <article class="px-why__card">
                    <span><i class="fal fa-route" aria-hidden="true"></i></span>
                    <h3>Lịch trình từng ngày</h3>
                    <p>Xem trước lịch trình, bản đồ và video của chuyến đi trước khi quyết định đặt.</p>
                </article>
                <article class="px-why__card">
                    <span><i class="fal fa-calendar-check" aria-hidden="true"></i></span>
                    <h3>Giữ chỗ {{ $holdHours }} giờ</h3>
                    <p>Đặt chỗ trước, hoàn tất thanh toán trong {{ $holdHours }} giờ, không phải quyết định vội.</p>
                </article>
                <article class="px-why__card">
                    <span><i class="fal fa-undo" aria-hidden="true"></i></span>
                    <h3>Tự hủy linh hoạt</h3>
                    <p>Tự hủy ngay trong “Tour đã đặt” khi còn hơn {{ $cancelDays }} ngày trước ngày khởi hành.</p>
                </article>
                <article class="px-why__card">
                    <span><i class="fal fa-comments" aria-hidden="true"></i></span>
                    <h3>Hỗ trợ trực tiếp</h3>
                    <p>Nhắn ngay trên website, có câu trả lời nhanh cho các thắc mắc thường gặp.</p>
                </article>
            </div>
        </div>
    </section>

    {{-- ===== QUY TRÌNH ===== --}}
    <section class="px-asec">
        <div class="container">
            <div class="px-ahead">
                <span class="px-kicker">Quy trình đặt tour</span>
                <h2>Bốn bước là xong</h2>
            </div>
            <ol class="px-flow">
                <li><span>1</span><i class="fal fa-search" aria-hidden="true"></i><b>Chọn tour</b>
                    <p>Lọc theo miền, ngân sách, số ngày và đánh giá để tìm hành trình hợp với bạn.</p>
                </li>
                <li><span>2</span><i class="fal fa-images" aria-hidden="true"></i><b>Xem chi tiết</b>
                    <p>Đọc lịch trình, xem ảnh, video và nhận xét của những khách đã đi.</p>
                </li>
                <li><span>3</span><i class="fal fa-ticket-alt" aria-hidden="true"></i><b>Đặt chỗ</b>
                    <p>Điền thông tin, chọn số hành khách và mã giảm giá nếu có. Chỗ được giữ {{ $holdHours }} giờ.
                    </p>
                </li>
                <li><span>4</span><i class="fal fa-clipboard-check" aria-hidden="true"></i><b>Theo dõi đơn</b>
                    <p>Xem trạng thái ở “Tour đã đặt”, hủy khi còn hạn và đánh giá sau chuyến đi.</p>
                </li>
            </ol>
        </div>
    </section>

    {{-- ===== ĐIỂM ĐẾN NỔI BẬT (từ DB) ===== --}}
    @if ($destinations->count())
        <section class="px-asec px-asec--tint">
            <div class="container">
                <div class="px-ahead">
                    <span class="px-kicker">Điểm đến</span>
                    <h2>Những nơi có nhiều tour nhất</h2>
                </div>
                <div class="px-dest">
                    @foreach ($destinations as $d)
                        <a class="px-dest__card"
                            href="{{ route('search-voice-text', ['keyword' => $d->destination]) }}">
                            <img src="{{ \App\Support\TourImage::url($d->image ?: 'default.jpg', 700) }}"
                                alt="" loading="lazy">
                            <span class="px-dest__txt"><b>{{ $d->destination }}</b><small>{{ $d->total }}
                                    tour</small></span>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- ===== KÊU GỌI ===== --}}
    <section class="px-asec px-asec--last">
        <div class="container">
            <div class="px-cta" data-rv="zoom">
                <span class="px-cta__sun" aria-hidden="true"></span>
                <div>
                    <h2>Sẵn sàng cho chuyến đi tiếp theo?</h2>
                    <p>Chọn một hành trình, giữ chỗ trước và thanh toán sau.</p>
                </div>
                <div class="px-cta__btns">
                    <a href="{{ route('tours') }}" class="px-btn px-btn--sand">Xem các tour</a>
                    <a href="{{ route('contact') }}" class="px-btn px-btn--glass">Liên hệ</a>
                </div>
            </div>
        </div>
    </section>
</main>

@include('clients.blocks.footer')
