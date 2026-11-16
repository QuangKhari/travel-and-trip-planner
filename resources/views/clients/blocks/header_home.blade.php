<!DOCTYPE html>
<html lang="vi">

<head>
    <!-- Required meta tags -->
    <meta charset="utf-8">
    <script>
        document.documentElement.classList.add('px-js')
    </script>
    <meta name="description" content="{{ \App\Support\Seo::DEFAULT_DESCRIPTION }}">
    <link rel="canonical" href="{{ url('/') }}">
    <meta property="og:site_name" content="Travela">
    <meta property="og:locale" content="vi_VN">
    <meta property="og:type" content="website">
    <meta property="og:title" content="Travela - Du lịch Việt Nam theo mùa đẹp">
    <meta property="og:description" content="{{ \App\Support\Seo::DEFAULT_DESCRIPTION }}">
    <meta property="og:url" content="{{ url('/') }}">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

    <!-- Title -->
    <title>{{ config('site.name', 'Travela') }} - {{ $title ?? 'Du lịch Việt Nam theo cách riêng' }}</title>
    <!-- Favicon Icon -->
    <link rel="shortcut icon" href="{{ asset('clients/assets/images/logos/favicon.png') }}" type="image/x-icon">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&amp;display=swap"
        rel="stylesheet">
    {{-- CSS trang chủ: gộp theo nhóm, đúng thứ tự cũ (config/assets.php: home-a/b/c, php artisan assets:bundle) --}}
    {!! \App\Support\AssetBundle::tags('home-a.css') !!}
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    {!! \App\Support\AssetBundle::tags('home-b.css') !!}

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">

    <!-- Ảnh chính của hero (Lý Sơn, từ hero.jpg) tải sớm cho LCP -->
    <link rel="preload" as="image" href="{{ asset('clients/assets/images/hero/hero-bg.webp') }}" type="image/webp"
        fetchpriority="high">
    <link rel="preload" as="image" href="{{ asset('clients/assets/images/hero/hero-card.webp') }}" type="image/webp">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&family=Caveat:wght@600;700&display=swap"
        rel="stylesheet">
    {{-- Lớp giao diện: travel-theme -> ... -> shell (tv3) -> tv3-home -> Nature Luxury, phải nằm CUỐI <head> --}}
    {!! \App\Support\AssetBundle::tags('home-c.css') !!}
</head>

<body class="tv-home">
    <div class="page-wrapper">
        <a class="tl-skip" href="#tl-main-start">Bỏ qua menu, đến nội dung chính</a>

        @include('clients.blocks.site_header')
        <span id="tl-main-start" tabindex="-1" class="tl-main-anchor"></span>
