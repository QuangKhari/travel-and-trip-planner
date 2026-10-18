@include('clients.blocks.header')

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link
    href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700&family=Playfair+Display:wght@500;600;700&display=swap"
    rel="stylesheet">
<link rel="stylesheet" href="{{ asset('clients/assets/css/about-watercolor.css') }}">

<main class="travel-about-page">

    <!-- =========================================================
         SECTION 1: HERO BANNER ("Travela - Đồng hành cùng bạn...")
         ========================================================= -->
    <section class="ta-hero">
        <div class="ta-hero__bg"
            style="background-image: url('{{ asset('clients/assets/images/about/hero-about.jpg') }}');">
        </div>
        <div class="ta-hero__Mist"></div>

        <!-- Lá màu nước góc trái & phải -->
        <svg class="ta-leaf ta-leaf--top-left" viewBox="0 0 180 260" fill="none" aria-hidden="true">
            <path d="M-10,240 C35,180 75,110 140,20" stroke="#0B7A75" stroke-width="2.2" opacity="0.55" />
            <path d="M35,185 C10,168 4,142 18,125 C38,136 46,162 35,185 Z" fill="#16A6A1" opacity="0.45" />
            <path d="M68,138 C42,120 38,94 52,78 C72,90 78,116 68,138 Z" fill="#0B7A75" opacity="0.35" />
            <path d="M78,125 C106,116 128,95 122,76 C98,82 84,102 78,125 Z" fill="#52B788" opacity="0.4" />
            <path d="M108,82 C88,64 84,42 98,28 C115,40 118,62 108,82 Z" fill="#74C69D" opacity="0.45" />
        </svg>

        <svg class="ta-leaf ta-leaf--bottom-right" viewBox="0 0 200 220" fill="none" aria-hidden="true">
            <path d="M190,10 C145,75 105,135 35,205" stroke="#0B7A75" stroke-width="2.2" opacity="0.6" />
            <path d="M145,68 C172,82 182,108 168,124 C146,114 136,90 145,68 Z" fill="#16A6A1" opacity="0.5" />
            <path d="M110,115 C136,132 142,158 128,174 C108,162 100,138 110,115 Z" fill="#2D6A4F" opacity="0.45" />
            <path d="M98,128 C70,138 48,158 54,178 C78,172 92,152 98,128 Z" fill="#52B788" opacity="0.45" />
        </svg>

        <div class="container ta-hero__container">
            <div class="ta-hero__content">
                <span class="ta-eyebrow">VỀ CHÚNG TÔI</span>
                <h1 class="ta-hero__title">
                    Travela – Đồng hành cùng bạn khám phá Việt Nam
                </h1>
                <p class="ta-hero__desc">
                    Chúng tôi tin rằng mỗi chuyến đi không chỉ là điểm đến, mà còn là những trải nghiệm, cảm xúc và câu
                    chuyện đáng nhớ.
                </p>
                <a href="{{ route('tours') }}" class="ta-btn ta-btn--white">
                    <span>Khám phá ngay</span>
                    <i class="fas fa-arrow-right"></i>
                </a>
            </div>

            <!-- Chữ viết tay góc trên phải -->
            <div class="ta-hero__handnote" aria-hidden="true">
                <span>Việt Nam<br>đẹp lắm!</span>
                <svg viewBox="0 0 120 30" fill="none">
                    <path d="M5,20 Q60,5 115,15 M25,26 Q65,16 95,22" stroke="#0B7A75" stroke-width="2"
                        stroke-linecap="round" opacity="0.6" />
                </svg>
            </div>
        </div>

        <!-- Viền cọ màu nước cắt đáy Hero -->
        <div class="ta-brush-divider ta-brush-divider--bottom" aria-hidden="true">
            <svg viewBox="0 0 1440 90" preserveAspectRatio="none">
                <path d="M0,45 C180,85 340,15 540,48 C740,80 920,20 1140,52 C1280,72 1380,40 1440,30 L1440,90 L0,90 Z"
                    fill="#F8F6F0" opacity="0.65"></path>
                <path d="M0,62 C220,28 460,82 720,54 C980,26 1220,76 1440,48 L1440,90 L0,90 Z" fill="#F8F6F0"></path>
            </svg>
        </div>
    </section>

    <!-- =========================================================
         SECTION 2: CÂU CHUYỆN CỦA CHÚNG TÔI (3 Khung Ảnh Polaroid)
         ========================================================= -->
    <section class="ta-story">
        <div class="container">
            <div class="ta-story__grid">
                <!-- Cột trái: Nội dung câu chuyện -->
                <div class="ta-story__text">
                    <span class="ta-eyebrow">CÂU CHUYỆN CỦA CHÚNG TÔI</span>
                    <h2 class="ta-section-title">Từ một ý tưởng nhỏ đến hành trình lớn</h2>
                    <p>
                        Travela ra đời từ niềm đam mê khám phá Việt Nam của những người trẻ yêu du lịch. Chúng tôi muốn
                        tạo ra một nền tảng giúp bạn dễ dàng tìm kiếm, lên kế hoạch và trải nghiệm những chuyến đi tuyệt
                        vời nhất.
                    </p>
                    <p>
                        Không chỉ là đặt tour, Travela còn là <strong>người bạn đồng hành</strong>, luôn mang đến những
                        gợi ý chân thực, hữu ích và truyền cảm hứng để bạn khám phá vẻ đẹp muôn màu của đất nước.
                    </p>
                    <div class="ta-story__signature">
                        <span>Đội ngũ<br>Travel</span>
                        <i class="fas fa-heart"></i>
                    </div>
                </div>

                <!-- Cột phải: Collage 3 ảnh Polaroid + La bàn + Chữ viết tay -->
                <div class="ta-story__collage">
                    <div class="ta-story__wash" aria-hidden="true"></div>

                    <div class="ta-story__note" aria-hidden="true">
                        Khám phá<br>Trải nghiệm<br>Kết nối
                    </div>

                    <!-- Ảnh Polaroid 1 (Lớn - Vịnh Hạ Long) -->
                    <div class="ta-polaroid ta-polaroid--main">
                        <img src="{{ asset('clients/assets/images/about/hero-about.jpg') }}" alt="Vịnh Hạ Long">
                    </div>

                    <!-- Ảnh Polaroid 2 (Nhỏ phải - Du khách ngắm cảnh) -->
                    <div class="ta-polaroid ta-polaroid--right">
                        <img src="{{ asset('clients/assets/images/about/story-2.jpg') }}" alt="Trải nghiệm du lịch">
                    </div>

                    <!-- Ảnh Polaroid 3 (Ngang dưới - Ruộng bậc thang vùng cao) -->
                    <div class="ta-polaroid ta-polaroid--bottom">
                        <img src="{{ asset('clients/assets/images/about/story-3.jpg') }}" alt="Khám phá miền núi">
                    </div>

                    <!-- Dấu mộc La bàn cổ điển (Ink Sketch) -->
                    <svg class="ta-compass-stamp" viewBox="0 0 120 120" fill="none" aria-hidden="true">
                        <circle cx="60" cy="60" r="52" stroke="#5C5346" stroke-width="1.5"
                            stroke-dasharray="4 3" opacity="0.55" />
                        <circle cx="60" cy="60" r="44" stroke="#5C5346" stroke-width="1.2"
                            opacity="0.65" />
                        <circle cx="60" cy="60" r="34" stroke="#5C5346" stroke-width="1"
                            opacity="0.45" />
                        <polygon points="60,14 66,54 60,48 54,54" fill="#5C5346" opacity="0.7" />
                        <polygon points="60,106 66,66 60,72 54,66" fill="#8B7E6A" opacity="0.5" />
                        <polygon points="14,60 54,54 48,60 54,66" fill="#8B7E6A" opacity="0.5" />
                        <polygon points="106,60 66,54 72,60 66,66" fill="#5C5346" opacity="0.7" />
                        <circle cx="60" cy="60" r="4" fill="#5C5346" />
                    </svg>

                    <!-- Nhánh lá màu nước điểm xuyết quanh ảnh -->
                    <svg class="ta-collage-leaf" viewBox="0 0 120 120" fill="none" aria-hidden="true">
                        <path d="M15,95 Q60,55 105,20" stroke="#0B7A75" stroke-width="2" opacity="0.55" />
                        <path d="M45,70 C28,58 26,38 38,28 C52,38 56,56 45,70 Z" fill="#16A6A1" opacity="0.45" />
                        <path d="M72,52 C88,58 102,48 100,34 C84,32 74,40 72,52 Z" fill="#52B788" opacity="0.45" />
                    </svg>
                </div>
            </div>
        </div>
    </section>

    <!-- Chỉ hiển thị các chỉ số lấy từ dữ liệu hệ thống -->
    @if (
        !empty($stats) &&
            (($stats['tours'] ?? 0) > 0 ||
                ($stats['destinations'] ?? 0) > 0 ||
                ($stats['completed'] ?? 0) > 0 ||
                ($stats['reviews'] ?? 0) > 0))
        <section class="ta-proof" aria-label="Thông tin Travela">
            <div class="container ta-proof__grid">
                @if (($stats['tours'] ?? 0) > 0)
                    <div class="ta-proof__item"><strong>{{ number_format($stats['tours']) }}+</strong><span>tour đang
                            mở</span></div>
                @endif
                @if (($stats['destinations'] ?? 0) > 0)
                    <div class="ta-proof__item"><strong>{{ number_format($stats['destinations']) }}+</strong><span>điểm
                            đến trong tour</span></div>
                @endif
                @if (($stats['completed'] ?? 0) > 0)
                    <div class="ta-proof__item"><strong>{{ number_format($stats['completed']) }}+</strong><span>đơn đặt
                            hoàn tất</span></div>
                @endif
                @if (($stats['reviews'] ?? 0) > 0)
                    <div class="ta-proof__item"><strong>{{ number_format($stats['reviews']) }}</strong><span>đánh
                            giá</span></div>
                @endif
                @if (($stats['rating'] ?? 0) > 0)
                    <div class="ta-proof__item"><strong>{{ number_format($stats['rating'], 1) }}<span
                                class="ta-proof__star">★</span></strong><span>điểm trung bình</span></div>
                @endif
            </div>
        </section>
    @endif

    <!-- =========================================================
         SECTION 4: GIÁ TRỊ CỐT LÕI ("Điều làm nên Travela" - 5 Cột)
         ========================================================= -->
    <section class="ta-values">
        <div class="container">
            <span class="ta-eyebrow">GIÁ TRỊ CỐT LÕI</span>
            <h2 class="ta-section-title">Điều làm nên Travel</h2>

            <div class="ta-values__grid">
                <!-- Card 1 -->
                <div class="ta-value-card">
                    <div class="ta-value-card__icon ta-value-card__icon--teal">
                        <i class="fas fa-leaf"></i>
                    </div>
                    <h3>Trải nghiệm thật</h3>
                    <p>Chúng tôi mang đến những hành trình chân thực, khác biệt và ý nghĩa.</p>
                </div>

                <!-- Card 2 -->
                <div class="ta-value-card">
                    <div class="ta-value-card__icon ta-value-card__icon--rose">
                        <i class="fas fa-heart"></i>
                    </div>
                    <h3>Khách hàng là trung tâm</h3>
                    <p>Mọi quyết định đều hướng đến sự hài lòng của bạn.</p>
                </div>

                <!-- Card 3 -->
                <div class="ta-value-card">
                    <div class="ta-value-card__icon ta-value-card__icon--aqua">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <h3>An toàn &amp; tin cậy</h3>
                    <p>Đảm bảo hành trình an toàn, minh bạch và chuyên nghiệp.</p>
                </div>

                <!-- Card 4 -->
                <div class="ta-value-card">
                    <div class="ta-value-card__icon ta-value-card__icon--cyan">
                        <i class="fas fa-users"></i>
                    </div>
                    <h3>Đội ngũ tận tâm</h3>
                    <p>Đội ngũ giàu kinh nghiệm, luôn sẵn sàng hỗ trợ 24/7.</p>
                </div>

                <!-- Card 5 -->
                <div class="ta-value-card">
                    <div class="ta-value-card__icon ta-value-card__icon--gold">
                        <i class="fas fa-star"></i>
                    </div>
                    <h3>Phát triển bền vững</h3>
                    <p>Cùng chung tay bảo vệ thiên nhiên và gìn giữ bản sắc Việt.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- =========================================================
         SECTION 5: BANNER CẢM HỨNG ("Không chỉ là một chuyến đi...")
         ========================================================= -->
    <section class="ta-journey-banner">
        <!-- Viền cọ màu nước phía trên -->
        <div class="ta-brush-divider ta-brush-divider--top" aria-hidden="true">
            <svg viewBox="0 0 1440 75" preserveAspectRatio="none">
                <path d="M0,0 L1440,0 L1440,42 C1220,72 960,18 720,48 C480,78 220,24 0,58 Z" fill="#F8F6F0"></path>
            </svg>
        </div>

        <!-- Ảnh nền Panorama núi rừng & ruộng bậc thang Việt Nam -->
        <div class="ta-journey-banner__bg"
            style="background-image: url('{{ asset('clients/assets/images/about/testimonial-landscape.jpg') }}');">
        </div>
        <div class="ta-journey-banner__overlay"></div>

        <!-- Máy bay giấy nét vẽ tay -->
        <svg class="ta-paper-plane" viewBox="0 0 180 90" fill="none" aria-hidden="true">
            <path d="M10,75 Q75,45 135,28" stroke="#FFFFFF" stroke-width="1.5" stroke-dasharray="4 4"
                opacity="0.65" />
            <path d="M132,26 L168,14 L148,46 L142,34 Z" stroke="#FFFFFF" stroke-width="1.8"
                fill="rgba(255,255,255,0.2)" />
        </svg>

        <div class="container ta-journey-banner__container">
            <!-- Bên trái: Chữ viết tay "Không chỉ là một chuyến đi..." -->
            <div class="ta-journey-banner__left">
                <h2 class="ta-journey-banner__quote-title">
                    Không chỉ là<br>một chuyến đi...
                </h2>
                <p class="ta-journey-banner__quote-sub">
                    ...mà là hành trình<br>để tìm thấy chính mình
                </p>
                <a href="{{ route('tours') }}" class="ta-btn ta-btn--teal-pill">
                    <span>Khám phá các tour</span>
                    <i class="fas fa-arrow-right"></i>
                </a>
            </div>

            <!-- Đánh giá lấy từ cơ sở dữ liệu; không tự tạo tên hoặc lời chứng thực -->
            <div class="ta-journey-banner__testimonial">
                @if (!empty($review) && !empty($review->comment))
                    <span class="ta-quote-mark" aria-hidden="true">“</span>
                    <p class="ta-testimonial__text">{{ $review->comment }}</p>
                    <div class="ta-testimonial__author">
                        <span class="ta-testimonial__initial"
                            aria-hidden="true">{{ mb_substr(trim($review->fullName ?? 'K'), 0, 1) }}</span>
                        <div>
                            <strong>{{ $review->fullName ?: 'Khách hàng Travela' }}</strong>
                            <span>Đánh giá của khách hàng
                                @if (isset($review->rating) && is_numeric($review->rating))
                                    <em class="ta-stars"
                                        aria-label="{{ (int) $review->rating }} trên 5 sao">{{ str_repeat('★', max(0, min(5, (int) $review->rating))) }}</em>
                                @endif
                            </span>
                        </div>
                    </div>
                @else
                    <span class="ta-eyebrow ta-eyebrow--light">TRẢI NGHIỆM CÙNG TRAVEL</span>
                    <h3 class="ta-testimonial__empty-title">Mỗi hành trình mở ra một câu chuyện mới.</h3>
                    <p class="ta-testimonial__text">Khám phá các tour đang có và chọn hành trình phù hợp với bạn.</p>
                    <a href="{{ route('tours') }}" class="ta-btn ta-btn--teal-pill">Khám phá tour <i
                            class="fas fa-arrow-right"></i></a>
                @endif
            </div>
        </div>

        <!-- Viền cọ màu nước phía dưới nối liền sang Section 5 -->
        <div class="ta-brush-divider ta-brush-divider--bottom" aria-hidden="true">
            <svg viewBox="0 0 1440 85" preserveAspectRatio="none">
                <path d="M0,38 C260,82 520,18 820,52 C1080,80 1280,32 1440,48 L1440,85 L0,85 Z" fill="#F4F7F4"></path>
            </svg>
        </div>
    </section>

    {{-- SECTION 6: CTA cuối trang (dùng chung partial với trang Tour) --}}
    @include('clients.partials.tour-cta-scene', [
        'ctaText' => 'Chọn tour có sẵn hoặc tự lên lịch trình với Trip Planner.',
    ])

</main>

@include('clients.blocks.footer')
