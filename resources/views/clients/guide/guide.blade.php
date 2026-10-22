@php

    $g = $guide;
    $catSlug = $g->catSlug ?: 'khac';
    $catName = $g->catName ?: 'Khác';
    $guideUrl = route('guides.show', ['category' => $catSlug, 'slug' => $g->slug]);
    $desc = \App\Services\Content\GuideCatalog::excerpt($g->body, 158);
    $modified = $g->updatedOn ?: ($g->updatedAt ?? $g->createdAt ?? null);
    $coverAbs = $cover ? (preg_match('#^https?://#i', $cover) ? $cover : url($cover)) : null;
    $stale = $g->updatedOn && \Carbon\Carbon::parse($g->updatedOn)->lt(now()->subMonths(12));
    $plannerUrl = config('postcards.planner_url') ? url(config('postcards.planner_url')) : null;

    $metaDescription = $desc;
    $metaImage = $coverAbs;
    $ogType = 'article';
    $canonical = $guideUrl;
    $jsonLd = [[
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => $g->title,
        'description' => $desc,
        'mainEntityOfPage' => $guideUrl,
        'author' => ['@type' => 'Organization', 'name' => 'Travela'],
        'publisher' => ['@type' => 'Organization', 'name' => 'Travela'],
    ]
        + (!empty($g->createdAt) ? ['datePublished' => \Carbon\Carbon::parse($g->createdAt)->toDateString()] : [])
        + ($modified ? ['dateModified' => \Carbon\Carbon::parse($modified)->toDateString()] : [])
        + ($coverAbs ? ['image' => [$coverAbs]] : [])];
@endphp
@include('clients.blocks.header')
{{-- /cam-nang/{danh-muc}/{slug} — biến: $guide, $html (đã lọc), $toc, $related, $cover --}}
<main class="gx-page" id="main">
    <div class="gx-wrap">
        @include('clients.partials.gx.crumb', ['crumbs' => [['Trang chủ', route('home')], ['Cẩm nang', route('guides.index')], [$catName, route('guides.category', ['category' => $catSlug])], [$g->title, null]]])

        <header class="gx-head">
            <p class="gx-eyebrow">{{ $catName }}</p>
            <h1>{{ $g->title }}</h1>
            <p class="gx-lead">{{ $desc }}</p>
            @if ($g->updatedOn)
                <p class="gx-updated {{ $stale ? 'is-stale' : '' }}">
                    Cập nhật lần cuối: <time datetime="{{ \Carbon\Carbon::parse($g->updatedOn)->toDateString() }}">{{ \Carbon\Carbon::parse($g->updatedOn)->format('d/m/Y') }}</time>
                    @if ($stale)
                        <span>. Bài đã hơn 12 tháng, hãy đối chiếu lại với nguồn chính thức.</span>
                    @endif
                </p>
            @endif
        </header>

        @if ($cover)
            <figure class="gx-cover"><img src="{{ $cover }}" alt="{{ $g->title }}" loading="eager" fetchpriority="high"></figure>
        @endif

        <div class="gx-detail">
            <div>
                <article class="gx-box gx-text gx-prose" id="noi-dung">
                    {!! $html !!}
                    @include('clients.partials.gx.attribution', ['row' => $g])
                </article>

                @if (!empty($g->sourceUrl) && preg_match('#^https?://#i', $g->sourceUrl))
                    <section class="gx-box" id="nguon">
                        <h2>Nguồn chính thức</h2>
                        <p>Thông tin dễ thay đổi trong bài này nên được đối chiếu với nguồn sau, hãy kiểm tra lại trước khi đi:
                            <a href="{{ $g->sourceUrl }}" target="_blank" rel="noopener noreferrer">{{ parse_url($g->sourceUrl, PHP_URL_HOST) }}</a>.</p>
                    </section>
                @endif

                <section class="gx-box gx-cta" id="tiep-theo">
                    <h2>Bước tiếp theo</h2>
                    <p>Đã biết cần chuẩn bị gì, giờ chọn nơi đi.</p>
                    <p class="gx-cta__row">
                        <a class="gx-btn" href="{{ route('seasons.matrix') }}">Nên đi tháng nào</a>
                        <a class="gx-btn gx-btn--ghost" href="{{ route('destination') }}">Khám phá điểm đến</a>
                        <a class="gx-btn gx-btn--ghost" href="{{ $plannerUrl ?: route('tours') }}">{{ $plannerUrl ? 'Lên kế hoạch chuyến đi' : 'Xem tour' }}</a>
                    </p>
                </section>

                @if ($related->isNotEmpty())
                    <section class="gx-box" id="bai-lien-quan">
                        <h2>Bài liên quan</h2>
                        <div class="gx-grid">@foreach ($related as $c) @include('clients.partials.gx.card', ['c' => $c]) @endforeach</div>
                    </section>
                @endif
            </div>
            <aside class="gx-toc" aria-label="Mục lục">
                <h2>Trong bài</h2>
                <ol>
                    @foreach ($toc as $t)
                        <li><a href="#{{ $t['id'] }}">{{ $t['text'] }}</a></li>
                    @endforeach
                    @if (!empty($g->sourceUrl))<li><a href="#nguon">Nguồn chính thức</a></li>@endif
                    <li><a href="#tiep-theo">Bước tiếp theo</a></li>
                    @if ($related->isNotEmpty())<li><a href="#bai-lien-quan">Bài liên quan</a></li>@endif
                </ol>
            </aside>
        </div>
    </div>
</main>
@include('clients.blocks.footer')
