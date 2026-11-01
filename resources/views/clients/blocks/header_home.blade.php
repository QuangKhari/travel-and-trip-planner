<!DOCTYPE html>
<html lang="zxx">

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
    <title>TRAVEL - Travel & {{ $title ?? '' }}</title>
    <!-- Favicon Icon -->
    <link rel="shortcut icon" href="{{ asset('clients/assets/images/logos/favicon.png') }}" type="image/x-icon">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&amp;display=swap"
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
    <!-- Datetime picker -->
    <link rel="stylesheet" href="{{ asset('clients/assets/css/jquery.datetimepicker.min.css') }}" />
    <!-- Custom css -->
    <link rel="stylesheet" href="{{ asset('clients/assets/css/custom-css.css') }}">

    <!-- jQuery UI -->
    <link rel="stylesheet" href="{{ asset('clients/assets/css/jquery-ui.min.css') }}">

    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>

    <!-- Main css -->
    <link rel="stylesheet" href="{{ asset('clients/assets/css/css-login/style.css') }}">

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
    <link rel="stylesheet" href="{{ asset('clients/assets/css/travel-theme.css') }}?v=3">
    <link rel="stylesheet" href="{{ asset('clients/assets/css/inspiration.css') }}?v=2">
    <link rel="stylesheet" href="{{ asset('clients/assets/css/inspiration-hub.css') }}?v=3">
    <link rel="stylesheet" href="{{ asset('clients/assets/css/tv-weather.css') }}?v=1">
    <link rel="stylesheet" href="{{ asset('clients/assets/css/gx-guide.css') }}?v=1">
    <link rel="stylesheet" href="{{ asset('clients/assets/css/mobile-nav.css') }}?v=1">
    <link rel="stylesheet" href="{{ asset('clients/assets/css/site-ux.css') }}?v=1">
    {{-- Giao diện mới: phải nằm CUỐI <head> --}}
    <link rel="stylesheet" href="{{ asset('clients/assets/css/tv3.css') }}?v=1">
    <link rel="stylesheet" href="{{ asset('clients/assets/css/tv3-home.css') }}?v=5">
</head>

<body class="tv-home">
    <div class="page-wrapper">

        @include('clients.blocks.site_header')
