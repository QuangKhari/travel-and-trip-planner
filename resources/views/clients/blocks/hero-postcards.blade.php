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
    // Chưa có Planner (planner_url = null): bưu ảnh trắng dẫn xuống khối lên kế hoạch ngay dưới hero (#hpl).
    // Có Planner: dẫn tới {planner_url}?thang=N.
    $plannerBase = config('postcards.planner_url');
    $plannerUrl = $plannerBase ? url($plannerBase) : '';
    $blankUrl = $plannerBase ? $plannerUrl . '?thang=' . $month : '#hpl';
    $blankLabel = $plannerBase ? 'Lên kế hoạch cho tháng ' . $month : 'Lên kế hoạch ngay bên dưới';
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
                <p class="hp-lead">Chọn một tháng để xem những nơi đẹp nhất, rồi đi dọc dải bưu ảnh.</p>
            </div>

            {{-- Bộ chọn tháng: đủ 12 tháng hiện cùng lúc (không phải kéo ngang), ô lớn dễ bấm, có thanh trượt báo tháng đang chọn.
                 Giữ class hp-chip / data-month / aria-pressed vì hero-postcards.js đọc các thuộc tính này. --}}
            @php $nowMonth = (int) now()->format('n'); @endphp
            <div class="hp-rail" role="group" aria-label="Chọn tháng">
                <span class="hp-rail__label" aria-hidden="true">Tháng</span>
                <button type="button" class="hp-rail__nav" data-dir="-1" aria-label="Tháng trước"><i
                        class="fas fa-chevron-left" aria-hidden="true"></i></button>
                <div class="hp-chips" role="group" aria-label="Danh sách tháng">
                    <span class="hp-chips__thumb" aria-hidden="true"></span>
                    @for ($m = 1; $m <= 12; $m++)
                        <button type="button" class="hp-chip{{ $m === $nowMonth ? ' is-now' : '' }}"
                            data-month="{{ $m }}" aria-pressed="{{ $m === $month ? 'true' : 'false' }}"
                            aria-label="Tháng {{ $m }}{{ $m === $nowMonth ? ' (tháng hiện tại)' : '' }}"
                            @if ($m === $nowMonth) title="Tháng hiện tại" @endif>{{ $m }}</button>
                    @endfor
                </div>
                <button type="button" class="hp-rail__nav" data-dir="1" aria-label="Tháng sau"><i
                        class="fas fa-chevron-right" aria-hidden="true"></i></button>
            </div>
            <div class="hp-notice" role="status" aria-live="polite" hidden></div>

            <div class="hp-scene">
                <div class="hp-world">
                    @foreach ($postcards as $i => $p)
                        <a class="hp-card" href="{{ $p['url'] }}" data-type="{{ $p['type'] ?? 'destination' }}" data-name="{{ $p['name'] }}"
                            data-why="{{ $p['why'] }}" data-credit="{{ $p['credit'] }}" data-thumb="{{ $p['thumb'] ?? '' }}"
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
                    <a class="hp-card hp-blank" href="{{ $blankUrl }}" data-blank="1"
                        aria-label="Bưu ảnh trắng: {{ $blankLabel }}">
                        <b>Chuyến đi của bạn bắt đầu từ đâu?</b>
                        <span class="hp-go">{{ $blankLabel }} →</span>
                    </a>
                </div>
            </div>

            <div class="hp-sr" aria-live="polite" aria-atomic="true"></div>
            <div class="hp-panel">
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
