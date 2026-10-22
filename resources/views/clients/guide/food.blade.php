@include('clients.blocks.header')
{{-- /am-thuc/{slug} — biến: $food, $cover, $sameDest, $variants, $events, $marks --}}
<main class="gx-page" id="main">
    <div class="gx-wrap">
        @include('clients.partials.gx.crumb', ['crumbs' => array_values(array_filter([
            ['Trang chủ', route('home')], ['Ẩm thực', route('foods.index')],
            $food->destSlug ? [$food->destName, route('destination.show', ['slug' => $food->destSlug])] : null,
            [$food->name, null],
        ]))])
        <header class="gx-head">
            <p class="gx-eyebrow">{{ $food->category ?: 'Món ăn' }}@if ($food->destProvince) · {{ $food->destProvince }}@endif</p>
            <h1>{{ $food->name }}</h1>
        </header>
        @if ($cover)
            <figure class="gx-cover"><img src="{{ $cover }}" alt="{{ $food->name }}" fetchpriority="high"></figure>
        @endif
        <div class="gx-actions">
            @include('clients.partials.gx.tripbtn', ['type' => 'food', 'slug' => $food->slug, 'name' => $food->name, 'url' => route('foods.show', ['slug' => $food->slug])])
            @include('clients.partials.gx.marks', ['refType' => 'food', 'slug' => $food->slug, 'mark' => 'tried', 'on' => isset($marks['tried']), 'labels' => ['Tôi đã thử', '✓ Đã thử']])
            @include('clients.partials.gx.marks', ['refType' => 'food', 'slug' => $food->slug, 'mark' => 'saved', 'on' => isset($marks['saved']), 'labels' => ['Lưu', '✓ Đã lưu']])
        </div>
        <div class="gx-detail">
            <div>
                <section class="gx-box gx-text" id="gioi-thieu">
                    <h2>Giới thiệu</h2>
                    @foreach (preg_split('/\R{2,}/', trim((string) $food->description)) as $p)
                        <p>{{ $p }}</p>
                    @endforeach
                    @include('clients.partials.gx.attribution', ['row' => $food])
                </section>
                @if ($food->destSlug)
                    <section class="gx-box" id="xuat-xu">
                        <h2>Xuất xứ</h2>
                        <p><a href="{{ route('destination.show', ['slug' => $food->destSlug]) }}">{{ $food->destName }}</a>, {{ $food->destProvince }}.</p>
                    </section>
                @endif
                @if ($sameDest->isNotEmpty())
                    <section class="gx-box" id="cung-diem-den">
                        <h2>Món khác ở {{ $food->destName }}</h2>
                        <div class="gx-grid">@foreach ($sameDest as $c) @include('clients.partials.gx.card', ['c' => $c]) @endforeach</div>
                    </section>
                @endif
                @if ($variants->isNotEmpty())
                    <section class="gx-box" id="bien-the">
                        <h2>Cùng nhóm “{{ $food->category }}” ở nơi khác</h2>
                        <div class="gx-grid">@foreach ($variants as $c) @include('clients.partials.gx.card', ['c' => $c]) @endforeach</div>
                    </section>
                @endif
                @if ($events->isNotEmpty())
                    <section class="gx-box" id="le-hoi">
                        <h2>Lễ hội gần đó</h2>
                        <div class="gx-grid">@foreach ($events as $c) @include('clients.partials.gx.card', ['c' => $c]) @endforeach</div>
                    </section>
                @endif
            </div>
            <aside class="gx-toc" aria-label="Mục lục">
                <h2>Trong trang</h2>
                <ol>
                    <li><a href="#gioi-thieu">Giới thiệu</a></li>
                    @if ($food->destSlug)<li><a href="#xuat-xu">Xuất xứ</a></li>@endif
                    @if ($sameDest->isNotEmpty())<li><a href="#cung-diem-den">Món cùng điểm đến</a></li>@endif
                    @if ($variants->isNotEmpty())<li><a href="#bien-the">Cùng nhóm</a></li>@endif
                    @if ($events->isNotEmpty())<li><a href="#le-hoi">Lễ hội gần đó</a></li>@endif
                </ol>
            </aside>
        </div>
    </div>
</main>
@include('clients.blocks.footer')
