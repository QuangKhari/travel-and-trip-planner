{{--
  "Bạn đang muốn một chuyến đi như thế nào?" — mục lục 8 cảm hứng (hàng ngang, thẻ mở rộng như thiết kế cũ)
  + bản đồ màu nước lớn; khung gợi ý và thẻ nơi đang chọn nổi trên vùng biển của bản đồ.
  Dữ liệu: $inspirationMap (App\Services\InspirationService::mapData()).
  CSS: inspiration-hub.css (thành phần gốc) + tv3-home.css (phần "BẢN ĐỒ CẢM HỨNG").  JS: inspiration-hub.js
  Không bật JS: mỗi cảm hứng vẫn là link tới /explore?inspiration=...
--}}
@php
    $tvhIns = $inspirationMap['inspirations'];
    $tvhDest = collect($inspirationMap['destinations']);
    $tvhRank = fn(string $slug) => $tvhDest
        ->filter(fn($d) => ($d['scores'][$slug] ?? 0) > 0)
        ->sortByDesc(fn($d) => $d['scores'][$slug])
        ->values();
@endphp

@if (count($tvhIns))
    @php
        $tvhFirst = $tvhIns[0];
        $tvhFirstRank = $tvhRank($tvhFirst['slug']);
    @endphp
    <section class="tvh tvm" id="kham-pha" aria-labelledby="tvhTitle">
        @include('clients.partials.inspiration-motifs')

        <div class="v3-wrap">
            <p class="tvh-sr" id="tvhLive" aria-live="polite"></p>

            <header class="tvm-head" id="tvhChon">
                <p class="tvh-eyebrow"><i class="fas fa-water" aria-hidden="true"></i> Khám phá theo cảm hứng</p>
                <h2 id="tvhTitle">Bạn đang muốn một chuyến đi như thế nào?</h2>
                <p class="tvm-lead">Mỗi người đều có một lý do để đi. Chọn cảm hứng của bạn, chúng tôi sẽ gợi ý những
                    điểm đến phù hợp nhất.</p>
            </header>

            <div class="tvh-hub" id="tvhHub" data-ins="{{ $tvhFirst['slug'] }}">
                {{-- Mục lục 8 cảm hứng: rê chuột để xem nhanh, bấm để chọn (phím mũi tên trái/phải) --}}
                <nav class="tvh-toc" id="tvhToc" aria-label="Chọn cảm hứng (dùng phím mũi tên)">
                    @foreach ($tvhIns as $i => $it)
                        <a href="{{ route('explore', ['inspiration' => $it['slug']]) }}"
                            class="tvh-row{{ $loop->first ? ' is-selected is-open' : '' }}"
                            data-slug="{{ $it['slug'] }}" data-n="{{ $i }}"
                            @if ($loop->first) aria-current="true" @endif
                            aria-label="{{ $it['name'] }}, {{ $it['tagline'] }}, {{ $it['count'] }} điểm đến">
                            <svg class="tvh-row__scene" viewBox="0 0 320 170" preserveAspectRatio="xMidYMin slice"
                                aria-hidden="true" focusable="false">
                                <use href="#tvh-m-{{ $it['slug'] }}" />
                            </svg>
                            <span class="tvh-row__bar">
                                <span class="tvh-row__line">
                                    <span class="tvh-row__num">{{ sprintf('%02d', $i + 1) }}</span>
                                    <span class="tvh-row__chip" aria-hidden="true"><i
                                            class="fas {{ $it['icon'] }}"></i></span>
                                    <strong class="tvh-row__name">{{ $it['name'] }}</strong>
                                    <span class="tvh-row__slot" aria-hidden="true">
                                        <span class="tvh-row__count">{{ $it['count'] }} nơi</span>
                                        <span class="tvh-row__pick">Chọn</span>
                                        <span class="tvh-row__check"><i class="fas fa-check"></i></span>
                                    </span>
                                </span>
                                <span class="tvh-row__more" aria-hidden="true">
                                    <span class="tvh-row__tag">{{ $it['tagline'] }}</span>
                                    <span class="tvh-row__top">Hợp nhất:
                                        {{ $tvhRank($it['slug'])->take(3)->pluck('name')->implode(' · ') }}</span>
                                </span>
                            </span>
                        </a>
                    @endforeach
                </nav>

                {{-- Bản đồ lớn --}}
                <div class="tvm-map" id="tvhBanDo">
                    <div class="tvh-board tvm-board" id="tvhBoard">
                        @include('clients.partials.vn-watercolor-map')
                        <div class="tvh-pins" id="tvhPins" aria-hidden="true"></div>
                        <div class="tvh-labels" id="tvhLabels" aria-hidden="true"></div>
                    </div>

                    <p class="tvm-legend"><span class="tvm-legend__dot" aria-hidden="true"></span>
                        <span>Chấm càng lớn càng hợp · <strong id="tvhLit">{{ $tvhFirstRank->count() }}</strong> điểm
                            sáng</span>
                    </p>

                    {{-- Khung gợi ý (nổi trên Biển Đông) --}}
                    <div class="tvm-panel">
                        <p class="tvm-kicker"><i class="fas {{ $tvhFirst['icon'] }}" id="tvhNowIcon"
                                aria-hidden="true"></i> <span id="tvhNowName">{{ $tvhFirst['name'] }}</span></p>
                        <h3 class="tvm-quote" id="tvhQuote">{{ $tvhFirst['quote'] }}</h3>
                        <div class="tvh-doms tvm-doms" role="group" aria-label="Lọc theo miền" id="tvhDoms">
                            <button type="button" data-dom="all" aria-pressed="true">Tất cả</button>
                            <button type="button" data-dom="b" aria-pressed="false">Bắc</button>
                            <button type="button" data-dom="t" aria-pressed="false">Trung</button>
                            <button type="button" data-dom="n" aria-pressed="false">Nam</button>
                        </div>
                        <p class="tvm-sub" id="tvhBandSub"></p>
                        <div class="tvh-band tvm-list" id="tvhBand"></div>
                        <a class="tvm-all" href="{{ route('explore', ['inspiration' => $tvhFirst['slug']]) }}"
                            id="tvhAll"><span id="tvhAllText">Xem tất cả {{ $tvhFirstRank->count() }} điểm
                                đến</span> <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                    </div>

                    {{-- Thẻ nơi đang chọn (nổi góc dưới bên trái) --}}
                    <div class="tvm-card">
                        <div class="tvm-card__ph" id="tvmPhoto" aria-hidden="true"><span class="tvm-card__wx"
                                id="tvhDetailTemp" hidden></span></div>
                        <div class="tvh-detail tvm-detail" id="tvhDetail">
                            <p class="tvh-detail__where"><i class="fas fa-map-marker-alt" aria-hidden="true"></i>
                                <span id="tvhDetailWhere"></span>
                            </p>
                            <h4 id="tvhDetailName"></h4>
                            <p class="tvh-detail__desc" id="tvhDetailDesc"></p>
                            <div class="tvm-btns">
                                <a href="#" class="tvh-btn is-ghost" id="tvhDetailMore">Xem điểm đến</a>
                                <a href="{{ route('tours') }}" class="tvh-btn" id="tvhDetailCta">Xem tour</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script type="application/json" id="tvhData">@json($inspirationMap)</script>
        <script src="{{ asset('clients/assets/js/inspiration-hub.js') }}?v=3" defer></script>
    </section>
@endif
