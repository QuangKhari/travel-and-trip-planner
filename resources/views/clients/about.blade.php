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

    <!-- =========================================================
         SECTION 6: FINAL CTA ("Cùng Travela khám phá Việt Nam...")
         Cảnh vịnh núi đá vôi màu nước + 2 thuyền gỗ truyền thống
         ========================================================= -->
    <section class="ta-final-cta">
        <!-- Lá màu nước rủ từ góc trái trên và trái dưới -->
        <svg class="ta-leaf ta-leaf--cta-left" viewBox="0 0 190 320" fill="none" aria-hidden="true">
            <path d="M-10,40 Q55,95 115,165" stroke="#0B7A75" stroke-width="2" opacity="0.5" />
            <path d="M35,75 C58,62 82,70 84,88 C62,96 42,88 35,75 Z" fill="#16A6A1" opacity="0.45" />
            <path d="M-5,295 C35,235 78,175 145,105" stroke="#0B7A75" stroke-width="2.2" opacity="0.55" />
            <path d="M38,242 C12,226 6,198 20,182 C40,192 48,218 38,242 Z" fill="#16A6A1" opacity="0.45" />
            <path d="M72,195 C46,178 42,152 56,136 C76,148 82,174 72,195 Z" fill="#2D6A4F" opacity="0.38" />
            <path d="M84,182 C112,172 132,152 126,134 C102,140 88,160 84,182 Z" fill="#52B788" opacity="0.42" />
        </svg>

        <!-- Toàn cảnh Vịnh Hạ Long Màu Nước (Núi đá vôi xanh lam nhạt + Thuyền gỗ truyền thống) -->
        <div class="ta-final-cta__scene" aria-hidden="true">
            <svg class="ta-final-cta__svg" viewBox="0 0 1440 460" preserveAspectRatio="xMidYMax slice">
                <defs>
                    <linearGradient id="taSkyWash" x1="0%" y1="0%" x2="0%" y2="100%">
                        <stop offset="0%" stop-color="#F4F7F4" />
                        <stop offset="55%" stop-color="#EBF4F3" />
                        <stop offset="100%" stop-color="#CBEAE6" />
                    </linearGradient>
                    <linearGradient id="taSeaWash" x1="0%" y1="0%" x2="0%" y2="100%">
                        <stop offset="0%" stop-color="#BCE5E0" stop-opacity="0.75" />
                        <stop offset="50%" stop-color="#8CD1CA" stop-opacity="0.85" />
                        <stop offset="100%" stop-color="#5BB8B0" stop-opacity="0.95" />
                    </linearGradient>
                    <linearGradient id="taKarstDist" x1="0%" y1="0%" x2="0%" y2="100%">
                        <stop offset="0%" stop-color="#9CC5C9" stop-opacity="0.45" />
                        <stop offset="100%" stop-color="#D9EFECE0" stop-opacity="0.1" />
                    </linearGradient>
                    <linearGradient id="taKarstMid" x1="0%" y1="0%" x2="0%" y2="100%">
                        <stop offset="0%" stop-color="#6BA6A8" stop-opacity="0.65" />
                        <stop offset="100%" stop-color="#B3DFDC" stop-opacity="0.25" />
                    </linearGradient>
                    <linearGradient id="taKarstNear" x1="0%" y1="0%" x2="0%" y2="100%">
                        <stop offset="0%" stop-color="#4A8B8C" stop-opacity="0.82" />
                        <stop offset="70%" stop-color="#76B5B3" stop-opacity="0.65" />
                        <stop offset="100%" stop-color="#A8DDD8" stop-opacity="0.3" />
                    </linearGradient>
                </defs>

                <!-- Nền trời màu nước -->
                <rect width="1440" height="460" fill="url(#taSkyWash)" />

                <!-- Đàn chim hải âu bay nhẹ -->
                <g class="ta-anim-birds">
                    <path d="M780,95 Q790,83 800,93 Q810,83 820,95" stroke="#4A7C7D" stroke-width="1.8"
                        fill="none" opacity="0.55" />
                    <path d="M845,75 Q853,65 861,73 Q869,65 877,75" stroke="#4A7C7D" stroke-width="1.5"
                        fill="none" opacity="0.45" />
                    <path d="M905,102 Q912,94 919,100 Q926,94 933,102" stroke="#4A7C7D" stroke-width="1.4"
                        fill="none" opacity="0.4" />
                </g>

                <!-- Lớp 1: Dãy núi đá vôi mờ phía xa (Distant Karsts) -->
                <path
                    d="M460,320 C510,240 560,220 615,295 C655,210 725,195 785,290 C835,230 890,235 940,305 C995,215 1065,205 1125,295 C1185,180 1265,175 1335,290 C1375,245 1415,250 1440,295 L1440,340 L460,340 Z"
                    fill="url(#taKarstDist)" />

                <!-- Lớp 2: Các đảo đá vôi tầm trung giống hệt bố cục ảnh mẫu -->
                <!-- Đảo đá bên trái-giữa -->
                <g class="ta-karst-group">
                    <path d="M830,325 C845,255 875,215 910,218 C945,220 968,265 985,325 Z" fill="url(#taKarstMid)" />
                    <!-- Thảm thực vật xanh trên đỉnh núi đá -->
                    <path d="M868,242 C885,222 918,220 936,240 C950,255 935,268 912,262 C890,268 860,258 868,242 Z"
                        fill="#3D7A68" opacity="0.65" />
                    <path d="M852,278 C872,268 898,272 912,284 C892,292 865,290 852,278 Z" fill="#4D8B76"
                        opacity="0.55" />
                </g>

                <!-- Cụm núi đá lớn bên phải -->
                <g class="ta-karst-group">
                    <path d="M1110,332 C1135,235 1175,165 1225,168 C1272,170 1305,235 1335,332 Z"
                        fill="url(#taKarstNear)" />
                    <path d="M1285,332 C1305,260 1338,215 1372,218 C1405,220 1425,268 1440,332 Z"
                        fill="url(#taKarstMid)" />
                    <!-- Cây xanh phủ trên đỉnh và sườn núi đá vôi -->
                    <path
                        d="M1165,205 C1190,172 1242,168 1268,202 C1282,220 1260,235 1228,228 C1195,235 1152,224 1165,205 Z"
                        fill="#2F6B5A" opacity="0.72" />
                    <path d="M1140,255 C1168,238 1210,242 1232,262 C1198,272 1158,270 1140,255 Z" fill="#3D7A68"
                        opacity="0.6" />
                    <path d="M1325,248 C1348,225 1385,225 1405,246 C1382,258 1345,258 1325,248 Z" fill="#3D7A68"
                        opacity="0.62" />
                    <!-- Vách đá sáng tối tự nhiên -->
                    <path d="M1215,225 L1210,315 M1238,235 L1242,310 M905,260 L902,315" stroke="#EBF4F3"
                        stroke-width="2" stroke-linecap="round" opacity="0.45" />
                </g>

                <!-- Dải đảo thấp phía chân trời bên trái -->
                <path d="M480,335 Q560,290 650,335 Q720,305 795,335 Z" fill="url(#taKarstMid)" opacity="0.7" />

                <!-- Mặt biển xanh ngọc dịu mát -->
                <path d="M0,335 C360,322 720,342 1080,328 C1260,322 1360,330 1440,334 L1440,460 L0,460 Z"
                    fill="url(#taSeaWash)" />

                <!-- Các gợn sóng nước lấp lánh (Chuyển động êm) -->
                <g class="ta-anim-waves">
                    <path d="M420,355 Q540,348 660,355 T900,355 T1140,355 T1380,355" stroke="#FFFFFF" stroke-width="2"
                        stroke-linecap="round" fill="none" opacity="0.65" />
                    <path d="M520,378 Q660,370 800,378 T1080,378 T1360,378" stroke="#E9F7F5" stroke-width="1.6"
                        stroke-linecap="round" fill="none" opacity="0.55" />
                    <path d="M350,402 Q510,395 670,402 T990,402 T1310,402" stroke="#0B7A75" stroke-width="1.4"
                        stroke-linecap="round" fill="none" opacity="0.25" />
                    <!-- Chấm nắng lấp lánh trên mặt vịnh -->
                    <circle cx="780" cy="368" r="2.2" fill="#FFF8D6" opacity="0.8" />
                    <circle cx="840" cy="385" r="1.8" fill="#FFF8D6" opacity="0.7" />
                    <circle cx="960" cy="362" r="2.5" fill="#FFF8D6" opacity="0.85" />
                    <circle cx="1050" cy="392" r="2" fill="#FFF8D6" opacity="0.75" />
                    <circle cx="1120" cy="372" r="2.2" fill="#FFF8D6" opacity="0.8" />
                </g>

                <!-- THUYỀN 1: Thuyền gỗ nhỏ phía xa (Giữa vịnh - đúng theo ảnh mẫu) -->
                <g class="ta-boat ta-boat--distant">
                    <!-- Bóng nước dưới thuyền -->
                    <ellipse cx="705" cy="366" rx="38" ry="4" fill="#2B6CB0"
                        opacity="0.22" />
                    <!-- Thân thuyền gỗ sáng -->
                    <path d="M672,354 L738,350 L728,364 L680,364 Z" fill="#C89D7C" />
                    <path d="M676,358 L734,355 L728,364 L680,364 Z" fill="#7F5539" />
                    <!-- Khoang cabin trắng-xanh -->
                    <rect x="686" y="340" width="34" height="13" rx="2" fill="#F8F9FA"
                        stroke="#6BA6A8" stroke-width="1" />
                    <rect x="691" y="344" width="6" height="5" fill="#4A8B8C" />
                    <rect x="701" y="344" width="6" height="5" fill="#4A8B8C" />
                    <rect x="711" y="344" width="6" height="5" fill="#4A8B8C" />
                    <!-- Mái cabin & Cột cờ -->
                    <path d="M682,340 L724,338 L720,334 L686,335 Z" fill="#8D99AE" />
                    <line x1="704" y1="316" x2="704" y2="338" stroke="#7F5539"
                        stroke-width="1.4" />
                    <line x1="704" y1="318" x2="725" y2="338" stroke="#7F5539"
                        stroke-width="0.8" opacity="0.6" />
                </g>

                <!-- THUYỀN 2: Thuyền gỗ du lịch truyền thống bên phải (Đúng theo ảnh mẫu) -->
                <g class="ta-boat ta-boat--foreground">
                    <!-- Bóng phản chiếu dưới mặt nước -->
                    <ellipse cx="1235" cy="384" rx="68" ry="6" fill="#1D4E4E"
                        opacity="0.28" />
                    <path d="M1180,388 Q1235,394 1290,386" stroke="#FFFFFF" stroke-width="1.8" stroke-linecap="round"
                        fill="none" opacity="0.7" />

                    <!-- Cột buồm & Dây neo -->
                    <line x1="1218" y1="292" x2="1218" y2="342" stroke="#5C3A21"
                        stroke-width="2.2" />
                    <line x1="1256" y1="304" x2="1256" y2="342" stroke="#5C3A21"
                        stroke-width="1.8" />
                    <line x1="1218" y1="296" x2="1175" y2="352" stroke="#5C3A21"
                        stroke-width="1" opacity="0.55" />
                    <line x1="1218" y1="296" x2="1285" y2="346" stroke="#5C3A21"
                        stroke-width="1" opacity="0.55" />
                    <!-- Cờ nhỏ trên đỉnh cột -->
                    <path d="M1218,292 L1232,296 L1218,300 Z" fill="#E07A5F" />

                    <!-- Cabin gỗ truyền thống -->
                    <path d="M1194,354 L1198,334 L1272,332 L1276,352 Z" fill="#E6CCB2" stroke="#7F5539"
                        stroke-width="1.2" />
                    <!-- Mái cabin cong nhẹ -->
                    <path d="M1190,334 Q1235,328 1280,332 L1275,326 Q1235,322 1195,328 Z" fill="#9C6644" />
                    <!-- Ô cửa sổ ấm áp -->
                    <rect x="1205" y="338" width="9" height="8" rx="1" fill="#3D5A5B" />
                    <rect x="1220" y="338" width="9" height="8" rx="1" fill="#FFE599" />
                    <rect x="1235" y="337" width="9" height="8" rx="1" fill="#FFE599" />
                    <rect x="1250" y="337" width="9" height="8" rx="1" fill="#3D5A5B" />

                    <!-- Thân thuyền gỗ mộc (Mũi thuyền vươn cao bên trái) -->
                    <path d="M1162,348 Q1225,358 1304,344 L1288,374 Q1232,382 1178,372 Z" fill="#B07D52" />
                    <path d="M1170,360 Q1230,368 1296,358 L1288,374 Q1232,382 1178,372 Z" fill="#6C462B" />
                    <!-- Đường viền mạn thuyền -->
                    <path d="M1165,352 Q1228,361 1300,348" stroke="#F4E3D3" stroke-width="1.6" fill="none"
                        opacity="0.8" />
                </g>
            </svg>
        </div>

        <!-- Nội dung chữ bên trái -->
        <div class="container ta-final-cta__container">
            <div class="ta-final-cta__content">
                <h2 class="ta-final-cta__title">
                    Cùng Travel khám phá Việt Nam theo cách của bạn
                </h2>
                <p class="ta-final-cta__desc">
                    Hãy để chúng tôi đồng hành cùng bạn trên những hành trình đầy cảm hứng và trải nghiệm đáng nhớ.
                </p>
                <a href="{{ route('tours') }}" class="ta-btn ta-btn--teal-solid">
                    <span>Xem các tour nổi bật</span>
                    <i class="fas fa-arrow-right"></i>
                </a>
            </div>
        </div>
    </section>

</main>

@include('clients.blocks.footer')
