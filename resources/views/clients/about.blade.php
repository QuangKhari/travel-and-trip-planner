@include('clients.blocks.header')
{{--
  Trang Giới thiệu (giao diện mới, CSS: tv3.css + tv3-pages.css, tiền tố ab-).
  Số liệu và đánh giá lấy từ CSDL (AboutController): mục nào chưa có dữ liệu thì tự ẩn, không tự tạo lời chứng thực.
--}}
@php
    $img = fn($f) => asset('clients/assets/images/' . $f);
    $hasStats =
        !empty($stats) &&
        (($stats['tours'] ?? 0) > 0 ||
            ($stats['destinations'] ?? 0) > 0 ||
            ($stats['completed'] ?? 0) > 0 ||
            ($stats['reviews'] ?? 0) > 0);
    $values = [
        ['Trải nghiệm thật', 'Lịch trình, ảnh và thông tin điểm đến được kiểm tra trước khi đăng.', 'fa-leaf', '#2f9e5b', '#e3f4e8'],
        ['Giá minh bạch', 'Giá và số chỗ còn lại lấy trực tiếp từ hệ thống, không phát sinh khi thanh toán.', 'fa-receipt', '#0b9488', '#dff4f1'],
        ['An toàn, tin cậy', 'Thanh toán online qua VNPay, thông tin cá nhân được mã hoá khi lưu.', 'fa-shield-check', '#2f6fdb', '#e2ecfc'],
        ['Luôn có người hỗ trợ', 'Hỏi qua trang liên hệ hoặc trợ lý chat, có người trả lời bạn.', 'fa-headset', '#d93a6c', '#fde3ec'],
    ];
@endphp

