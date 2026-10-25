{{-- "Thời điểm đi". Biến: $advice (12 tháng từ TravelAdvice::forDestination), $adviceBest (chuỗi "T10–T12"), $destination.
     Mặc định mở tháng hiện tại; chọn tháng khác bằng nút (travel-advice.js). Không có JS: tháng hiện tại vẫn đọc được. --}}
@php
    $nowMonth = (int) date('n');
    $hasAny = collect($advice)->contains(fn($a) => $a['level'] !== 'unknown' || $a['hasData']);
@endphp
@if ($hasAny)
    <section class="gx-box ta" id="thoi-diem-di" data-advice-root aria-labelledby="taH">
        <h2 id="taH">Thời điểm đi {{ $destination->name }}</h2>
        @if ($adviceBest)
            <p class="ta-best">Đẹp nhất: <b>{{ $adviceBest }}</b></p>
        @endif

        <div class="ta-tabs" role="tablist" aria-label="Chọn tháng">
            @foreach ($advice as $a)
                <button type="button" role="tab" class="ta-tab ta-tab--{{ $a['level'] }} {{ $a['month'] === $nowMonth ? 'is-on' : '' }}"
                    id="taT{{ $a['month'] }}" aria-controls="taP{{ $a['month'] }}"
                    aria-selected="{{ $a['month'] === $nowMonth ? 'true' : 'false' }}" data-advice-tab="{{ $a['month'] }}">T{{ $a['month'] }}</button>
            @endforeach
        </div>

        @foreach ($advice as $a)
            <div class="ta-panel" role="tabpanel" id="taP{{ $a['month'] }}" aria-labelledby="taT{{ $a['month'] }}"
                data-advice-panel="{{ $a['month'] }}" @if ($a['month'] !== $nowMonth) hidden @endif>
                <p class="ta-head">
                    <span class="ta-stars" aria-label="{{ $a['stars'] }} trên 5 sao">
                        @for ($i = 1; $i <= 5; $i++)<i class="{{ $i <= $a['stars'] ? 'on' : '' }}" aria-hidden="true">★</i>@endfor
                    </span>
                    <b>Tháng {{ $a['month'] }}: {{ $a['label'] }}</b>
                </p>
                <p class="ta-sum">{{ $a['summary'] }}</p>

                <div class="ta-cols">
                    @if ($a['suitable'])
                        <div>
                            <h3>Hợp với</h3>
                            <ul>@foreach ($a['suitable'] as $s)<li>{{ $s }}</li>@endforeach</ul>
                        </div>
                    @endif
                    @if ($a['caution'])
                        <div>
                            <h3>Cần lưu ý</h3>
                            <ul>@foreach ($a['caution'] as $s)<li>{{ $s }}</li>@endforeach</ul>
                        </div>
                    @endif
                    @if ($a['packing'])
                        <div>
                            <h3>Nên mang</h3>
                            <ul>@foreach ($a['packing'] as $s)<li>{{ $s }}</li>@endforeach</ul>
                        </div>
                    @endif
                </div>
            </div>
        @endforeach

        <p class="gx-legend">{{ \App\Services\Content\TravelAdvice::DISCLAIMER }}</p>
    </section>
@endif
