<!DOCTYPE html>
<html lang="vi">

<head>
    <!-- Required meta tags -->
    <meta charset="utf-8">
    <script>
        document.documentElement.classList.add('px-js')
    </script>
    @php
        $__seoDesc = \App\Support\Seo::text($metaDescription ?? \App\Support\Seo::DEFAULT_DESCRIPTION);
        $__seoUrl = $canonical ?? \App\Support\Seo::canonical();
        $__seoNoindex = ($noindex ?? false) || \App\Support\Seo::hasFilter($filters ?? null);
        $__seoTitle = 'Travela - Travel & ' . ($title ?? '');
        $__seoImage = !empty($metaImage) ? (preg_match('#^https?://#i', $metaImage) ? $metaImage : url($metaImage)) : null;
    @endphp
    <meta name="description" content="{{ $__seoDesc }}">
    <link rel="canonical" href="{{ $__seoUrl }}">
    @if ($__seoNoindex)
        <meta name="robots" content="noindex,follow">
    @endif
    <meta property="og:site_name" content="Travela">
    <meta property="og:locale" content="vi_VN">
    <meta property="og:type" content="{{ $ogType ?? 'website' }}">
    <meta property="og:title" content="{{ $__seoTitle }}">
    <meta property="og:description" content="{{ $__seoDesc }}">
    <meta property="og:url" content="{{ $__seoUrl }}">
    @if ($__seoImage)
        <meta property="og:image" content="{{ $__seoImage }}">
        <meta name="twitter:card" content="summary_large_image">
    @endif
    @foreach ($jsonLd ?? [] as $__ld)
        <script type="application/ld+json">{!! \App\Support\Seo::json($__ld) !!}</script>
    @endforeach
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

    <!-- Title -->
    <title>Travela - Travel & {{ $title ?? '' }}</title>
    <!-- Favicon Icon -->
    <link rel="shortcut icon" href="{{ asset('clients/assets/images/logos/favicon.png') }}" type="image/x-icon">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&amp;display=swap"
        rel="stylesheet">

    <link
        href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&family=Caveat:wght@600;700&family=Dancing+Script:wght@600;700&display=swap"
        rel="stylesheet">

    <!-- Flaticon -->
    <link rel="stylesheet" href="{{ asset('clients/assets/css/flaticon.min.css') }}">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="{{ asset('clients/assets/css/fontawesome-5.14.0.min.css') }}">
    <!-- Bootstrap -->
    <link rel="stylesheet" href="{{ asset('clients/assets/css/bootstrap.min.css') }}">
    <!-- Magnific Popup -->
    <link rel="stylesheet" href="{{ asset('clients/assets/css/magnific-popup.min.css') }}">
    <!-- Nice Select -->
    <link rel="stylesheet" href="{{ asset('clients/assets/css/nice-select.min.css') }}">
    <!-- Animate -->
    <link rel="stylesheet" href="{{ asset('clients/assets/css/aos.css') }}">
    <!-- Slick -->
    <link rel="stylesheet" href="{{ asset('clients/assets/css/slick.min.css') }}">
    <!-- Main Style -->
    <link rel="stylesheet" href="{{ asset('clients/assets/css/style.css') }}">
    <!-- jQuery UI -->
    <link rel="stylesheet" href="{{ asset('clients/assets/css/jquery-ui.min.css') }}">

    {{-- User Profile  --}}
    <link rel="stylesheet" href="{{ asset('clients/assets/css/user-profile.css') }}" />


    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>

    {{-- Login --}}
    <!-- Font Icon -->
    <link rel="stylesheet"
        href="{{ asset('clients/assets/css/css-login/fonts/material-icon/css/material-design-iconic-font.min.css') }}">

    <!-- Main css -->
    <link rel="stylesheet" href="{{ asset('clients/assets/css/css-login/style.css') }}">
    <!-- Custom css -->
    <link rel="stylesheet" href="{{ asset('clients/assets/css/custom-css.css') }}">
    <!-- Bộ thành phần lớp Khám phá + khay chuyến đi -->
    <link rel="stylesheet" href="{{ asset('clients/assets/css/explore-kit.css') }}?v=1">
    <!-- Giai đoạn C: cẩm nang, mùa đẹp, tìm kiếm có gợi ý -->
    <link rel="stylesheet" href="{{ asset('clients/assets/css/explore-c.css') }}?v=1">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">

    <!-- Travel Theme v2: phải nằm CUỐI <head> để đè được CSS cũ -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&family=Caveat:wght@600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('clients/assets/css/travel-theme.css') }}?v=2">
    {{-- SEA THEME: giao diện biển cho các trang con. Phải nằm SAU travel-theme.css --}}
    <link rel="stylesheet" href="{{ asset('clients/assets/css/sea-theme.css') }}?v=1">
    <link rel="stylesheet" href="{{ asset('clients/assets/css/client-ui-polish.css') }}?v=4">
    <link rel="stylesheet" href="{{ asset('clients/assets/css/travel-pages.css') }}?v=2">
    <link rel="stylesheet" href="{{ asset('clients/assets/css/inspiration.css') }}?v=2">
    <link rel="stylesheet" href="{{ asset('clients/assets/css/tv-pages.css') }}?v=1">
    <link rel="stylesheet" href="{{ asset('clients/assets/css/tv-pages-10.css') }}?v=1">
    <link rel="stylesheet" href="{{ asset('clients/assets/css/tv-watercolor.css') }}?v=1">
    <link rel="stylesheet" href="{{ asset('clients/assets/css/tv-watercolor-refine.css') }}?v=1">
    <link rel="stylesheet" href="{{ asset('clients/assets/css/tv-final.css') }}?v=1">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('clients/assets/css/tv-tours-10.css') }}?v=1">
    <link rel="stylesheet" href="{{ asset('clients/assets/css/ux-pass.css') }}?v=3">
    <link rel="stylesheet" href="{{ asset('clients/assets/css/tv-decor-fix.css') }}?v=1">
    <link rel="stylesheet" href="{{ asset('clients/assets/css/tv-sort.css') }}?v=2">
    <link rel="stylesheet" href="{{ asset('clients/assets/css/ux-watercolor.css') }}?v=1">
    {{-- Thiết kế lại trang /tours (hero, tìm kiếm, thẻ tour, CTA). Phải nằm SAU tv-sort.css và ux-watercolor.css --}}
    <link rel="stylesheet" href="{{ asset('clients/assets/css/tv-tours-v2.css') }}?v=1">
    <script src="{{ asset('clients/assets/js/travel-motion.js') }}?v=1" defer></script>
    <script src="{{ asset('clients/assets/js/tv-final.js') }}?v=1" defer></script>
    <script src="{{ asset('clients/assets/js/tv-tours-10.js') }}?v=1" defer></script>
    <script>
        window.GX_SEARCH = {suggest: @json(url('/api/v1/search/suggest')), page: @json(route('site.search')), tours: @json(route('tours'))};
    </script>
    <script src="{{ asset('clients/assets/js/guide-search.js') }}?v=1" defer></script>
</head>

<body class="tv-sea page-{{ str_replace('.', '-', request()->route()?->getName() ?? 'page') }}">
    <div class="page-wrapper">

        <!-- Preloader -->
        <div class="preloader">
            <div class="custom-loader"></div>
        </div>

        <!-- main header -->
        @include('clients.blocks.site_header', ['solid' => true])