<main class="v3 ab">

    {{-- ===== ĐẦU TRANG ===== --}}
    <section class="ab-hero" aria-labelledby="abTitle">
        <div class="v3-wrap">
            <nav class="tr-crumb" aria-label="breadcrumb">
                <a href="{{ route('home') }}">Trang chủ</a><span aria-hidden="true">›</span><span
                    aria-current="page">Giới thiệu</span>
            </nav>
            <div class="ab-hero__in">
                <div>
                    <p class="v3-eyebrow">Về chúng tôi</p>
                    <h1 id="abTitle">{{ config('site.name') }} – đồng hành cùng bạn <span>khám phá Việt Nam</span></h1>
                    <p class="ab-hero__sub">Chúng tôi tin mỗi chuyến đi không chỉ là điểm đến, mà là những trải nghiệm,
                        cảm xúc và câu chuyện đáng nhớ. {{ config('site.name') }} giúp bạn chọn đúng nơi, đúng mùa và đặt
                        tour trong vài bước.</p>
                    <div class="ab-hero__btns">
                        <a href="{{ route('tours') }}" class="v3-btn v3-btn--teal">Khám phá tour <i
                                class="fal fa-arrow-right" aria-hidden="true"></i></a>
                        <a href="{{ route('contact') }}" class="v3-btn v3-btn--line">Liên hệ với chúng tôi</a>
                    </div>
                </div>
                <div class="ab-photos" aria-hidden="true">
                    <figure class="p1"><img src="{{ $img('about/hero-about.jpg') }}" alt=""
                            fetchpriority="high"></figure>
                    <figure class="p2"><img src="{{ $img('about/story-2.jpg') }}" alt="" loading="lazy">
                    </figure>
                    <figure class="p3"><img src="{{ $img('about/story-3.jpg') }}" alt="" loading="lazy">
                    </figure>
                    <span class="ab-note">Việt Nam đẹp lắm!</span>
                </div>
            </div>
        </div>
    </section>

    {{-- ===== SỐ LIỆU THẬT ===== --}}
    @if ($hasStats)
        <section class="v3-wrap" aria-label="Thông tin {{ config('site.name') }}">
            <div class="ab-stats v3-rv">
                @if (($stats['tours'] ?? 0) > 0)
                    <div class="ab-stat"><b>{{ number_format($stats['tours']) }}</b><span>tour đang mở bán</span>
                    </div>
                @endif
                @if (($stats['destinations'] ?? 0) > 0)
                    <div class="ab-stat"><b>{{ number_format($stats['destinations']) }}</b><span>điểm đến trong
                            tour</span></div>
                @endif
                @if (($stats['completed'] ?? 0) > 0)
                    <div class="ab-stat"><b>{{ number_format($stats['completed']) }}</b><span>chuyến đi đã hoàn
                            tất</span></div>
                @endif
                @if (($stats['reviews'] ?? 0) > 0)
                    <div class="ab-stat"><b>{{ number_format($stats['reviews']) }}</b><span>đánh giá của
                            khách</span></div>
                @endif
                @if (($stats['rating'] ?? 0) > 0)
                    <div class="ab-stat"><b>{{ number_format($stats['rating'], 1) }}<small class="ab-stars">
                                ★</small></b><span>điểm trung bình</span></div>
                @endif
            </div>
        </section>
    @endif

    {{-- ===== CÂU CHUYỆN ===== --}}
    <section class="v3-sec" aria-labelledby="abStory">
        <div class="v3-wrap ab-story">
            <div class="ab-story__img v3-rv">
                <img src="{{ $img('about/story-1.jpg') }}" alt="Du khách ngắm cảnh trong một chuyến đi"
                    loading="lazy">
                <span class="ab-story__badge"><i class="fas fa-route" aria-hidden="true"></i>Tour, lịch trình<br>và
                    cẩm nang ở một nơi</span>
            </div>
            <div class="v3-rv" style="--d: 1">
                <p class="v3-eyebrow">Câu chuyện của chúng tôi</p>
                <h2 id="abStory">Từ một ý tưởng nhỏ đến hành trình lớn</h2>
                <p>{{ config('site.name') }} ra đời từ niềm đam mê khám phá Việt Nam của những người trẻ yêu du lịch.
                    Chúng tôi muốn tạo ra một nơi giúp bạn dễ dàng tìm kiếm, lên kế hoạch và trải nghiệm những chuyến
                    đi tuyệt vời nhất.</p>
                <p>Không chỉ là đặt tour, {{ config('site.name') }} còn là <strong>người bạn đồng hành</strong>: gợi ý
                    nơi đang vào mùa, món nên thử, lịch trình mẫu và một bản đồ cảm hứng để bạn chọn chuyến đi hợp với
                    mình.</p>
                <ul class="ab-checks">
                    <li><i class="fas fa-check" aria-hidden="true"></i>Đặt tour, thanh toán VNPay</li>
                    <li><i class="fas fa-check" aria-hidden="true"></i>Gợi ý theo mùa và cảm hứng</li>
                    <li><i class="fas fa-check" aria-hidden="true"></i>Tự lên lịch trình</li>
                    <li><i class="fas fa-check" aria-hidden="true"></i>Cẩm nang, ẩm thực, lễ hội</li>
                </ul>
            </div>
        </div>
    </section>

    {{-- ===== GIÁ TRỊ ===== --}}
    <section class="v3-sec" aria-labelledby="abValues">
        <div class="v3-wrap">
            <div class="v3-head v3-rv">
                <div class="v3-head__t">
                    <h2 id="abValues">Điều làm nên {{ config('site.name') }}</h2>
                    <p>Những cam kết chúng tôi giữ trong từng chuyến đi.</p>
                </div>
            </div>
            <div class="ab-values">
                @foreach ($values as $i => [$t, $d, $ic, $c, $bg])
                    <article class="ab-val v3-rv" style="--d: {{ $i }}">
                        <span class="ab-val__i" style="--c: {{ $c }}; --bg: {{ $bg }}"><i
                                class="fas {{ $ic }}" aria-hidden="true"></i></span>
                        <h3>{{ $t }}</h3>
                        <p>{{ $d }}</p>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ===== BẮT ĐẦU NHƯ THẾ NÀO ===== --}}
    <section class="v3-sec" aria-labelledby="abSteps">
        <div class="v3-wrap">
            <div class="v3-head v3-rv">
                <div class="v3-head__t">
                    <h2 id="abSteps">Bắt đầu chuyến đi trong 3 bước</h2>
                    <p>Chọn theo cách bạn thích: theo cảm hứng, theo mùa hoặc tìm thẳng tour.</p>
                </div>
            </div>
            <div class="ab-steps">
                <div class="ab-step v3-rv">
                    <h3>Chọn cảm hứng</h3>
                    <p>Biển, núi, văn hoá hay ẩm thực: bản đồ cảm hứng gợi ý nơi hợp với bạn.</p>
                    <a class="v3-more" href="{{ route('destination') }}">Xem điểm đến</a>
                </div>
                <div class="ab-step v3-rv" style="--d: 1">
                    <h3>Xem lịch trình</h3>
                    <p>Đọc lịch trình mẫu hoặc tự lên kế hoạch từng ngày cho chuyến đi.</p>
                    <a class="v3-more" href="{{ route('planner') }}">Lên kế hoạch</a>
                </div>
                <div class="ab-step v3-rv" style="--d: 2">
                    <h3>Đặt tour</h3>
                    <p>Chọn ngày, số khách, thanh toán VNPay và nhận xác nhận qua email.</p>
                    <a class="v3-more" href="{{ route('tours') }}">Xem tour</a>
                </div>
            </div>
        </div>
    </section>

    {{-- ===== ĐÁNH GIÁ THẬT (hoặc lời mời nếu chưa có) ===== --}}
    <section class="v3-sec" aria-label="Đánh giá của khách hàng">
        <div class="v3-wrap">
            <div class="ab-quote v3-rv">
                <div class="ab-quote__img">
                    <img src="{{ $img('about/testimonial-landscape.jpg') }}" alt="" loading="lazy">
                    <p>Không chỉ là<br>một chuyến đi...</p>
                </div>
                <div class="ab-quote__body">
                    @if (!empty($review) && !empty($review->comment))
                        <span class="ab-quote__mark" aria-hidden="true">“</span>
                        <p class="ab-quote__text">{{ $review->comment }}</p>
                        <div class="ab-quote__who">
                            <span class="ab-quote__av"
                                aria-hidden="true">{{ mb_substr(trim($review->fullName ?? 'K'), 0, 1) }}</span>
                            <span><b>{{ $review->fullName ?: 'Khách hàng ' . config('site.name') }}</b>Đánh giá của khách
                                hàng
                                @if (isset($review->rating) && is_numeric($review->rating))
                                    <span class="ab-stars"
                                        aria-label="{{ (int) $review->rating }} trên 5 sao">{{ str_repeat('★', max(0, min(5, (int) $review->rating))) }}</span>
                                @endif
                            </span>
                        </div>
                    @else
                        <p class="v3-eyebrow" style="color: var(--v3-sand)">Trải nghiệm cùng
                            {{ config('site.name') }}</p>
                        <p class="ab-quote__text">Mỗi hành trình mở ra một câu chuyện mới. Khám phá các tour đang có và
                            chọn hành trình phù hợp với bạn.</p>
                    @endif
                    <div><a href="{{ route('tours') }}" class="v3-btn v3-btn--sand">Khám phá các tour <i
                                class="fal fa-arrow-right" aria-hidden="true"></i></a></div>
                </div>
            </div>
        </div>
    </section>

    <div class="ab-gap" aria-hidden="true"></div>
</main>

@include('clients.blocks.footer')
