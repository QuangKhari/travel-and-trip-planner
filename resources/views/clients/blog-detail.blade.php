@include('clients.blocks.header')

@php
    $blogBanner = file_exists(public_path('clients/assets/images/banner/banner-blog.jpg'))
        ? 'banner-blog.jpg'
        : 'banner-about.jpg';
    $sections = $post['sections'] ?? [];
@endphp

{{-- Dải ảnh đầu trang (không lặp lại tiêu đề bài, để tiêu đề nằm trong thẻ bài viết) --}}
<section class="px-bhero" style="background-image:url('{{ asset('clients/assets/images/banner/' . $blogBanner) }}')"
    aria-hidden="true">
    <svg class="px-wave" viewBox="0 0 1440 80" preserveAspectRatio="none">
        <path d="M0 40 Q180 80 380 48 T760 44 T1130 28 T1440 48 V80 H0Z" />
    </svg>
</section>

<main class="px-blog px-post">
    <div class="container">
        <nav class="px-crumb px-crumb--dark" aria-label="breadcrumb">
            <a href="{{ route('home') }}">Trang chủ</a>
            <i class="fas fa-chevron-right" aria-hidden="true"></i>
            <a href="{{ route('blogs') }}">Blog</a>
            <i class="fas fa-chevron-right" aria-hidden="true"></i>
            <a href="{{ route('blogs', ['cat' => $post['category']]) }}">{{ $post['category'] }}</a>
        </nav>

        <div class="px-blog__layout">
            <article class="px-article">
                <header>
                    <h1>{{ $post['title'] }}</h1>
                    <div class="px-card__meta">
                        <span><i class="fal fa-calendar-alt"
                                aria-hidden="true"></i>{{ \Carbon\Carbon::parse($post['date'])->format('d/m/Y') }}</span>
                        <span><i class="fal fa-clock" aria-hidden="true"></i>{{ $post['read'] }} phút đọc</span>
                        <span><i class="fal fa-folder-open" aria-hidden="true"></i>{{ $post['category'] }}</span>
                    </div>
                </header>

                <p class="px-article__intro">{{ $post['intro'] }}</p>

                @if (!empty($post['quote']))
                    <blockquote class="px-quote">
                        <i class="fas fa-quote-left" aria-hidden="true"></i>
                        <p>{{ $post['quote'] }}</p>
                    </blockquote>
                @endif

                @foreach ($sections as $i => $sec)
                    <section id="muc-{{ $i + 1 }}" class="px-article__sec">
                        <h2><span>{{ $i + 1 }}</span>{{ $sec[0] }}</h2>
                        @foreach ($sec[1] as $para)
                            <p>{{ $para }}</p>
                        @endforeach
                    </section>
                @endforeach

                <footer class="px-article__nav">
                    @if ($prev)
                        <a href="{{ route('blog-detail', ['slug' => $prev['slug']]) }}"><small><i
                                    class="fal fa-arrow-left"></i> Bài trước</small><b>{{ $prev['title'] }}</b></a>
                    @else
                        <span></span>
                    @endif
                    @if ($next)
                        <a href="{{ route('blog-detail', ['slug' => $next['slug']]) }}" class="is-next"><small>Bài sau
                                <i class="fal fa-arrow-right"></i></small><b>{{ $next['title'] }}</b></a>
                    @endif
                </footer>
            </article>

            <aside class="px-blog__side">
                @if (count($sections) > 1)
                    <section class="px-box px-toc">
                        <h3>Mục lục</h3>
                        <ol>
                            @foreach ($sections as $i => $sec)
                                <li><a href="#muc-{{ $i + 1 }}">{{ $sec[0] }}</a></li>
                            @endforeach
                        </ol>
                    </section>
                @endif

                <a class="px-side-img" href="{{ route('tours') }}">
                    <img src="{{ asset('clients/assets/images/blog/' . $post['image']) }}" alt=""
                        loading="lazy">
                    <span><b>Sẵn sàng cho chuyến đi?</b><small>Xem các tour đang mở đặt</small></span>
                </a>
            </aside>
        </div>

        @if ($related->count())
            <section class="px-blog__related">
                <h2 class="px-h2">Bài viết liên quan</h2>
                <div class="px-cards">
                    @foreach ($related as $p)
                        @include('clients.partials.blog-card', ['post' => $p])
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</main>

@include('clients.blocks.footer')
