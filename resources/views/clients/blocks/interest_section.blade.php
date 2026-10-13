{{-- "Khám phá theo cảm hứng" — Bản đồ cảm xúc (tương tác).
     Dữ liệu: $inspirationMap (App\Services\InspirationService::mapData()). JS: public/clients/assets/js/inspiration-map.js
     Không bật JS: danh sách bên trái vẫn là link tới /explore?inspiration=... --}}
@php($tvxInspirations = $inspirationMap['inspirations'])
<section class="tvx-inspire tvx-map-section" id="kham-pha" aria-labelledby="tvxInspireTitle">
    <div class="container">
        <header class="tvx-inspire__head" data-aos="fade-up" data-aos-duration="800">
            <p class="tvx-eyebrow">Khám phá theo cảm hứng</p>
            <h2 id="tvxInspireTitle">Bạn đang muốn một chuyến đi <em>như thế nào?</em></h2>
            <p class="tvx-lead">Chọn một cảm hứng, bản đồ sẽ chỉ cho bạn những nơi hợp với nó nhất.</p>
        </header>

        @if (count($tvxInspirations))
            <div class="tvx-map" id="tvxMap" data-active="{{ $tvxInspirations[0]['slug'] }}">
                {{-- 8 cảm hứng --}}
                <nav class="tvx-map__tabs" aria-label="Chọn cảm hứng" role="tablist">
                    @foreach ($tvxInspirations as $it)
                        <a href="{{ route('explore', ['inspiration' => $it['slug']]) }}" class="tvx-tab"
                            data-slug="{{ $it['slug'] }}" role="tab"
                            aria-selected="{{ $loop->first ? 'true' : 'false' }}">
                            <span class="tvx-tab__icon" aria-hidden="true"><i
                                    class="fas {{ $it['icon'] }}"></i></span>
                            <span class="tvx-tab__text">
                                <strong>{{ $it['name'] }}</strong>
                                <small>{{ $it['tagline'] }}</small>
                            </span>
                            <span class="tvx-tab__n" title="Số điểm đến">{{ $it['count'] }}</span>
                        </a>
                    @endforeach
                </nav>

                {{-- Bản đồ + pin (JS vẽ pin vào #tvxPins) --}}
                <div class="tvx-map__stage" aria-live="polite">
                    <div class="tvx-map__board">
                        @include('clients.partials.vn-map-svg')
                        <div class="tvx-map__pins" id="tvxPins"></div>
                    </div>
                    {{-- Thẻ điểm đến: trên máy tính nổi trên bản đồ, trên điện thoại nằm ngay dưới bản đồ --}}
                    <div class="tvx-pop" id="tvxPop" hidden></div>
                    <p class="tvx-map__legend">
                        <span class="tvx-map__legend-dot"></span> Chấm càng lớn, càng hợp với cảm hứng này
                        <span class="tvx-map__lit" id="tvxLit"></span>
                    </p>
                </div>

                {{-- Chi tiết cảm hứng đang chọn --}}
                <div class="tvx-detail" id="tvxDetail">
                    <p class="tvx-detail__hand" id="tvxHand"></p>
                    <p class="tvx-detail__top">Hợp nhất: <span id="tvxTop"></span></p>
                    <a href="{{ route('explore', ['inspiration' => $tvxInspirations[0]['slug']]) }}"
                        class="tvx-detail__cta" id="tvxCta">
                        <span id="tvxCtaText">Xem tất cả điểm đến</span> <i class="fal fa-arrow-right"></i>
                    </a>
                </div>
            </div>

            <script type="application/json" id="tvxMapData">@json($inspirationMap)</script>
            <script src="{{ asset('clients/assets/js/inspiration-map.js') }}?v=1" defer></script>
        @endif
    </div>
</section>
