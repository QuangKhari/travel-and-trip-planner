{{-- Hàng thẻ "Khám phá theo cảm hứng" — nằm ngay dưới hero --}}
@php
    $interests = [
        ['key' => 'beach', 'icon' => 'fa-umbrella-beach', 'title' => 'Biển', 'kw' => 'biển', 'color' => '#0EA5C6'],
        ['key' => 'mount', 'icon' => 'fa-mountain', 'title' => 'Núi', 'kw' => 'núi', 'color' => '#2E9873'],
        ['key' => 'culture', 'icon' => 'fa-landmark', 'title' => 'Văn hóa', 'kw' => 'văn hóa', 'color' => '#F08A24'],
        ['key' => 'food', 'icon' => 'fa-utensils', 'title' => 'Ẩm thực', 'kw' => 'ẩm thực', 'color' => '#EF4466'],
        ['key' => 'city', 'icon' => 'fa-city', 'title' => 'Thành phố', 'kw' => 'thành phố', 'color' => '#7C5CE0'],
        ['key' => 'romance', 'icon' => 'fa-heart', 'title' => 'Lãng mạn', 'kw' => 'lãng mạn', 'color' => '#F43F5E'],
        ['key' => 'explore', 'icon' => 'fa-compass', 'title' => 'Khám phá', 'kw' => 'khám phá', 'color' => '#0E8F8A'],
    ];
@endphp

<section class="tv-interest">
    <div class="container">
        <div class="tv-interest__row">
            <div class="tv-hand">Khám phá<br>theo cảm hứng ↷</div>

            @foreach ($interests as $i => $it)
                <a href="{{ route('search-voice-text', ['keyword' => $it['kw']]) }}" class="tv-chip"
                    data-interest="{{ $it['key'] }}" style="--c: {{ $it['color'] }}" data-aos="fade-up"
                    data-aos-delay="{{ $i * 60 }}" data-aos-duration="800" data-aos-offset="20">
                    <i class="fas {{ $it['icon'] }}"></i>
                    <span>{{ $it['title'] }}</span>
                </a>
            @endforeach

            <a href="{{ route('tours') }}" class="tv-promo">
                <span>Việt Nam<br>đẹp lắm!</span>
                <b>→</b>
            </a>
        </div>
    </div>
</section>
