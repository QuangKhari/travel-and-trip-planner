{{-- Con số tổng ở chân trang, lấy từ SiteStats (cache 1 giờ). Rỗng thì không vẽ gì. --}}
@php $siteStats = app(\App\Services\Content\SiteStats::class)->get(); @endphp
@if ($siteStats)
    <p class="tv-stats" aria-label="Quy mô nội dung">
        @foreach ($siteStats as $label => $n)
            <span><b>{{ number_format($n, 0, ',', '.') }}</b> {{ mb_strtolower($label) }}</span>
        @endforeach
    </p>
@endif
