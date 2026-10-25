@include('clients.blocks.header')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="{{ asset('clients/assets/css/planner.css') }}?v=1">

{{-- /len-ke-hoach/{id}. Biến: $payload (TripPlanner::payload), $client (dữ liệu cho planner.js).
     Danh sách ngày được JS vẽ từ #tpData; phần "Tóm tắt" do máy chủ tính sau mỗi lần lưu. --}}
@php
    $t = $payload['trip'];
    $money = fn($n) => number_format((float) $n, 0, ',', '.') . 'đ';
@endphp

<main class="tp tp--edit">
    <div class="container">
        <header class="tp-head">
            <p><a href="{{ route('planner') }}">← Chuyến đi của tôi</a></p>
            <h1>{{ $t['title'] }}</h1>
        </header>

        <div class="tp-edit">
            <div class="tp-col-main">
                <section class="tp-card" aria-labelledby="tpInfoH">
                    <h2 id="tpInfoH">Thông tin chuyến đi</h2>
                    <div class="tp-form tp-form--grid">
                        <label>Tên chuyến đi
                            <input type="text" id="tpTitle" value="{{ $t['title'] }}" maxlength="160">
                        </label>
                        <label>Ngày đi
                            <input type="date" id="tpStart" value="{{ $t['startDate'] }}">
                        </label>
                        <label>Số ngày
                            <input type="number" id="tpDays" min="1" max="{{ \App\Services\Planner\TripPlanner::MAX_DAYS }}" value="{{ $t['days'] }}">
                        </label>
                        <label>Số người
                            <input type="number" id="tpGroup" min="1" max="50" value="{{ $t['groupSize'] }}">
                        </label>
                        <label>Ngân sách (đ)
                            <input type="number" id="tpBudget" min="0" step="1000" value="{{ $t['budget'] !== null ? (int) $t['budget'] : '' }}">
                        </label>
                    </div>
                </section>

                <section class="tp-card" aria-labelledby="tpPlanH">
                    <h2 id="tpPlanH">Lịch theo ngày</h2>
                    <p class="tp-muted">Chọn ngày cho từng mục, dùng nút ↑ ↓ để đổi thứ tự. Bấm “Lưu thay đổi” để cập nhật quãng đường và cảnh báo.</p>
                    <div id="tpDaysBox"></div>
                    <p id="tpTotals" class="tp-totals" role="status"></p>
                    <div class="tp-actions">
                        <button type="button" id="tpSave" class="tp-btn">Lưu thay đổi</button>
                        <span id="tpMsg" class="tp-msg" role="status" aria-live="polite"></span>
                    </div>
                </section>
            </div>

            <aside class="tp-col-side">
                <section class="tp-card" aria-labelledby="tpMapH">
                    <h2 id="tpMapH">Bản đồ</h2>
                    <div id="tpMap" class="tp-map" role="img" aria-label="Bản đồ các điểm trong chuyến đi"></div>
                    <p class="tp-muted tp-small">Màu theo ngày. Bản đồ © OpenStreetMap.</p>
                </section>

                <section class="tp-card" aria-labelledby="tpSumH">
                    <h2 id="tpSumH">Tóm tắt</h2>
                    @if ($payload['warnings'])
                        <ul class="tp-warn">@foreach ($payload['warnings'] as $w)<li>{{ $w }}</li>@endforeach</ul>
                    @else
                        <p class="tp-ok">Chưa thấy vấn đề gì với lịch hiện tại.</p>
                    @endif

                    @if ($payload['advice'])
                        <h3>Thời điểm đi</h3>
                        <ul class="tp-list">
                            @foreach ($payload['advice'] as $a)
                                <li><b>{{ $a['dest'] }}</b>, tháng {{ $a['month'] }}: {{ $a['label'] }}@if ($a['note']) ({{ $a['note'] }})@endif</li>
                            @endforeach
                        </ul>
                    @endif

                    @php($withLegs = array_filter($payload['days'], fn($d) => $d['legs']))
                    @if ($withLegs)
                        <h3>Di chuyển (ước tính)</h3>
                        <ul class="tp-list">
                            @foreach ($withLegs as $d)
                                <li><b>Ngày {{ $d['day'] }}</b>: khoảng {{ $d['km'] }} km, {{ floor($d['minutes'] / 60) }}h{{ str_pad($d['minutes'] % 60, 2, '0', STR_PAD_LEFT) }}
                                    <ul>@foreach ($d['legs'] as $l)<li>{{ $l['from'] }} → {{ $l['to'] }}: {{ $l['km'] }} km, {{ $l['min'] }} phút</li>@endforeach</ul>
                                </li>
                            @endforeach
                        </ul>
                        <p class="tp-muted tp-small">{{ $payload['estimateNote'] }}</p>
                    @endif

                    <p class="tp-total-line">Chi phí dự kiến: <b>{{ $money($payload['totals']['cost']) }}</b>
                        @if ($payload['totals']['budget'] !== null)
                            / ngân sách {{ $money($payload['totals']['budget']) }}
                        @endif
                    </p>
                </section>

                <section class="tp-card" aria-labelledby="tpSuggH">
                    <h2 id="tpSuggH">Gợi ý thêm</h2>
                    <div id="tpSugg"></div>
                </section>

                <section class="tp-card" aria-labelledby="tpShareH">
                    <h2 id="tpShareH">Chia sẻ</h2>
                    <p class="tp-muted tp-small">Ai có liên kết đều xem được bản chỉ đọc. Tắt chia sẻ thì liên kết cũ hết hiệu lực.</p>
                    <div class="tp-share">
                        <input type="text" id="tpShareUrl" readonly aria-label="Liên kết chia sẻ" value="{{ $t['shared'] ? route('planner.shared', ['token' => $t['shareToken']]) : '' }}" @if (!$t['shared']) hidden @endif>
                        <button type="button" id="tpShareBtn" class="tp-btn tp-btn--ghost">{{ $t['shared'] ? 'Tắt chia sẻ' : 'Bật liên kết chia sẻ' }}</button>
                    </div>
                </section>
            </aside>
        </div>
    </div>
</main>

<script type="application/json" id="tpData">{!! json_encode($client, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="{{ asset('clients/assets/js/planner.js') }}?v=1" defer></script>

@include('clients.blocks.footer')
