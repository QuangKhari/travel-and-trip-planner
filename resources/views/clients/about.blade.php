@include('clients.blocks.header')

@php
    $holdHours = (int) config('travela.hold_hours', 48);
    $cancelDays = \App\Support\CancelPolicy::MIN_DAYS;
    $siteName = config('site.name');

    $base = 'clients/assets/images/';
    $art = fn($f) => asset($base . 'illustrations/' . $f);
    // Tranh màu nước mới (thư mục images/watercolor/)
    $wc = fn($f) => asset($base . 'watercolor/' . $f);
    // Có ảnh thật trong images/about/ thì dùng; chưa có thì dùng tranh minh họa. Trả về [url, làAnhThat]
    $pick = function (string $photo, string $fallback) use ($base, $art) {
        return file_exists(public_path($base . 'about/' . $photo))
            ? [asset($base . 'about/' . $photo), true]
            : [$art($fallback), false];
    };
    [$heroSrc, $heroPhoto] = $pick('hero-about.jpg', 'scene-bay-wide.svg');
    [$s1] = $pick('story-1.jpg', 'scene-bay.svg');
    [$s2] = $pick('story-2.jpg', 'scene-island.svg');
    [$s3] = $pick('story-3.jpg', 'scene-terrace.svg');
    [$scenicSrc] = $pick('testimonial-landscape.jpg', 'scene-terrace-wide.svg');

    $values = [
        [
            'fa-leaf',
            '#d3efe9',
            '#087f7b',
            'Trải nghiệm thật',
            'Chúng tôi mang đến những hành trình chân thực, khác biệt và ý nghĩa.',
        ],
        [
            'fa-heart',
            '#fbd9df',
            '#d9455b',
            'Khách hàng là trung tâm',
            'Mọi quyết định đều hướng đến sự hài lòng của bạn.',
        ],
        [
            'fa-shield-alt',
            '#d3e7f5',
            '#1d6fa5',
            'An toàn & tin cậy',
            "Giá và số chỗ minh bạch, giữ chỗ {$holdHours} giờ, tự hủy khi còn hơn {$cancelDays} ngày.",
        ],
        [
            'fa-users',
            '#d3efe9',
            '#087f7b',
            'Đội ngũ tận tâm',
            'Nhắn ngay trên website, có câu trả lời nhanh cho các thắc mắc thường gặp.',
        ],
        ['fa-star', '#fbe7bd', '#b97a0c', 'Phát triển bền vững', 'Cùng chung tay gìn giữ và lan tỏa bản sắc Việt.'],
    ];
    $statItems = array_filter(
        [
            ['tours', 'tour đang mở bán'],
            ['destinations', 'điểm đến'],
            ['completed', 'chuyến đã hoàn thành'],
            ['reviews', 'đánh giá từ khách'],
        ],
        fn($i) => ($stats[$i[0]] ?? 0) > 0,
    );
    $stars = $review ? max(0, min(5, (int) round($review->rating))) : 0;
    $check = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12.5l5 5L20 6.5"/></svg>';
@endphp

