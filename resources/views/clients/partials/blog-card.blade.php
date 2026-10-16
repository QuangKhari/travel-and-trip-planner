{{-- Thẻ bài viết. Biến: $post --}}
<article class="px-card">
    <a class="px-card__img" href="{{ route('blog-detail', ['slug' => $post['slug']]) }}" tabindex="-1" aria-hidden="true">
        <img src="{{ asset('clients/assets/images/blog/' . $post['image']) }}" alt="" width="640" height="420"
            loading="lazy" decoding="async">
        <span class="px-card__tag">{{ $post['category'] }}</span>
    </a>
    <div class="px-card__body">
        <h3><a href="{{ route('blog-detail', ['slug' => $post['slug']]) }}">{!! isset($hl) ? $hl($post['title']) : e($post['title']) !!}</a></h3>
        <p>{!! isset($hl) ? $hl($post['excerpt']) : e($post['excerpt']) !!}</p>
        <div class="px-card__meta">
            <span><i class="fal fa-calendar-alt"
                    aria-hidden="true"></i>{{ \Carbon\Carbon::parse($post['date'])->format('d/m/Y') }}</span>
            <span><i class="fal fa-clock" aria-hidden="true"></i>{{ $post['read'] }} phút đọc</span>
        </div>
    </div>
</article>
