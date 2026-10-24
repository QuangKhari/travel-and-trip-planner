{{-- Một component thẻ cho mọi loại nội dung. $c: kind, slug, title, badge, meta, text, image, credit, url. --}}
@php
    $tripTypes = ['destination', 'food', 'event'];
    $loading = $loading ?? 'lazy';
@endphp
<article class="gx-card">
    <span class="gx-card__img">
        @if ($c->image)
            <img src="{{ $c->image }}" alt="{{ $c->title }}" loading="{{ $loading }}" decoding="async" width="640"
                height="400">
        @endif
        @if (!empty($c->must))
            <span class="gx-card__must">Phải thử</span>
        @endif
        @if ($c->badge)
            <span class="gx-card__badge">{{ $c->badge }}</span>
        @endif
    </span>
    <div class="gx-card__body">
        @if (!empty($c->chip))
            <p class="gx-card__chip">{{ $c->chip }}</p>
        @endif
        <h3 class="gx-card__title"><a href="{{ $c->url }}">{{ $c->title }}</a></h3>
        @if ($c->meta)
            <p class="gx-card__meta">{{ $c->meta }}</p>
        @endif
        @if ($c->text)
            <p class="gx-card__text">{{ \Illuminate\Support\Str::limit(strip_tags($c->text), 160) }}</p>
        @endif
        <div class="gx-card__foot">
            <span class="gx-card__credit">
                @if ($c->image && $c->credit)
                    Ảnh: {{ \Illuminate\Support\Str::limit($c->credit, 40) }}
                @endif
            </span>
            @if (in_array($c->kind, $tripTypes, true))
                <button type="button" class="gx-add" data-trip-add data-type="{{ $c->kind }}"
                    data-slug="{{ $c->slug }}" data-name="{{ $c->title }}" data-url="{{ $c->url }}"
                    aria-pressed="false">+ Thêm vào chuyến đi</button>
            @endif
        </div>
    </div>
</article>
