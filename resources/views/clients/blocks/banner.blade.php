<!-- Page Banner (Travel theme v2) -->
<section class="tv-banner">
    <div class="tv-banner__bg" style="background-image:url('{{ asset('clients/assets/images/banner/banner.jpg') }}');">
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
    <svg class="tv-wave" viewBox="0 0 1440 100" preserveAspectRatio="none" aria-hidden="true">
        <path d="M0 45 Q180 100 380 58 T760 62 T1130 40 T1440 68 V100 H0Z" />
    </svg>
</section>
<!-- Page Banner End -->
