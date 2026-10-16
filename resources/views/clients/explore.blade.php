@include('clients.blocks.header')

{{-- /explore?inspiration={slug}[&domain=b|t|n] — giao diện "Travel Journal".
     Biến: $inspiration, $destinations, $others, $domain, $domains --}}
<section class="tvx-hero tvx-card--{{ $inspiration->slug }}">
    @if ($inspiration->image)
        <img class="tvx-hero__img" src="{{ $inspiration->image }}" alt="" fetchpriority="high">
    @endif
    <span class="tvx-hero__shade" aria-hidden="true"></span>
    <div class="container">
        <nav class="tvx-crumb" aria-label="breadcrumb">
            <a href="{{ route('home') }}">Trang chủ</a>
            <i class="fas fa-chevron-right"></i>
            <a href="{{ route('home') }}#kham-pha">Khám phá theo cảm hứng</a>
            <i class="fas fa-chevron-right"></i>
            <span aria-current="page">{{ $inspiration->name }}</span>
        </nav>
        <p class="tvx-hero__eyebrow"><i class="fas {{ $inspiration->icon }}"></i> {{ $inspiration->tagline }}</p>
        <h1 class="tvx-hero__title">{{ $inspiration->name }}</h1>
        <p class="tvx-hero__lead">{{ $inspiration->headline }}</p>
    </div>
    {{-- mép giấy xé --}}
    <svg class="tvx-hero__tear" viewBox="0 0 1200 30" preserveAspectRatio="none" aria-hidden="true">
        <path
            d="M0 30 L0 13 L18 15 L52 4 L68 20 L85 14 L117 4 L147 9 L162 5 L189 16 L205 10 L221 20 L248 4 L280 6 L301 21 L316 21 L348 15 L363 10 L378 20 L396 12 L423 7 L454 6 L486 12 L517 8 L534 21 L566 9 L591 6 L622 5 L654 4 L687 9 L716 20 L743 13 L771 21 L799 14 L822 10 L841 10 L857 21 L880 19 L909 13 L937 12 L970 5 L987 19 L1014 8 L1038 7 L1067 16 L1082 5 L1113 21 L1137 13 L1162 18 L1194 17 L1200 30 Z" />
    </svg>
</section>

<section class="tvx-explore">
    <div class="container">
        <div class="tvx-bar">
            <div class="tvx-filter" role="group" aria-label="Lọc theo miền">
                <a href="{{ route('explore', ['inspiration' => $inspiration->slug]) }}"
                    class="tvx-pill {{ $domain === null ? 'is-on' : '' }}">Tất cả</a>
                @foreach ($domains as $code => $label)
                    <a href="{{ route('explore', ['inspiration' => $inspiration->slug, 'domain' => $code]) }}"
                        class="tvx-pill {{ $domain === $code ? 'is-on' : '' }}">{{ $label }}</a>
                @endforeach
            </div>
            <p class="tvx-count">{{ $destinations->count() }} điểm đến</p>
        </div>

        @if ($destinations->isEmpty())
            <div class="tvx-empty">
                <i class="fal fa-compass"></i>
                <h3>Chưa có điểm đến nào ở khu vực này</h3>
                <p>Hãy thử chọn “Tất cả” hoặc một miền khác.</p>
                <a class="ux-chip" href="{{ route('explore', ['inspiration' => $inspiration->slug]) }}">Xem tất cả
                    miền</a>
            </div>
        @else
            <div class="tvx-journal">
                @foreach ($destinations as $i => $d)
                    @php
                        $href = $d->tourCount > 0 ? route('tours', ['keyword' => $d->name]) : null;
                    @endphp
                    <article class="tvx-jcard tvx-card--{{ $inspiration->slug }} {{ $href ? 'has-link' : '' }}"
                        data-aos="fade-up" data-aos-delay="{{ ($i % 3) * 70 }}" data-aos-duration="700"
                        data-aos-offset="20">
                        <span class="tvx-tape" aria-hidden="true"></span>

                        <div class="tvx-jphoto">
                            @if ($d->image)
                                <img src="{{ $d->image }}" alt="{{ $d->name }}" loading="lazy"
                                    decoding="async">
                            @else
                                <i class="fas {{ $inspiration->icon }} tvx-card__ghost" aria-hidden="true"></i>
                            @endif
                            <span class="tvx-card__shade" aria-hidden="true"></span>
                        </div>

                        <span class="tvx-stamp" aria-hidden="true">
                            <span>{{ $d->province }}<small>Việt Nam</small></span>
                        </span>

                        <h3 class="tvx-jname">{{ $d->name }}</h3>
                        <p class="tvx-jplace">{{ $d->province }} · {{ $d->domainName }}</p>
                        <p class="tvx-jdesc">{{ $d->description }}</p>

                        <div class="tvx-jfoot">
                            <span class="tvx-sticker" title="Mức độ phù hợp với “{{ $inspiration->name }}”"
                                aria-label="Mức độ phù hợp {{ $d->score }} trên 100">
                                <small>Độ hợp</small><b>{{ $d->score }}<span
                                        class="tvx-sticker__max">/100</span></b>
                            </span>
                            @if ($href)
                                <a href="{{ $href }}" class="tvx-ticket">
                                    <i class="fal fa-ticket-alt"></i>
                                    <span>{{ $d->tourCount }} tour<br>đang mở bán</span>
                                </a>
                            @else
                                <a href="{{ route('contact') }}" class="tvx-ticket tvx-ticket--soon">
                                    <i class="fal fa-ticket-alt" aria-hidden="true"></i><span>Tour sắp có<br>Nhắn để
                                        được báo</span>
                                </a>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        @endif

        @if ($others->isNotEmpty())
            <div class="tvx-others-wrap">
                <h2 class="tvx-others__title">Lật sang trang khác…</h2>
                <div class="tvx-others">
                    @foreach ($others as $it)
                        <a href="{{ route('explore', ['inspiration' => $it->slug]) }}"
                            class="tvx-card tvx-card--{{ $it->slug }}">
                            @if ($it->image)
                                <img class="tvx-card__img" src="{{ $it->image }}" alt="" loading="lazy"
                                    decoding="async">
                            @else
                                <i class="fas {{ $it->icon }} tvx-card__ghost" aria-hidden="true"></i>
                            @endif
                            <span class="tvx-card__shade" aria-hidden="true"></span>
                            <span class="tvx-card__body">
                                <span class="tvx-card__icon" aria-hidden="true"><i
                                        class="fas {{ $it->icon }}"></i></span>
                                <span class="tvx-card__text"><strong>{{ $it->name }}</strong></span>
                                <span class="tvx-card__arrow" aria-hidden="true"><i
                                        class="fal fa-arrow-right"></i></span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</section>

@include('clients.blocks.new_letter')
@include('clients.blocks.footer')
