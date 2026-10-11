{{-- Banner trang con (Sea theme).
     Ảnh nền tự chọn theo tên route; muốn ép ảnh riêng: @include('clients.blocks.banner', ['bannerImage' => 'banner-tours.jpg'])
     Ảnh nằm trong public/clients/assets/images/banner/ --}}
@php
    $bannerMap = [
        'about' => 'banner-about.jpg',
        'destination' => 'banner-destination.jpg',
        'tours' => 'banner-tours.jpg',
        'search' => 'banner-tours.jpg',
        'booking' => 'banner-booking.jpg',
        'tour-booked' => 'banner-booking.jpg',
        'blogs' => 'banner-about.jpg',
        'blog-detail' => 'banner-about.jpg',
        'team' => 'banner-destination.jpg',
    ];
    $bannerFile = $bannerImage ?? ($bannerMap[request()->route()?->getName()] ?? 'banner.jpg');
@endphp
<!-- Page Banner (Sea theme) -->
<section class="tv-banner">
    <div class="tv-banner__bg"
        style="background-image:url('{{ asset('clients/assets/images/banner/' . $bannerFile) }}');">
    </div>
    <div class="container">
        <nav class="tv-crumb" aria-label="breadcrumb">
            <a href="{{ route('home') }}">Trang chủ</a>
            <i class="fas fa-chevron-right"></i>
            <span>{{ $title ?? '' }}</span>
        </nav>
        <h1 class="tv-banner__title">{{ $title ?? '' }}</h1>
        @isset($subtitle)
            <p class="tv-banner__sub">{{ $subtitle }}</p>
        @endisset
    </div>
    {{-- sóng cát (trôi chậm) nằm sau sóng nền trang --}}
    <svg class="tv-wave tv-wave--sand" viewBox="0 0 1440 100" preserveAspectRatio="none" aria-hidden="true">
        <path d="M0 52 Q200 8 420 46 T820 40 T1200 56 T1440 36 V100 H0Z" />
    </svg>
    <svg class="tv-wave" viewBox="0 0 1440 100" preserveAspectRatio="none" aria-hidden="true">
        <path d="M0 45 Q180 100 380 58 T760 62 T1130 40 T1440 68 V100 H0Z" />
    </svg>
</section>
<!-- Page Banner End -->
