{{-- Thẻ trả lời nhanh + gợi ý lập chuyến từ Search Intent. Biến: $intent, $quick, $plannerUrl --}}
@if (!empty($quick))
    <section class="gx-box" aria-label="Trả lời nhanh">
        <h2>{{ $quick['title'] }}</h2>
        <p>{{ $quick['text'] }}</p>
        <p><a href="{{ $quick['url'] }}">Xem chi tiết tháng {{ $intent['month'] }}</a></p>
    </section>
@endif
@if (!empty($plannerUrl))
    <section class="gx-box" aria-label="Lập chuyến đi">
        <h2>Lập chuyến đi theo ý bạn</h2>
        <p>
            Mình hiểu: {{ $intent['destination']['name'] }}{{ $intent['days'] ? ', ' . $intent['days'] . ' ngày' : '' }}{{ $intent['month'] ? ', tháng ' . $intent['month'] : '' }}{{ $intent['interests'] ? ', sở thích: ' . implode(', ', array_map(fn($k) => config("interests.$k.label", $k), $intent['interests'])) : '' }}.
        </p>
        <p><a class="gx-btn" href="{{ $plannerUrl }}">Lập chuyến đi này</a></p>
    </section>
@endif
