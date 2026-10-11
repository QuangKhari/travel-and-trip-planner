@include('clients.blocks.header')
@include('clients.blocks.banner')

@php
    $domainNames = ['b' => 'Miền Bắc', 't' => 'Miền Trung', 'n' => 'Miền Nam'];
@endphp

<section class="tv-dest">
    <div class="container">
        <div class="td-section__head">
            <div>
                <h2 class="td-h2" style="margin-bottom:6px">Điểm đến đang có tour</h2>
                <p class="tv-muted" style="margin:0">{{ count($tours) }} hành trình đang mở bán. Chọn miền để thu hẹp danh
                    sách.</p>
            </div>
        </div>

        <div class="tv-chiprow" role="group" aria-label="Lọc theo miền" id="tvDomainFilter">
            <button type="button" class="tv-chipbtn is-on" data-domain="all">Tất cả</button>
            @foreach ($domainNames as $key => $label)
                <button type="button" class="tv-chipbtn" data-domain="{{ $key }}">{{ $label }}</button>
            @endforeach
        </div>

        <div class="tv-dest__grid" id="tvDestGrid">
            @foreach ($tours as $tour)
                <a class="tv-dest__item" data-domain="{{ $tour->domain }}"
                    href="{{ route('tour-detail', ['id' => $tour->tourId]) }}">
                    <img loading="lazy" decoding="async"
                        src="{{ \App\Support\TourImage::url($tour->images[0] ?? 'default.jpg', 800) }}"
                        alt="{{ $tour->title }}">
                    <span class="tv-dest__label">
                        <small>{{ $domainNames[$tour->domain] ?? '' }} · {{ $tour->time }}</small>
                        <b>{{ $tour->title }}</b>
                    </span>
                </a>
            @endforeach
        </div>
        <p class="tv-muted tv-dest__none" id="tvDestNone" hidden>Chưa có điểm đến nào ở khu vực này.</p>
    </div>
</section>

@include('clients.blocks.new_letter')
@include('clients.blocks.footer')
