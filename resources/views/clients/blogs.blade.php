@include('clients.blocks.header')
@include('clients.blocks.banner', [
    'title' => 'Cẩm nang du lịch',
    'subtitle' => 'Những gợi ý nhỏ để mỗi chuyến đi trở nên đáng nhớ hơn.',
])

@php
    $posts = [
        [
            'img' => 'blog-list1.jpg',
            'tag' => 'Cảm hứng',
            'title' => '5 cách chọn một hành trình thật sự hợp với bạn',
            'date' => '12/09/2026',
            'desc' => 'Từ ngân sách, nhịp độ đến sở thích thiên nhiên — vài tiêu chí giúp bạn chọn tour dễ hơn.',
        ],
        [
            'img' => 'blog-list2.jpg',
            'tag' => 'Biển & nghỉ dưỡng',
            'title' => 'Một ngày chậm lại giữa biển xanh và nắng vàng',
            'date' => '05/09/2026',
            'desc' => 'Gợi ý cách tận hưởng một chuyến đi ven biển mà không cần lịch trình quá dày.',
        ],
        [
            'img' => 'blog-list3.jpg',
            'tag' => 'Kinh nghiệm',
            'title' => 'Checklist trước khi lên đường cho chuyến đi nhiều ngày',
            'date' => '28/08/2026',
            'desc' => 'Những thứ nên kiểm tra trước ngày khởi hành để hành trình nhẹ đầu hơn.',
        ],
        [
            'img' => 'blog-list4.jpg',
            'tag' => 'Điểm đến',
            'title' => 'Đi đâu khi bạn muốn vừa khám phá vừa thư giãn?',
            'date' => '19/08/2026',
            'desc' => 'Một vài kiểu điểm đến cân bằng giữa trải nghiệm thiên nhiên và thời gian nghỉ ngơi.',
        ],
        [
            'img' => 'blog-list5.jpg',
            'tag' => 'Travel tips',
            'title' => 'Cách đọc lịch trình tour để biết chuyến đi có hợp mình không',
            'date' => '11/08/2026',
            'desc' => 'Đừng chỉ nhìn số ngày — hãy đọc kỹ nhịp di chuyển, thời gian tự do và điểm dừng.',
        ],
    ];
@endphp

<section class="blog-list-page py-100 rel z-1">
    <div class="container">
        <div class="tv-blog-intro">
            <span class="tv-tag"><i class="fal fa-compass"></i> Travel journal</span>
            <h2>Đi để thấy nhiều hơn, chuẩn bị để tận hưởng nhiều hơn.</h2>
            <p>Khám phá những câu chuyện, kinh nghiệm và gợi ý điểm đến được trình bày ngắn gọn, dễ đọc.</p>
        </div>

        <div class="tv-blog-grid">
            @foreach ($posts as $post)
                <article class="tv-blog-card">
                    <a class="tv-blog-card__media" href="{{ route('blog-detail') }}">
                        <img loading="lazy" src="{{ asset('clients/assets/images/blog/' . $post['img']) }}"
                            alt="{{ $post['title'] }}">
                        <span>{{ $post['tag'] }}</span>
                    </a>
                    <div class="tv-blog-card__body">
                        <div class="tv-blog-card__meta"><i class="fal fa-calendar-alt"></i> {{ $post['date'] }}</div>
                        <h3><a href="{{ route('blog-detail') }}">{{ $post['title'] }}</a></h3>
                        <p>{{ $post['desc'] }}</p>
                        <a href="{{ route('blog-detail') }}" class="tv-blog-card__more">Đọc bài viết <i
                                class="fal fa-arrow-right"></i></a>
                    </div>
                </article>
            @endforeach
        </div>
    </div>
</section>

@include('clients.blocks.footer')