<main class="tvp tvf" id="tvpAbout">
    <a class="tvf-skip" href="#tvfStory">Bỏ qua, tới nội dung chính</a>

    {{-- ===== 1. HERO ===== --}}
    <section class="tvf-hero" aria-labelledby="tvfH1">
        <img class="tvf-deco tvf-hero__wash" src="{{ $wc('wc-wash-teal.svg') }}" alt="" aria-hidden="true"
            width="900" height="620">
        <div class="tvf-hero__photo">
            <img src="{{ $heroSrc }}" alt="" width="1200" height="800" fetchpriority="high">
        </div>
        <img class="tvf-deco tvf-leaf" src="{{ $wc('wc-leaf-a.svg') }}" alt="" aria-hidden="true" width="560"
            height="640" style="--r:6deg;left:-70px;bottom:-40px;width:250px">
        <img class="tvf-deco tvf-leaf tvf-leaf--b" src="{{ $wc('wc-leaf-c.svg') }}" alt="" aria-hidden="true"
            width="560" height="640" style="--r:-8deg;right:-60px;bottom:-60px;width:260px;transform:scaleX(-1)">
        <div class="tvf-slogan tvf-script" aria-hidden="true">Việt Nam<br>đẹp lắm!</div>

        <div class="tvp-wrap tvf-hero__in">
            <div class="tvf-hero__copy">
                <span class="tvp-eyebrow">Về chúng tôi</span>
                <h1 id="tvfH1">{{ $siteName }} - Đồng hành cùng bạn <span class="tvf-hl">khám phá Việt
                        Nam!</span></h1>
                <p>Chúng tôi tin rằng mỗi chuyến đi không chỉ là điểm đến, mà còn là những trải nghiệm, cảm xúc và câu
                    chuyện đáng nhớ.</p>
                <div class="tvf-actions">
                    <a class="tvp-btn tvp-btn--teal" href="{{ route('tours') }}">Khám phá ngay
                        <svg class="tvp-ic" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M5 12h14M13 6l6 6-6 6" />
                        </svg></a>
                    <a class="tvp-btn tvp-btn--ghost" href="{{ route('destination') }}">Xem điểm đến</a>
                </div>
                <ul class="tvf-trust" aria-label="Cam kết của {{ $siteName }}">
                    <li>{!! $check !!} Giá minh bạch</li>
                    <li>{!! $check !!} Giữ chỗ {{ $holdHours }} giờ</li>
                    <li>{!! $check !!} Hỗ trợ ngay trên website</li>
                </ul>
            </div>
        </div>
        @unless ($heroPhoto)
            <img class="tvp-girl--hero" src="{{ $art('girl-rock.svg') }}" alt="" aria-hidden="true">
        @endunless
    </section>

    {{-- ===== 2. CÂU CHUYỆN ===== --}}
    <section class="tvp-story" id="tvfStory" aria-labelledby="tvfStoryH">
        <img class="tvf-deco tvf-leaf tvf-leaf--b" src="{{ $wc('wc-leaf-b.svg') }}" alt="" aria-hidden="true"
            width="560" height="640" loading="lazy"
            style="--r:-6deg;right:-70px;bottom:-30px;width:230px;transform:scaleX(-1)">
        <div class="tvp-wrap">
            <div class="tvf-rv">
                <span class="tvp-eyebrow">Câu chuyện của chúng tôi</span>
                <h2 id="tvfStoryH"><span class="tvf-underline">Từ một ý tưởng nhỏ<br>đến hành trình lớn</span></h2>
                <p>{{ $siteName }} ra đời từ niềm đam mê khám phá Việt Nam của những người trẻ yêu du lịch. Chúng
                    tôi
                    muốn tạo ra một nền tảng giúp bạn dễ dàng tìm kiếm, lên kế hoạch và trải nghiệm những chuyến đi
                    tuyệt vời nhất.</p>
                <p>Không chỉ là đặt tour, {{ $siteName }} còn là người bạn đồng hành, luôn mang đến những gì chân
                    thực, hữu ích và truyền cảm hứng để bạn khám phá vẻ đẹp muôn màu của đất nước.</p>
                <div class="tvp-sign tvp-script">Đội ngũ {{ $siteName }}
                    <svg width="22" height="20" viewBox="0 0 24 22" aria-hidden="true">
                        <path d="M12 21S1 14 1 7a5.5 5.5 0 0111-2 5.5 5.5 0 0111 2c0 7-11 14-11 14z" fill="#16a6a1" />
                    </svg>
                </div>
            </div>
            <div class="tvp-collage tvf-rv" style="--d:.12s">
                <div class="tvp-pola"><img src="{{ $s1 }}" alt="" width="450" height="550"
                        loading="lazy"></div>
                <div class="tvp-pola"><img src="{{ $s2 }}" alt="" width="450" height="550"
                        loading="lazy"></div>
                <div class="tvp-pola"><img src="{{ $s3 }}" alt="" width="450" height="550"
                        loading="lazy"></div>
                <div class="tvp-hand tvp-script" aria-hidden="true">Khám phá<br>Trải nghiệm<br>Kết nối</div>
                <img class="tvp-compass" src="{{ $art('compass.svg') }}" alt="" aria-hidden="true">
            </div>
        </div>
    </section>

    {{-- Số liệu thật từ DB: ô nào bằng 0 sẽ tự ẩn --}}
    @if (count($statItems))
        <section class="tvp-stats" aria-label="Số liệu của {{ $siteName }}">
            <ul>
                @foreach ($statItems as [$key, $label])
                    <li class="tvf-rv"><b
                            data-count="{{ $stats[$key] }}">{{ number_format($stats[$key]) }}</b><span>{{ $label }}</span>
                    </li>
                @endforeach
                @if (($stats['rating'] ?? 0) > 0)
                    <li class="tvf-rv"><b>{{ number_format($stats['rating'], 1) }}/5</b><span>điểm đánh giá trung
                            bình</span></li>
                @endif
            </ul>
        </section>
    @endif

    {{-- ===== 3. GIÁ TRỊ CỐT LÕI ===== --}}
    <section class="tvp-values" aria-labelledby="tvfValH">
        <div class="tvp-wrap">
            <span class="tvp-eyebrow">Giá trị cốt lõi</span>
            <h2 id="tvfValH">Điều làm nên {{ $siteName }}</h2>
            <div class="tvp-vgrid">
                @foreach ($values as $n => [$ico, $bg, $fg, $name, $text])
                    <div class="tvf-val tvf-rv" style="--d:{{ $n * 0.08 }}s">
                        <div class="tvf-val__ic" style="--bg:{{ $bg }};--c:{{ $fg }}"><i
                                class="fas {{ $ico }}" aria-hidden="true"></i></div>
                        <h3>{{ $name }}</h3>
                        <p>{{ $text }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ===== 4. KHÔNG CHỈ LÀ MỘT CHUYẾN ĐI ===== --}}
    <section class="tvf-scenic" aria-labelledby="tvfScenicH">
        <div class="tvf-scenic__photo"><img src="{{ $scenicSrc }}" alt="" width="1600" height="700"
                loading="lazy"></div>
        <div class="tvf-scenic__veil" aria-hidden="true"></div>
        <div class="tvf-scenic__grain" aria-hidden="true"></div>
        <img class="tvf-deco" src="{{ $wc('wc-birds.svg') }}" alt="" aria-hidden="true" width="140"
            height="70" style="left:46%;top:12%;width:110px;opacity:.8;filter:brightness(2.2)">

        <div class="tvp-wrap tvf-scenic__grid">
            <div class="tvf-rv">
                <h2 id="tvfScenicH"><span class="tvf-script">Không chỉ là một chuyến đi…</span>
                    <small>…mà là hành trình để tìm thấy chính mình</small>
                </h2>
                <div class="tvf-actions">
                    @if (config('site.youtube'))
                        <a class="tvp-btn tvp-btn--glass" href="{{ config('site.youtube') }}" target="_blank"
                            rel="noopener noreferrer">
                            <svg class="tvp-ic" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M8 5l11 7-11 7z" />
                            </svg> Xem video giới thiệu <span class="sr-only">(mở tab mới)</span></a>
                    @else
                        <a class="tvp-btn tvp-btn--glass" href="{{ route('tours') }}">Xem các tour</a>
                    @endif
                </div>
            </div>

            @if ($review)
                <figure class="tvf-quote tvf-rv" style="--d:.15s">
                    <span class="tvf-quote__mark" aria-hidden="true">“</span>
                    <blockquote>
                        <p>{{ $review->comment }}</p>
                    </blockquote>
                    <figcaption>
                        <img src="{{ \App\Support\Avatar::url($review->avatar) }}" alt="" width="52"
                            height="52" loading="lazy">
                        <span class="tvf-quote__who">
                            <cite class="tvf-quote__name">{{ $review->fullName }}</cite>
                            <span class="tvf-stars" role="img"
                                aria-label="{{ $stars }} trên 5 sao">{{ str_repeat('★', $stars) }}<i>{{ str_repeat('★', 5 - $stars) }}</i></span>
                            <span class="tvf-quote__meta">Khách hàng của {{ $siteName }}</span>
                        </span>
                    </figcaption>
                </figure>
            @else
                <figure class="tvf-quote tvf-quote--empty tvf-rv" style="--d:.15s">
                    <blockquote>
                        <p>Hành trình của bạn sẽ là câu chuyện tiếp theo ở đây. Hãy chọn một chuyến đi và chia sẻ cảm
                            nhận sau khi trở về.</p>
                    </blockquote>
                    <a class="tvp-btn tvp-btn--teal" href="{{ route('tours') }}">Chọn chuyến đi đầu tiên</a>
                </figure>
            @endif
        </div>
        <div class="tvf-scenic__edge" aria-hidden="true"></div>
    </section>

    {{-- ===== 5. CÙNG TRAVEL KHÁM PHÁ (Hạ Long màu nước) ===== --}}
    <section class="tvf-cta" aria-labelledby="tvfCtaH">
        <img class="tvf-cta__wash" src="{{ $wc('wc-wash-sand.svg') }}" alt="" aria-hidden="true"
            width="900" height="620" loading="lazy">
        <img class="tvf-cta__art" src="{{ $wc('wc-halong.svg') }}" alt="" aria-hidden="true"
            width="1400" height="560" loading="lazy">
        <img class="tvf-deco tvf-leaf" src="{{ $wc('wc-leaf-a.svg') }}" alt="" aria-hidden="true"
            width="560" height="640" loading="lazy"
            style="--r:4deg;left:-80px;bottom:-60px;width:240px;z-index:1">
        <img class="tvf-deco" src="{{ $wc('wc-birds.svg') }}" alt="" aria-hidden="true" width="140"
            height="70" loading="lazy" style="left:56%;top:9%;width:96px;opacity:.7">

        <div class="tvp-wrap tvf-cta__in">
            <div class="tvf-cta__copy tvf-rv">
                <h2 id="tvfCtaH"><span class="tvf-script">Cùng {{ $siteName }} khám phá</span>Việt Nam theo
                    cách
                    của bạn!</h2>
                <p>Hãy để chúng tôi đồng hành cùng bạn trên những hành trình đáng nhớ, đầy cảm hứng và trải nghiệm
                    đáng giá.</p>
                <div class="tvf-actions">
                    <a class="tvp-btn tvp-btn--teal" href="{{ route('tours') }}">Xem các tour nổi bật
                        <svg class="tvp-ic" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M5 12h14M13 6l6 6-6 6" />
                        </svg>
                    </a>
                    <a class="tvp-btn tvp-btn--ghost" href="{{ route('contact') }}">Liên hệ tư vấn</a>
                </div>
                <ul class="tvf-points">
                    <li>{!! $check !!} Giữ chỗ {{ $holdHours }} giờ, giá và số chỗ hiển thị minh bạch</li>
                    <li>{!! $check !!} Tự hủy tour khi còn hơn {{ $cancelDays }} ngày</li>
                    <li>{!! $check !!} Nhắn tin hỗ trợ ngay trên website</li>
                </ul>
            </div>
        </div>
    </section>
</main>

@include('clients.blocks.footer')
