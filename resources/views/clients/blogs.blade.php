@include('clients.blocks.header')

@php
    // Ảnh banner riêng cho Blog nếu có (banner-blog.jpg), chưa có thì dùng ảnh cũ
    $blogBanner = file_exists(public_path('clients/assets/images/banner/banner-blog.jpg'))
        ? 'banner-blog.jpg'
        : 'banner-about.jpg';
@endphp

@include('clients.blocks.banner', [
    'title' => 'Blog',
    'subtitle' => 'Cảm hứng du lịch, kinh nghiệm và những câu chuyện thú vị',
    'bannerImage' => $blogBanner,
])

<main class="px-blog">
    <div class="container">
        <div class="px-blog__layout">
            <div class="px-blog__main">

                @if ($cat !== '' || $q !== '')
                    <p class="px-blog__filter">
                        Đang xem:
                        @if ($cat !== '')
                            <b>{{ $cat }}</b>
                        @endif
                        @if ($q !== '')
                            <b>“{{ $q }}”</b>
                        @endif
                        · {{ $posts->count() }} bài viết
                        <a href="{{ route('blogs') }}">Xóa lọc</a>
                    </p>
                @endif

                <div class="px-chips" role="navigation" aria-label="Danh mục bài viết">
                    <a href="{{ route('blogs') }}" class="{{ $cat === '' ? 'is-on' : '' }}">Tất cả</a>
                    @foreach ($categories as $name => $count)
                        <a href="{{ route('blogs', ['cat' => $name]) }}"
                            class="{{ $cat === $name ? 'is-on' : '' }}">{{ $name }}</a>
                    @endforeach
                </div>

                @if ($featured)
                    <article class="px-feature">
                        <a class="px-feature__img" href="{{ route('blog-detail', ['slug' => $featured['slug']]) }}">
                            <img src="{{ asset('clients/assets/images/blog/' . $featured['image']) }}"
                                alt="{{ $featured['title'] }}" decoding="async">
                            <span class="px-badge">Nổi bật</span>
                        </a>
                        <div class="px-feature__body">
                            <span class="px-card__tag px-card__tag--inline">{{ $featured['category'] }}</span>
                            <h2><a
                                    href="{{ route('blog-detail', ['slug' => $featured['slug']]) }}">{{ $featured['title'] }}</a>
                            </h2>
                            <p>{{ $featured['excerpt'] }}</p>
                            <div class="px-card__meta">
                                <span><i class="fal fa-calendar-alt"
                                        aria-hidden="true"></i>{{ \Carbon\Carbon::parse($featured['date'])->format('d/m/Y') }}</span>
                                <span><i class="fal fa-clock" aria-hidden="true"></i>{{ $featured['read'] }} phút
                                    đọc</span>
                            </div>
                            <a class="px-readmore" href="{{ route('blog-detail', ['slug' => $featured['slug']]) }}">Đọc
                                bài viết <i class="fal fa-arrow-right" aria-hidden="true"></i></a>
                        </div>
                    </article>
                @endif

                @if ($rest->count())
                    <h2 class="px-h2 px-blog__h">Bài viết mới</h2>
                    <div class="px-cards">
                        @foreach ($rest as $post)
                            @include('clients.partials.blog-card', ['post' => $post])
                        @endforeach
                    </div>
                @elseif (!$featured)
                    <div class="px-empty">
                        <i class="fal fa-search" aria-hidden="true"></i>
                        <p>Chưa có bài viết phù hợp. Thử chọn danh mục khác nhé.</p>
                        <a class="px-btn px-btn--cta" href="{{ route('blogs') }}">Xem tất cả bài viết</a>
                    </div>
                @endif
            </div>

            <aside class="px-blog__side">
                <form class="px-search" action="{{ route('blogs') }}" method="GET" role="search">
                    <input type="search" name="q" value="{{ $q }}" placeholder="Tìm bài viết…"
                        aria-label="Tìm bài viết">
                    @if ($cat !== '')
                        <input type="hidden" name="cat" value="{{ $cat }}">
                    @endif
                    <button type="submit" aria-label="Tìm"><i class="fal fa-search"></i></button>
                </form>

                <section class="px-box">
                    <h3>Danh mục</h3>
                    <ul class="px-cats">
                        <li><a href="{{ route('blogs') }}" class="{{ $cat === '' ? 'is-on' : '' }}"><span>Tất
                                    cả</span><em>{{ $all->count() }}</em></a></li>
                        @foreach ($categories as $name => $count)
                            <li><a href="{{ route('blogs', ['cat' => $name]) }}"
                                    class="{{ $cat === $name ? 'is-on' : '' }}"><span>{{ $name }}</span><em>{{ $count }}</em></a>
                            </li>
                        @endforeach
                    </ul>
                </section>

                <section class="px-box">
                    <h3>Bài viết mới nhất</h3>
                    <ul class="px-latest">
                        @foreach ($latest as $p)
                            <li>
                                <a href="{{ route('blog-detail', ['slug' => $p['slug']]) }}">
                                    <img src="{{ asset('clients/assets/images/blog/' . $p['image']) }}" alt=""
                                        loading="lazy">
                                    <span><b>{{ $p['title'] }}</b><small>{{ \Carbon\Carbon::parse($p['date'])->format('d/m/Y') }}</small></span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            </aside>
        </div>
    </div>
</main>

@include('clients.blocks.footer')
