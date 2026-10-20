{{--
  Hero "Dòng bưu ảnh" (tài liệu mục 4.1.1).
  Cần: $postcards (HeroPostcardService::forMonth), $month.
  Không có JS vẫn đọc và bấm được: mỗi bưu ảnh là một liên kết tới trang điểm đến.
--}}
@php
    $season = match (true) {
        in_array($month, [2, 3, 4], true) => 'spring',
        in_array($month, [5, 6, 7], true) => 'summer',
        in_array($month, [8, 9, 10], true) => 'autumn',
        default => 'winter',
    };
    $plannerUrl = url(config('postcards.planner_url', '/')) . '#kham-pha';
    $count = count($postcards) + 1; // + bưu ảnh trắng
@endphp
<section class="hp" id="hero" style="--n: {{ $count }}" data-month="{{ $month }}"
    data-season="{{ $season }}" data-planner="{{ $plannerUrl }}" data-api="{{ url('/api/v1/seasons') }}"
    aria-label="Điểm đến đẹp trong tháng {{ $month }}">
    {{-- Chạy đồng bộ trước khi vẽ để không giật bố cục; quá 3 giây mà JS chưa sẵn sàng thì rơi về bản tĩnh --}}
    <script>
        (function(s) {
            var m = matchMedia;
            if (!m('(max-width:599px)').matches && !m('(prefers-reduced-motion: reduce)').matches) {
                s.classList.add('hp--3d');
                setTimeout(function() {
                    if (!s.dataset.ready) s.classList.remove('hp--3d')
                }, 3000)
            }
        })(document.currentScript.parentNode)
    </script>
    <a class="hp-skip" href="#hp-after">Bỏ qua hero</a>

    <div class="hp-track">
        <div class="hp-stage">
            <div class="hp-bg" aria-hidden="true"></div>
            <div class="hp-bg" aria-hidden="true"></div>
            <div class="hp-veil" aria-hidden="true"></div>

            <div class="hp-intro">
                <p class="hp-eyebrow">Dòng bưu ảnh</p>
                <h1>Tháng <span class="hp-month">{{ $month }}</span>, Việt Nam đang đẹp ở đâu?</h1>
                <p class="hp-lead">Cuộn xuống để đi dọc dải bưu ảnh.</p>
            </div>

            <div class="hp-chips" role="group" aria-label="Chọn tháng">
                @for ($m = 1; $m <= 12; $m++)
                    <button type="button" class="hp-chip" data-month="{{ $m }}"
                        aria-pressed="{{ $m === $month ? 'true' : 'false' }}">Tháng {{ $m }}</button>
                @endfor
            </div>

            <div class="hp-scene">
                <div class="hp-world">
                    @foreach ($postcards as $i => $p)
                        <a class="hp-card" href="{{ $p['url'] }}" data-name="{{ $p['name'] }}"
                            data-why="{{ $p['why'] }}" data-credit="{{ $p['credit'] }}"
                            data-credit-url="{{ $p['creditUrl'] }}" data-slug="{{ $p['slug'] }}"
                            aria-label="{{ $p['name'] }}, {{ $p['province'] }}. {{ $p['why'] }}">
                            <span class="hp-img"><img src="{{ $p['image'] }}"
                                    alt="{{ $p['name'] }}, {{ $p['province'] }}"
                                    @if ($i === 0) fetchpriority="high" @else loading="{{ $i < 3 ? 'eager' : 'lazy' }}" @endif
                                    decoding="async"></span>
                            <span class="hp-cap"><b>{{ $p['name'] }}</b><i>{{ $p['province'] }}</i></span>
                            <span class="hp-stamp" aria-hidden="true">T{{ $month }}</span>
                        </a>
                    @endforeach
                    <a class="hp-card hp-blank" href="{{ $plannerUrl . $month }}" data-blank="1"
                        aria-label="Bưu ảnh trắng: khám phá theo cảm hứng">
                        <b>Chuyến đi của bạn bắt đầu từ đâu?</b>
                        <span class="hp-go">Khám phá theo cảm hứng →</span>
                    </a>
                </div>
            </div>

            <div class="hp-panel" role="status" aria-live="polite">
                <h2 class="hp-p-name"></h2>
                <p class="hp-p-why"></p>
                <div class="hp-row">
                    <a class="hp-btn hp-p-go" href="#">Xem điểm đến</a>
                    <button type="button" class="hp-btn hp-btn--ghost hp-p-add">+ Thêm vào chuyến đi</button>
                </div>
                <small class="hp-p-credit"></small>
            </div>
            <div class="hp-count" aria-hidden="true"></div>
            <div class="hp-hint" aria-hidden="true">Cuộn ↓ · phím ← →</div>
        </div>
    </div>
</section>
<span id="hp-after"></span>
