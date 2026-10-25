@php
    $metaDescription = \App\Support\Seo::text($e->description);
    $metaImage = $cover;
@endphp
@include('clients.blocks.header')
{{-- /trai-nghiem/{slug} — biến: $e, $cover, $related, $tours --}}
<main class="gx-page" id="main">
    <div class="gx-wrap">
        @include('clients.partials.gx.crumb', ['crumbs' => array_values(array_filter([
            ['Trang chủ', route('home')], ['Trải nghiệm', route('experiences.index')],
            $e->destSlug ? [$e->destName, route('destination.show', ['slug' => $e->destSlug])] : null,
            [$e->name, null],
        ]))])
        <header class="gx-head">
            <p class="gx-eyebrow">{{ $e->category ?: 'Trải nghiệm' }}@if ($e->destProvince) · {{ $e->destProvince }}@endif</p>
            <h1>{{ $e->name }}</h1>
        </header>
        @if ($cover)
            <figure class="gx-cover"><img src="{{ $cover }}" alt="{{ $e->name }}" fetchpriority="high"></figure>
        @endif

        {{-- Bốn ô thông tin nhanh (chỉ hiện ô có dữ liệu) --}}
        @php
            $facts = array_filter([
                'Loại' => $e->category,
                'Thời lượng' => $e->duration,
                'Điểm đến' => $e->destName,
                'Miền' => \App\Services\Content\ContentCatalog::REGIONS[$e->destDomain ?? ''] ?? null,
            ]);
        @endphp
        @if ($facts)
            <dl class="ex-facts">
                @foreach ($facts as $k => $v)
                    <div><dt>{{ $k }}</dt><dd>{{ $v }}</dd></div>
                @endforeach
            </dl>
        @endif

        <div class="gx-detail">
            <div>
                <section class="gx-box gx-text" id="gioi-thieu">
                    <h2>Giới thiệu</h2>
                    @foreach (preg_split('/\R{2,}/', trim((string) $e->description)) as $p)
                        <p>{{ $p }}</p>
                    @endforeach
                    @include('clients.partials.gx.attribution', ['row' => $e])
                </section>

                {{-- Nối sang đặt tour: tour còn chỗ tại cùng điểm đến --}}
                <section class="gx-box ex-tours" id="tour">
                    <h2>Đi cùng tour{{ $e->destName ? ' tại ' . $e->destName : '' }}</h2>
                    @if ($tours)
                        <ul class="ex-tour-list">
                            @foreach ($tours as $t)
                                <li>
                                    <a href="{{ $t['url'] }}"><b>{{ $t['title'] }}</b><span>Khởi hành {{ $t['start'] }} · từ {{ $t['price'] }}</span></a>
                                </li>
                            @endforeach
                        </ul>
                        <p class="gx-more"><a href="{{ route('tours') }}">Xem tất cả tour →</a></p>
                    @else
                        <p>Hiện chưa có tour sắp khởi hành gắn với điểm đến này. <a href="{{ route('tours') }}">Xem tất cả tour</a> hoặc thêm trải nghiệm này vào kế hoạch riêng bằng trợ lý lập kế hoạch.</p>
                    @endif
                </section>

                @if ($related->isNotEmpty())
                    <section class="gx-box" id="khac">
                        <h2>Trải nghiệm khác nên xem</h2>
                        <div class="gx-grid">@foreach ($related as $c) @include('clients.partials.gx.card', ['c' => $c]) @endforeach</div>
                    </section>
                @endif
            </div>
            <aside class="gx-toc" aria-label="Mục lục">
                <h2>Trong trang</h2>
                <ol>
                    <li><a href="#gioi-thieu">Giới thiệu</a></li>
                    <li><a href="#tour">Đi cùng tour</a></li>
                    @if ($related->isNotEmpty())<li><a href="#khac">Trải nghiệm khác</a></li>@endif
                </ol>
            </aside>
        </div>
    </div>
</main>
@include('clients.blocks.footer')
