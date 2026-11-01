@include('clients.blocks.header')
<link rel="stylesheet" href="{{ asset('clients/assets/css/planner.css') }}?v=21">
<link rel="stylesheet" href="{{ asset('clients/assets/css/planner-flow.css') }}?v=3">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">

{{-- /len-ke-hoach/de-xuat/xem-truoc. Biến: $r (PlannerService::generate), $query (yêu cầu đã kiểm tra), $empty.
     Chưa lưu gì: "Lưu thành chuyến" gửi lại đúng yêu cầu này (engine là hàm thuần nên lịch lưu ra trùng lịch đang xem).
     $r['mode'] = open: đề xuất mở rộng, mục có osm = true lấy từ OpenStreetMap và được gắn nhãn. --}}
@php
    $req = $r['request'];
    $plan = $r['plan'];
    $open = ($r['mode'] ?? 'curated') === 'open';
    $hm = fn(int $m) => sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    $dur = fn(?int $m) => !$m ? '—' : ($m >= 60 ? (intdiv($m, 60) . ' giờ' . ($m % 60 ? ' ' . ($m % 60) . ' phút' : '')) : $m . ' phút');
    $money = fn($v) => number_format((float) $v, 0, ',', '.') . 'đ';
    $TYPE = ['place' => 'Địa điểm', 'experience' => 'Trải nghiệm', 'food' => 'Món ăn', 'event' => 'Lễ hội', 'tour' => 'Tour', 'destination' => 'Điểm đến'];
    $ICON = ['place' => 'fa-map-marker-alt', 'experience' => 'fa-hiking', 'food' => 'fa-utensils', 'event' => 'fa-star', 'tour' => 'fa-ticket-alt'];
    $SLOT = ['morning' => 'Sáng', 'noon' => 'Trưa', 'afternoon' => 'Chiều', 'evening' => 'Tối'];
    $DOW = [1 => 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy', 'Chủ nhật'];
    $PACE = ['relaxed' => 'Thong thả', 'normal' => 'Vừa phải', 'packed' => 'Dày'];
    $DIFF = ['easy' => 'Dễ', 'medium' => 'Vừa', 'hard' => 'Khó'];
    $CODE = ['dislike' => 'Bạn không thích', 'difficulty' => 'Vượt độ khó bạn chọn', 'season' => 'Không đúng mùa', 'event_outside_trip' => 'Lễ hội ngoài thời gian đi',
        'no_coords' => 'Chưa có vị trí', 'duplicate' => 'Trùng', 'capacity' => 'Hết chỗ trong ngày', 'budget' => 'Vượt ngân sách', 'hours' => 'Giờ mở cửa',
        'far' => 'Quá xa', 'planner_incomplete' => 'Dữ liệu chưa đủ'];
    $COLORS = ['#0d6f6a', '#c2410c', '#1d4ed8', '#a21caf', '#4d7c0f', '#be123c', '#0e7490'];
    $logByCode = collect($plan['filterLog'])->groupBy('code');
    $start = $req['startDate'] ? \Carbon\Carbon::parse($req['startDate']) : null;
    $b = $plan['budget'];
    $paceUrl = fn($p) => route('planner.preview', array_merge($query, ['pace' => $p]));
    $rules = \App\Services\Planner\PlannerRules::fromConfig();
    $loadOf = function (array $day) use ($rules) {
        $all = array_sum(array_map(fn($i) => (int) $i['durationMin'], $day['items']));
        $meal = (bool) array_filter($day['items'], fn($i) => !empty($i['isMeal']));
        return $rules->occupied($all, (int) $day['travelMin'], $meal, (bool) $day['items'])['occupied'] / max(1, $rules->availableMin());
    };
    $mapData = ['center' => $r['center'] ?? null, 'colors' => $COLORS, 'days' => array_map(fn($d) => ['day' => $d['day'], 'items' => array_values(array_map(fn($i) => ['name' => $i['name'], 'lat' => $i['lat'] ?? null, 'lng' => $i['lng'] ?? null, 'key' => $i['key']], array_filter($d['items'], fn($i) => ($i['lat'] ?? null) !== null && !($i['coordsApprox'] ?? false))))], $plan['days'])];
@endphp
<main class="tp tpf tp-pv">
    <div class="container">
        <header class="tpf-hero">
            <p class="tpf-back"><a href="{{ route('planner.suggest', $query) }}"><i class="fas fa-arrow-left" aria-hidden="true"></i> Sửa yêu cầu</a></p>
            <p class="tpf-eyebrow">Xem trước · chưa lưu</p>
            <h1>{{ $r['destination']['name'] }} {{ $req['days'] }} ngày</h1>
            <p class="tp-pv__meta">
                <span><i class="fas fa-calendar-alt" aria-hidden="true"></i> {{ $start ? $start->format('d/m/Y') . ' – ' . $start->copy()->addDays($req['days'] - 1)->format('d/m/Y') : 'Tháng ' . $req['month'] . ' (chưa chọn ngày)' }}</span>
                <span><i class="fas fa-user-friends" aria-hidden="true"></i> {{ $req['adults'] }} người lớn{{ $req['children'] ? ', ' . $req['children'] . ' trẻ em' : '' }}</span>
                <span><i class="fas fa-walking" aria-hidden="true"></i> Nhịp {{ mb_strtolower($PACE[$req['pace']] ?? $req['pace']) }} · độ khó tối đa {{ mb_strtolower($DIFF[$req['maxDifficulty']] ?? $req['maxDifficulty']) }}</span>
                @if ($req['budget'])<span><i class="fas fa-wallet" aria-hidden="true"></i> Ngân sách {{ $money($req['budget']) }}</span>@endif
            </p>
        </header>

        @if ($open)
            <div class="tpf-banner" role="note">
                <i class="fas fa-map-marked-alt" aria-hidden="true"></i>
                <div>
                    <b>Đề xuất mở rộng</b>
                    <p>{{ $r['destination']['name'] }} chưa đủ nội dung Travela đã biên tập, nên lịch dùng thêm điểm tham quan và quán ăn từ bản đồ OpenStreetMap (gắn nhãn <span class="tpf-src"><i class="fas fa-map" aria-hidden="true"></i> Bản đồ</span>). Các mục này chưa có giá, mô tả và giờ mở cửa thường thiếu: nên kiểm tra trước khi đi.
                        @if (($r['openSources']['ok'] ?? true) === false) Máy chủ bản đồ đang bận nên số lựa chọn còn ít; thử lại sau ít phút sẽ phong phú hơn.@endif</p>
                </div>
            </div>
        @endif

        <div class="tp-pv__bar" role="region" aria-label="Lưu hoặc đổi lịch">
            @if (!$empty)
                <form method="POST" action="{{ route('planner.generate') }}" id="pvSave">
                    @csrf
                    @foreach ($query as $k => $val)
                        @if (is_array($val))
                            @foreach ($val as $x)<input type="hidden" name="{{ $k }}[]" value="{{ $x }}">@endforeach
                        @else
                            <input type="hidden" name="{{ $k }}" value="{{ $val }}">
                        @endif
                    @endforeach
                    <button type="submit" class="tp-btn"><i class="fas fa-save" aria-hidden="true"></i> Lưu thành chuyến</button>
                </form>
            @endif
            <div class="tp-pv__pace" role="group" aria-label="Đổi nhịp đi">
                <span class="tp-muted tp-small">Đổi nhịp:</span>
                @foreach ($PACE as $k => $label)
                    <a href="{{ $paceUrl($k) }}" class="tp-chip{{ $req['pace'] === $k ? ' is-on' : '' }}" @if ($req['pace'] === $k) aria-current="true" @endif>{{ $label }}</a>
                @endforeach
            </div>
            <a class="tp-btn tp-btn--ghost" href="{{ route('planner.suggest', $query) }}"><i class="fas fa-redo" aria-hidden="true"></i> Tạo lại với lựa chọn khác</a>
        </div>

        @if ($empty)
            <section class="tp-card tp-alert" role="alert">
                <h2>Chưa đủ nội dung phù hợp để xếp lịch</h2>
                <p>{{ $open ? 'Chưa lấy được điểm tham quan, quán ăn nào quanh nơi này (máy chủ bản đồ bận hoặc khu vực ít dữ liệu).' : 'Với điều kiện này, Travela không còn mục nào đã duyệt để xếp.' }} {{ implode(' ', $plan['warnings']) }}</p>
                <p>Thử nới ngân sách, bỏ bớt điều không thích, tăng độ khó tối đa, đổi tháng hoặc thử lại sau ít phút. Danh sách bên dưới cho biết mục nào bị loại vì sao.</p>
            </section>
        @endif

        @if ($plan['warnings'] && !$empty)
            <section class="tp-card tp-pv__warn" aria-label="Lưu ý">
                <ul>@foreach ($plan['warnings'] as $w)<li>{{ $w }}</li>@endforeach</ul>
            </section>
        @endif

        <div class="tp-pv__grid">
            <div class="tp-pv__days">
                @foreach ($plan['days'] as $day)
                    @php
                        $date = $start ? $start->copy()->addDays($day['day'] - 1) : null;
                        $load = $loadOf($day);
                        $color = $COLORS[($day['day'] - 1) % count($COLORS)];
                    @endphp
                    <section class="tp-card tp-pv__day" aria-labelledby="pvd{{ $day['day'] }}" style="border-top: 4px solid {{ $color }}">
                        <header class="tp-pv__dh">
                            <h2 id="pvd{{ $day['day'] }}">Ngày {{ $day['day'] }}@if ($date) <span class="tp-muted">· {{ $DOW[(int) $date->format('N')] }} {{ $date->format('d/m') }}</span>@endif</h2>
                            <span class="tp-pv__chips">
                                <span class="tp-chip tp-chip--static">{{ count($day['items']) }} mục</span>
                                <span class="tp-chip tp-chip--static">{{ $dur($day['activityMin']) }} hoạt động</span>
                                <span class="tp-chip tp-chip--static">{{ $day['travelMin'] ? '≈ ' . $dur($day['travelMin']) . ' đi lại' : 'gần nhau' }}</span>
                                @if ($day['items'])<span class="tp-chip tp-chip--static" title="Độ dày theo cùng công thức với Sức khỏe chuyến đi">{{ $rules->loadLabel($load) }} · {{ (int) round($load * 100) }}%</span>@endif
                            </span>
                        </header>
                        @if ($day['items'])
                            <div class="tpf-daybar" aria-hidden="true"><span style="width: {{ min(100, (int) round($load * 100)) }}%; background: {{ $color }}"></span></div>
                        @endif
                        @if (!$day['items'])
                            <p class="tp-muted">Ngày trống: dữ liệu chưa đủ. Sau khi lưu bạn có thể tự thêm, hoặc kéo mục từ khu "Đã lưu".</p>
                        @else
                            <ol class="tp-pv__list">
                                @foreach ($day['items'] as $n => $it)
                                    <li class="tp-pv__it" data-key="{{ $it['key'] }}">
                                        <span class="tp-pv__time">{{ $hm($it['startMin']) }}<small>{{ $SLOT[$it['slot']] ?? '' }}</small></span>
                                        <div class="tp-pv__body">
                                            @if ($it['travelMin'] > 0)<span class="tp-pv__leg"><i class="fas fa-car" aria-hidden="true"></i> ≈ {{ $it['travelMin'] }} phút · {{ str_replace('.', ',', (string) $it['travelKm']) }} km</span>@endif
                                            <b>{{ $it['name'] }}@if (!empty($it['osm']))<span class="tpf-src" title="Lấy từ bản đồ OpenStreetMap, chưa được Travela biên tập"><i class="fas fa-map" aria-hidden="true"></i> Bản đồ</span>@elseif ($open)<span class="tpf-src tpf-src--ok" title="Nội dung Travela đã duyệt"><i class="fas fa-check" aria-hidden="true"></i> Travela</span>@endif</b>
                                            <span class="tp-muted tp-small"><i class="fas {{ $ICON[$it['type']] ?? 'fa-map-marker-alt' }}" aria-hidden="true"></i> {{ !empty($it['osm']) ? ($it['kindLabel'] ?? 'Địa điểm') : ($TYPE[$it['type']] ?? $it['type']) }} · {{ $dur($it['durationMin']) }}@if (($it['priceMin'] ?? null) !== null) · {{ (float) $it['priceMin'] <= 0 ? 'Miễn phí' : 'từ ' . $money($it['priceMin']) . '/người' }}@endif @if ($it['coordsApprox']) · <span title="Món ăn chưa gắn quán cụ thể nên không tính đường đi">chưa có vị trí</span>@endif @if (!empty($it['address'])) · {{ $it['address'] }}@endif</span>
                                            @if (!empty($it['reasons']))
                                                <details class="tp-pv__why">
                                                    <summary>Vì sao chọn mục này?</summary>
                                                    <ul>@foreach ($it['reasons'] as $why)<li>{{ $why }}</li>@endforeach</ul>
                                                </details>
                                            @elseif (!empty($it['osm']))
                                                <span class="tp-small tp-muted">Gần trung tâm, đúng loại bạn cần; chưa có đánh giá biên tập.</span>
                                            @endif
                                        </div>
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                    </section>
                @endforeach
            </div>

            <aside class="tp-pv__side">
                @if (array_filter($mapData['days'], fn($d) => $d['items']))
                    <section class="tp-card" aria-labelledby="pvMapH">
                        <h2 id="pvMapH">Bản đồ</h2>
                        <div class="tpf-map" id="pvMap" role="img" aria-label="Bản đồ các điểm trong lịch, đánh số theo thứ tự từng ngày"></div>
                        <div class="tpf-mapdays" id="pvMapDays"></div>
                        <noscript><p class="tp-small tp-muted">Bật JavaScript để xem bản đồ.</p></noscript>
                    </section>
                @endif

                <section class="tp-card" aria-labelledby="pvBud">
                    <h2 id="pvBud">Chi phí đã biết</h2>
                    @if ($b['pricedCount'])
                        <p class="tp-pv__big">{{ $money($b['knownMin']) }}@if ($b['knownMax'] > $b['knownMin']) – {{ $money($b['knownMax']) }}@endif</p>
                        @if ($req['budget'])
                            <div class="tp-meter" role="img" aria-label="Đã dùng {{ min(100, (int) round($b['knownMin'] / $req['budget'] * 100)) }}% ngân sách"><span style="width: {{ min(100, (int) round($b['knownMin'] / $req['budget'] * 100)) }}%"></span></div>
                        @endif
                    @endif
                    <p class="tp-muted tp-small">{{ $b['message'] }}</p>
                    @if ($logByCode->has('budget'))
                        <p class="tp-small">Ngân sách là giới hạn cứng: {{ $logByCode['budget']->count() }} mục bị bỏ vì làm tổng giá vượt ngân sách.</p>
                    @endif
                </section>

                @if (!empty($plan['unplaced']))
                    <section class="tp-card" aria-labelledby="pvSpare">
                        <h2 id="pvSpare">Sẽ vào khu “Đã lưu”</h2>
                        <p class="tp-muted tp-small">Điểm cao nhưng chưa xếp được. Sau khi lưu, bạn kéo vào ngày nào cũng được.</p>
                        <ul class="tp-pv__spare">
                            @foreach ($plan['unplaced'] as $u)
                                <li><b>{{ $u['name'] }}</b>@if (!empty($u['osm']))<span class="tpf-src"><i class="fas fa-map" aria-hidden="true"></i> Bản đồ</span>@endif <span class="tp-muted tp-small">{{ $TYPE[$u['type']] ?? '' }} · {{ $u['why'] ?? '' }}</span></li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                @if ($plan['filterLog'])
                    <section class="tp-card" aria-labelledby="pvLog">
                        <h2 id="pvLog">Vì sao không có…?</h2>
                        <p class="tp-muted tp-small">Travela đã xét {{ $plan['stats']['candidates'] }} mục, chọn {{ $plan['stats']['selected'] }}@if (!empty($plan['stats']['fromMap'])) ({{ $plan['stats']['fromMap'] }} từ bản đồ)@endif. Mỗi mục bị loại đều có lý do:</p>
                        @foreach ($logByCode as $code => $rows)
                            <details class="tp-pv__log">
                                <summary>{{ $CODE[$code] ?? $code }} <span class="tp-muted">({{ $rows->count() }})</span></summary>
                                <ul>@foreach ($rows->take(20) as $x)<li><b>{{ $x['name'] }}</b>: {{ $x['reason'] }}</li>@endforeach</ul>
                            </details>
                        @endforeach
                    </section>
                @endif

                @if ($r['tours'])
                    <section class="tp-card" aria-labelledby="pvTour">
                        <h2 id="pvTour">Tour khởi hành gần ngày đi</h2>
                        <ul class="tp-pv__spare">
                            @foreach ($r['tours'] as $t)
                                <li><a href="{{ $t['url'] }}">{{ $t['title'] }}</a> <span class="tp-muted tp-small">{{ $t['start'] }} · {{ $money($t['forGroup']) }} cả nhóm</span></li>
                            @endforeach
                        </ul>
                        <p class="tp-muted tp-small">Không cộng vào chi phí chuyến.</p>
                    </section>
                @endif

                @if ($r['verdict'])
                    <p class="tp-muted tp-small">{{ $r['verdict'] }}</p>
                @endif
                <p class="tp-muted tp-small">{{ $r['disclaimer'] }}@if ($open) Dữ liệu bản đồ © OpenStreetMap contributors.@endif</p>
            </aside>
        </div>
    </div>
</main>
<script type="application/json" id="pvData">@json($mapData)</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" defer></script>
<script src="{{ asset('clients/assets/js/planner-preview.js') }}?v=1" defer></script>

@include('clients.blocks.footer')
