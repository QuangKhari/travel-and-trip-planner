{{--
  Dải "Món ngon nên thử" dạng accordion ảnh dọc (học từ GoVietnam). Rê chuột/focus vào lát nào thì lát đó nở ra, hiện màu và tên món.
  Tham số: $items (thẻ món có ảnh), $moreUrl, $moreLabel. Cần gx-discover.css + gx-discover.js. Mobile: cuộn ngang.
--}}
@php $fa = collect($items)->filter(fn($c) => !empty($c->image))->take(6)->values(); @endphp
@if ($fa->count() >= 4)
    <section class="hs hs-food" id="am-thuc-nen-thu" aria-labelledby="am-thuc-nen-thu-h">
        <div class="container">
            <header class="hs-head">
                <div>
                    <p class="hs-eyebrow">Ăn gì</p>
                    <h2 id="am-thuc-nen-thu-h">Món ngon nên thử</h2>
                </div>
                @isset($moreUrl)
                    <p class="hs-more hs-more--top"><a href="{{ $moreUrl }}">{{ $moreLabel ?? 'Tất cả' }} →</a></p>
                @endisset
            </header>
            <ul class="fa" data-fa>
                @foreach ($fa as $i => $c)
                    <li class="fa-item {{ $i === 0 ? 'is-on' : '' }}">
                        <a class="fa-link" href="{{ $c->url }}" aria-label="{{ $c->title }}">
                            <img src="{{ $c->image }}" alt="" loading="lazy" decoding="async" width="640" height="800">
                            @if (!empty($c->must))<span class="fa-must">Phải thử</span>@endif
                            <span class="fa-v" aria-hidden="true">{{ $c->title }}</span>
                            <span class="fa-cap">
                                <b>{{ $c->title }}</b>
                                @if ($c->meta)<small>{{ $c->meta }}</small>@endif
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>
@else
    @include('clients.blocks.home_rail', [
        'id' => 'am-thuc-nen-thu',
        'eyebrow' => 'Ẩm thực',
        'title' => 'Món nên thử',
        'items' => $items,
        'moreUrl' => $moreUrl ?? null,
        'moreLabel' => $moreLabel ?? 'Tất cả',
    ])
@endif
