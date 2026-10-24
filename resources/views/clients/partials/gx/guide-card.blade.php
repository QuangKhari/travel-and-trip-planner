{{-- Thẻ cẩm nang kiểu GoVietnam: biểu tượng + tiêu đề + một dòng mô tả. $c = thẻ từ GuideCatalog::card() (cần ->icon, ->title, ->desc, ->url). --}}
<a class="hb-card" href="{{ $c->url }}">
    <span class="hb-ic" aria-hidden="true"><i class="bx {{ $c->icon ?? 'bx-book-open' }}"></i></span>
    <span class="hb-tx">
        <b>{{ $c->title }}</b>
        @if (!empty($c->desc))<span class="hb-d">{{ $c->desc }}</span>@endif
    </span>
</a>
