@include('clients.blocks.header')

@php
    // Ảnh banner riêng cho Blog nếu có (banner-blog.jpg), chưa có thì dùng ảnh cũ
    $blogBanner = file_exists(public_path('clients/assets/images/banner/banner-blog.jpg'))
        ? 'banner-blog.jpg'
        : 'banner-about.jpg';

    // Tô sáng từ khóa đang tìm trong tiêu đề/mô tả (escape trước rồi mới chèn <mark> nên an toàn)
    $hl = function (string $text) use ($q) {
        $safe = e($text);
        if ($q === '') {
            return $safe;
        }
        return preg_replace('/(' . preg_quote(e($q), '/') . ')/iu', '<mark>$1</mark>', $safe);
    };
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

                {{-- Tìm kiếm ngay đầu danh sách (trên điện thoại ô tìm ở cột phải bị đẩy xuống cuối trang) --}}
                <form class="px-search px-search--top" action="{{ route('blogs') }}" method="GET" role="search">
                    <input type="search" name="q" value="{{ $q }}"
                        placeholder="Tìm bài viết: Phú Quốc, ẩm thực…" aria-label="Tìm bài viết" maxlength="100">
                    @if ($cat !== '')
                        <input type="hidden" name="cat" value="{{ $cat }}">
                    @endif
                    <button type="submit" aria-label="Tìm"><i class="fal fa-search" aria-hidden="true"></i></button>
                </form>

                <nav class="px-chips" aria-label="Danh mục bài viết">
                    <a href="{{ route('blogs', array_filter(['q' => $q])) }}" class="{{ $cat === '' ? 'is-on' : '' }}"
                        @if ($cat === '') aria-current="page" @endif>Tất cả
                        <em>{{ $all->count() }}</em></a>
                    @foreach ($categories as $name => $count)
                        <a href="{{ route('blogs', array_filter(['cat' => $name, 'q' => $q])) }}"
                            class="{{ $cat === $name ? 'is-on' : '' }}"
                            @if ($cat === $name) aria-current="page" @endif>{{ $name }}
                            <em>{{ $count }}</em></a>
                    @endforeach
                </nav>

                {{-- Kết quả đang xem: đọc to cho trình đọc màn hình, có nút xóa lọc --}}
                <p class="px-blog__filter" role="status" aria-live="polite">
                    @if ($isFiltered)
                        <span><b>{{ $total }}</b> bài viết
                            @if ($cat !== '')
                                trong <b>{{ $cat }}</b>
                            @endif
                            @if ($q !== '')
                                cho <b>“{{ $q }}”</b>
                            @endif
                        </span>
                        <a href="{{ route('blogs') }}"><i class="fal fa-times" aria-hidden="true"></i> Xóa lọc</a>
                    @else
                        <span><b>{{ $total }}</b> bài viết</span>
                    @endif
                </p>

                @if ($featured)
                    <article class="px-feature">
                        <a class="px-feature__img" href="{{ route('blog-detail', ['slug' => $featured['slug']]) }}"
                            tabindex="-1" aria-hidden="true">
                            <img src="{{ asset('clients/assets/images/blog/' . $featured['image']) }}" alt=""
                                width="800" height="520" fetchpriority="high" decoding="async">
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
                    <h2 class="px-h2 px-blog__h">{{ $isFiltered ? 'Kết quả' : 'Bài viết mới' }}</h2>
                    <div class="px-cards">
                        @foreach ($rest as $post)
                            @include('clients.partials.blog-card', ['post' => $post, 'hl' => $hl])
                        @endforeach
                    </div>

                    {{-- Phân trang (chỉ hiện khi có từ 2 trang trở lên) --}}
                    @if ($rest->hasPages())
                        <nav class="px-pager" aria-label="Phân trang bài viết">
                            @if ($rest->currentPage() > 1)
                                <a href="{{ $rest->url($rest->currentPage() - 1) }}" rel="prev"><i
                                        class="fal fa-chevron-left" aria-hidden="true"></i> Trước</a>
                            @endif
                            @for ($i = 1; $i <= $rest->lastPage(); $i++)
                                <a href="{{ $rest->url($i) }}"
                                    class="{{ $i === $rest->currentPage() ? 'is-on' : '' }}"
                                    @if ($i === $rest->currentPage()) aria-current="page" @endif>{{ $i }}</a>
                            @endfor
                            @if ($rest->hasMorePages())
                                <a href="{{ $rest->nextPageUrl() }}" rel="next">Sau <i class="fal fa-chevron-right"
                                        aria-hidden="true"></i></a>
                            @endif
                        </nav>
                    @endif
                @elseif (!$featured)
                    {{-- Không có kết quả: nói rõ vì sao và đưa ra lối đi tiếp --}}
                    <div class="px-empty">
                        <i class="fal fa-search" aria-hidden="true"></i>
                        <h2>Chưa có bài viết phù hợp</h2>
                        <p>
                            @if ($q !== '')
                                Không có bài nào chứa “{{ $q }}”. Hãy thử từ khóa ngắn hơn hoặc xem theo
                                danh mục.
                            @else
                                Danh mục này chưa có bài viết. Hãy xem các danh mục khác nhé.
                            @endif
                        </p>
                        <div class="px-empty__chips">
                            @foreach ($categories as $name => $count)
                                @if ($count > 0)
                                    <a href="{{ route('blogs', ['cat' => $name]) }}">{{ $name }}</a>
                                @endif
                            @endforeach
                        </div>
                        <a class="px-btn px-btn--cta" href="{{ route('blogs') }}">Xem tất cả bài viết</a>
                    </div>
                @endif
            </div>

            <aside class="px-blog__side">
                <section class="px-box">
                    <h3>Bài viết mới nhất</h3>
                    <ul class="px-latest">
                        @foreach ($latest as $p)
                            <li>
                                <a href="{{ route('blog-detail', ['slug' => $p['slug']]) }}">
                                    <img src="{{ asset('clients/assets/images/blog/' . $p['image']) }}" alt=""
                                        width="72" height="72" loading="lazy">
                                    <span><b>{{ $p['title'] }}</b><small>{{ \Carbon\Carbon::parse($p['date'])->format('d/m/Y') }}</small></span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>

                {{-- Đường dẫn từ nội dung sang chuyển đổi: đọc xong thì xem tour --}}
                <a class="px-side-img" href="{{ route('tours') }}">
                    <img src="{{ asset('clients/assets/images/blog/' . ($latest->first()['image'] ?? 'blog1.jpg')) }}"
                        alt="" loading="lazy">
                    <span><b>Sẵn sàng cho chuyến đi?</b><small>Xem các tour đang mở đặt</small></span>
                </a>
            </aside>
        </div>
    </div>
</main>

@include('clients.blocks.footer')
