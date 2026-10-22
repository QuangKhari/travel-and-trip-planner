{{-- Breadcrumb + JSON-LD BreadcrumbList. $crumbs = [[nhãn, url|null], ...]; mục cuối là trang hiện tại (url = null). --}}
@php
    $ld = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => collect($crumbs)->values()->map(fn($c, $i) => array_filter([
            '@type' => 'ListItem',
            'position' => $i + 1,
            'name' => $c[0],
            'item' => $c[1] ?? null,
        ]))->all(),
    ];
@endphp
<nav aria-label="breadcrumb">
    <ol class="gx-crumb">
        @foreach ($crumbs as $c)
            <li>
                @if (!empty($c[1]))
                    <a href="{{ $c[1] }}">{{ $c[0] }}</a>
                @else
                    <span aria-current="page">{{ $c[0] }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
<script type="application/ld+json">{!! json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
