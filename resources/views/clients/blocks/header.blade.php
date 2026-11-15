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
    {{-- PWA: cài lên màn hình chính, mở lại chuyến đi khi mất mạng (public/sw.js) --}}
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <meta name="theme-color" content="#167A85">
    <link rel="apple-touch-icon" href="{{ asset('clients/assets/images/pwa/icon-192.png') }}">
    {{-- Phông chữ: một yêu cầu cho mọi họ chữ, tải không chặn hiển thị (chữ hiện ngay bằng phông hệ thống, đổi khi phông về) --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    @php $__fonts = 'https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&family=Outfit:wght@300;400;500;600;700&family=Caveat:wght@600;700&family=Dancing+Script:wght@600;700&family=Playfair+Display:wght@600;700&display=swap'; @endphp
    <link rel="preload" as="style" href="{{ $__fonts }}">
    <link rel="stylesheet" href="{{ $__fonts }}" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="{{ $__fonts }}"></noscript>

    {{-- CSS dùng chung: gộp theo nhóm, đúng thứ tự cũ (config/assets.php, php artisan assets:bundle). Tệp ngoài giữ đúng chỗ giữa các nhóm. --}}
    {!! \App\Support\AssetBundle::tags('site-a.css') !!}
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    {!! \App\Support\AssetBundle::tags('site-b.css') !!}
    {{-- toastr chỉ cần khi hiện thông báo: tải không chặn lần vẽ đầu (CSS trang không đè lớp của toastr nên thứ tự không đổi kết quả) --}}
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css"></noscript>
    {{-- Travel Theme v2 trở đi: phải nằm SAU các CSS cũ để đè được --}}
    {!! \App\Support\AssetBundle::tags('site-c.css') !!}
    <script src="{{ asset('clients/assets/js/travel-motion.js') }}?v=1" defer></script>
    <script src="{{ asset('clients/assets/js/tv-final.js') }}?v=1" defer></script>
    <script src="{{ asset('clients/assets/js/tv-tours-10.js') }}?v=2" defer></script>
    <script>
        window.GX_SEARCH = {suggest: @json(url('/api/v1/search/suggest')), page: @json(route('site.search')), tours: @json(route('tours'))};
    </script>
    <script src="{{ asset('clients/assets/js/guide-search.js') }}?v=1" defer></script>
    {{-- Nature Luxury: lớp thiết kế cuối, giữ sau các CSS cũ --}}
    {!! \App\Support\AssetBundle::tags('site-d.css') !!}
    @if (request()->is('tours') || request()->is('diem-den') || request()->is('explore'))
        <link rel="stylesheet" href="{{ asset('clients/assets/css/blue-lagoon-tour-explore.css') }}?v=4">
        <link rel="stylesheet" href="{{ asset('clients/assets/css/blue-lagoon-layout-fix.css') }}?v=1">
    @endif
    <link rel="stylesheet" href="{{ asset('clients/assets/css/layout-offset.css') }}?v=2">
</head>

<body class="tv-sea page-{{ str_replace('.', '-', request()->route()?->getName() ?? 'page') }}">
    <div class="page-wrapper">
        <a class="tl-skip" href="#tl-main-start">Bỏ qua menu, đến nội dung chính</a>

        <!-- Preloader -->
        <div class="preloader">
            <div class="custom-loader"></div>
        </div>

        <!-- main header -->
        @include('clients.blocks.site_header', ['solid' => true])
        <span id="tl-main-start" tabindex="-1" class="tl-main-anchor"></span>
