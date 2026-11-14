@include('clients.blocks.header')
<link rel="stylesheet" href="{{ asset('clients/assets/css/planner.css') }}?v=21">
<link rel="stylesheet" href="{{ asset('clients/assets/css/planner-flow.css') }}?v=4">
<link rel="stylesheet" href="{{ asset('clients/assets/css/planner-ai.css') }}?v=1">
<link rel="stylesheet" href="{{ asset('clients/assets/css/nature-luxury-planner.css') }}?v=bl20261104">
<link rel="stylesheet" href="{{ asset('clients/assets/css/travela-nature-all-pages.css') }}?v=bl20261104">

{{-- /len-ke-hoach/de-xuat. Biến: $dests (điểm đến lõi + mức sẵn sàng), $allDests (mọi điểm đến cho ô "Đi đâu?"), $pre (điền sẵn),
     $notReady (điểm đến đang chọn chưa đủ dữ liệu), $unknown (nơi người dùng gõ mà Travela chưa có), $openMode (bật đề xuất mở rộng).
     Gửi form (GET) sang màn xem trước; chưa lưu gì. planner-suggest.js: ô chọn nơi, nút +/−, tóm tắt trực tiếp. --}}
@php
    $v = fn($k, $d = null) => old($k, $pre[$k] ?? null) ?? $d;
    $preDest = collect($allDests)->firstWhere('slug', $pre['destination']);
    $paces = ['relaxed' => ['Thong thả', 'Dùng khoảng 2/3 ngày, nghỉ nhiều', 'fa-mug-hot'], 'normal' => ['Vừa phải', 'Dùng khoảng 4/5 ngày, còn thời gian trống', 'fa-walking'], 'packed' => ['Dày', 'Gần kín ngày, đi được nhiều nơi nhất', 'fa-running']];
    $diffs = ['easy' => ['Dễ', 'Đi bộ nhẹ, hợp người lớn tuổi, trẻ nhỏ'], 'medium' => ['Vừa', 'Có leo dốc, đi bộ vài km'], 'hard' => ['Khó', 'Không giới hạn độ khó']];
    $icons = ['nature' => 'fa-leaf', 'culture' => 'fa-landmark', 'food' => 'fa-utensils', 'adventure' => 'fa-hiking', 'relax' => 'fa-spa', 'family' => 'fa-child'];
    $dislikes = ['trekking' => ['Trekking, leo núi', 'fa-mountain'], 'crowds' => ['Nơi đông đúc', 'fa-users'], 'outdoor' => ['Hoạt động ngoài trời', 'fa-sun'], 'long_travel' => ['Di chuyển xa', 'fa-route']];
    $months = range(1, 12);
    $core = collect($allDests)->where('core', true)->values();
    $clientData = ['dests' => $allDests, 'searchUrl' => route('planner.placeSearch'), 'openMode' => $openMode,
        'pre' => ['dest' => $preDest && ($preDest['ready'] || $openMode) ? $preDest['id'] : null, 'place' => $pre['place'] ?? null]];
    $aiData = ['briefUrl' => route('planner.ai.brief'), 'csrf' => csrf_token(), 'on' => $aiOn,
        'labels' => ['interests' => collect(config('interests'))->map(fn($d) => $d['label'])->all(),
            'dislikes' => array_map(fn($x) => $x[0], $dislikes), 'pace' => array_map(fn($x) => $x[0], $paces), 'maxDifficulty' => array_map(fn($x) => $x[0], $diffs)]];
@endphp
<main class="tp tpf" id="sgPage">
    <div class="container">
        <header class="tpf-hero">
            <p class="tpf-back"><a href="{{ route('planner') }}"><i class="fas fa-arrow-left" aria-hidden="true"></i> Chuyến đi của tôi</a></p>
            <p class="tpf-eyebrow">Lập kế hoạch tự động</p>
            <h1>Để Travela đề xuất lịch trình</h1>
            <p class="tpf-lead">Chọn nơi muốn đến, ngày đi và phong cách. Travela xếp sẵn từng ngày; bạn xem trước, rồi mới lưu và chỉnh tiếp.</p>
            {{-- La bàn F1 "Vé hành trình": kể chuyến đi bằng một câu -> AI (hoặc luật khi AI tắt) điền form bên dưới, người dùng xem lại rồi mới xếp lịch.
                 Không có JS thì form vẫn gửi ?q= như cũ (SearchIntent điền sẵn). --}}
            <form method="GET" action="{{ route('planner.suggest') }}" class="ai-say" id="aiSay" aria-labelledby="aiSayH">
                <div class="ai-say__head">
                    <span class="ai-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9.2" stroke="currentColor" stroke-width="1.6"/><path d="M12 4.5 14.2 12 12 19.5 9.8 12Z" fill="#e9a23b"/><path d="M12 12 14.2 12 12 19.5Z" fill="#167A85"/></svg></span>
                    <label id="aiSayH" for="aiText">Kể chuyến đi bằng một câu</label>
                    <span class="ai-say__mode" id="aiMode">{{ $aiOn ? 'La bàn đọc giúp bạn' : 'Chế độ cơ bản' }}</span>
                </div>
                <textarea id="aiText" name="q" rows="2" maxlength="500" placeholder="Ví dụ: Tôi đi Hà Nội 3 ngày cuối tháng 11, 2 người, khoảng 5 triệu, thích văn hóa và ăn uống, không thích đi quá nhiều">{{ is_string(request('q')) ? request('q') : '' }}</textarea>
                <div class="ai-say__row">
                    <p class="ai-say__priv">{{ $aiOn ? 'Câu bạn nhập được gửi tới nhà cung cấp AI để đọc hiểu. Đừng ghi email hay số điện thoại.' : 'Travela đọc câu bằng luật có sẵn, không gửi đi đâu.' }}</p>
                    <button type="submit" class="tp-btn ai-btn" id="aiGo">Hiểu giúp mình</button>
                </div>
            </form>
            <div class="ai-result" id="aiResult" hidden aria-live="polite"></div>
        </header>

        @if ($errors->any())
            <div class="tp-alert tpf-alert" role="alert">
                <ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        @if (!empty($notReady))
            {{-- Điểm đến chưa Planner-ready: nói rõ còn thiếu gì; vẫn tự xếp được bằng đề xuất mở rộng --}}
            <section class="tp-card tp-notready tpf-note" aria-labelledby="tpNrH" role="status">
                <h2 id="tpNrH">{{ $notReady['name'] }} chưa đủ dữ liệu để Travela tự xếp lịch</h2>
                <p>Nội dung đã biên tập đang đạt khoảng {{ $notReady['percent'] }}%.
                    @if ($notReady['open']) Bạn vẫn có thể dùng <b>đề xuất mở rộng</b>: Travela lấy thêm điểm tham quan, quán ăn từ bản đồ OpenStreetMap và ghi rõ mục nào chưa được biên tập.@endif
                </p>
                <details class="tpf-miss"><summary>Còn thiếu ({{ count($notReady['missing']) }})</summary>
                    <ul class="tp-nr__miss">@foreach ($notReady['missing'] as $m)<li>{{ $m }}</li>@endforeach</ul>
                </details>
                @if ($notReady['alternatives'])
                    <p class="tpf-small">Điểm đến đã đủ dữ liệu
                    @if ($pre['month']) và hợp tháng {{ $pre['month'] }}@endif:</p>
                    <p class="tp-nr__alts">
                        @foreach ($notReady['alternatives'] as $a)
                            <a class="tp-chip" href="{{ route('planner.suggest', ['diem-den' => $a['slug']] + ($pre['month'] ? ['thang' => $pre['month']] : [])) }}">{{ $a['name'] }}</a>@if ($a['why'])<span class="tp-muted tp-small"> {{ $a['why'] }}</span>@endif
                        @endforeach
                    </p>
                @endif
            </section>
        @endif

        @if (!empty($unknown))
            <section class="tp-card tp-unknown tpf-note" aria-labelledby="tpUnkH" role="status">
                <h2 id="tpUnkH">Travela chưa có dữ liệu về “{{ $unknown['term'] }}”</h2>
                @if ($unknown['fuzzy'])
                    <p>Có phải bạn muốn nói:
                        @foreach ($unknown['fuzzy'] as $f)
                            <a class="tp-chip" href="{{ route('planner.suggest', ['diem-den' => $f['slug']]) }}">{{ $f['name'] }}</a>
                        @endforeach
                    </p>
                @endif
                @if ($unknown['place'])
                    <p>Tìm thấy trên bản đồ: <strong>{{ $unknown['place']['name'] }}</strong>@if ($unknown['place']['address']), {{ $unknown['place']['address'] }}@endif.</p>
                    @if ($openMode)
                        <p><a class="tp-btn" href="{{ route('planner.suggest', ['placeName' => $unknown['place']['name'], 'placeLat' => $unknown['place']['lat'], 'placeLng' => $unknown['place']['lng']] + ($pre['month'] ? ['month' => $pre['month']] : []) + ($pre['days'] ? ['days' => $pre['days']] : [])) }}"><i class="fas fa-map-marked-alt" aria-hidden="true"></i> Đề xuất mở rộng quanh {{ $unknown['place']['name'] }}</a></p>
                    @endif
                    @if ($unknown['nearest'])
                        <p class="tpf-small">Hoặc chọn điểm đến Travela gần nhất:
                            @foreach ($unknown['nearest'] as $n)
                                <a class="tp-chip" href="{{ route('planner.suggest', ['diem-den' => $n['slug']]) }}">{{ $n['name'] }} · {{ (int) round($n['km']) }} km</a>
                            @endforeach
                        </p>
                    @endif
                @elseif ($unknown['external'] === 'error')
                    <p class="tp-muted">Dịch vụ bản đồ đang bận nên chưa tra được vị trí. Hãy gõ tên nơi đó vào ô “Bạn muốn đi đâu?” bên dưới.</p>
                @endif
            </section>
        @endif

        <form method="GET" action="{{ route('planner.preview') }}" class="tpf-grid" id="sgForm" novalidate>
            <div class="tpf-main">
                {{-- 1. Đi đâu --}}
                <section class="tpf-step" aria-labelledby="sgS1">
                    <h2 id="sgS1"><span class="tpf-num" aria-hidden="true">1</span> Bạn muốn đi đâu?</h2>
                    <div class="tpf-where" id="sgWhereBox">
                        <div class="tpf-combo">
                            <i class="fas fa-search" aria-hidden="true"></i>
                            <label class="sr-only" for="sgWhere">Điểm đến hoặc nơi bất kỳ</label>
                            <input id="sgWhere" type="text" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="sgWhereList"
                                placeholder="Gõ tên tỉnh, thành phố, điểm đến… (vd: Đà Lạt, Hà Giang, Côn Đảo)" value="">
                            <ul id="sgWhereList" class="tpf-combo__list" role="listbox" hidden></ul>
                        </div>
                        <div class="tpf-picked" id="sgPicked" hidden></div>
                        <input type="hidden" name="destinationId" id="sgDestId" value="{{ $clientData['pre']['dest'] }}">
                        <input type="hidden" name="placeName" id="sgPlaceName" value="{{ $pre['place']['name'] ?? '' }}">
                        <input type="hidden" name="placeLat" id="sgPlaceLat" value="{{ $pre['place']['lat'] ?? '' }}">
                        <input type="hidden" name="placeLng" id="sgPlaceLng" value="{{ $pre['place']['lng'] ?? '' }}">
                        <input type="hidden" name="notes" id="sgNotes" value="{{ $pre['notes'] ?? '' }}">
                        <p class="tpf-err" id="sgWhereErr" hidden>Hãy chọn một điểm đến hoặc một nơi trên bản đồ.</p>
                    </div>
                    <div class="tpf-popular" aria-label="Điểm đến nổi bật">
                        @foreach ($core as $d)
                            <button type="button" class="tpf-dest" data-dest="{{ $d['id'] }}">
                                <span class="tpf-dest__ph" aria-hidden="true"><i class="fas fa-map-marker-alt"></i>@if ($d['img'])<img src="{{ $d['img'] }}" alt="" loading="lazy" onerror="this.remove()">@endif</span>
                                <span class="tpf-dest__t"><b>{{ $d['name'] }}</b>
                                    <small class="{{ $d['ready'] ? 'is-ready' : 'is-open' }}">{{ $d['ready'] ? 'Đủ dữ liệu biên tập' : 'Đề xuất mở rộng' }}</small></span>
                            </button>
                        @endforeach
                    </div>
                    <p class="tpf-hint"><span><span class="tpf-dot is-ready"></span> <b>Đủ dữ liệu biên tập</b>: chỉ dùng nội dung Travela đã duyệt.</span>
                        @if ($openMode)<span><span class="tpf-dot is-open"></span> <b>Đề xuất mở rộng</b>: bổ sung từ bản đồ OpenStreetMap, có ghi rõ.</span>@endif</p>
                </section>

                {{-- 2. Khi nào --}}
                <section class="tpf-step" aria-labelledby="sgS2">
                    <h2 id="sgS2"><span class="tpf-num" aria-hidden="true">2</span> Khi nào và bao lâu?</h2>
                    <div class="tpf-row">
                        <label class="tpf-field"><span>Ngày đi</span>
                            <input type="date" name="startDate" min="{{ now()->toDateString() }}" value="{{ $v('startDate') }}" id="sgStart">
                            <small>Nên chọn: xếp đúng ngày lễ hội, giờ mở cửa, tour gần ngày đi.</small>
                        </label>
                        <div class="tpf-field">
                            <span id="sgDaysL">Số ngày</span>
                            <div class="tpf-stepper" data-min="1" data-max="{{ (int) config('planner.max_days', 7) }}">
                                <button type="button" class="tpf-stepper__b" data-d="-1" aria-label="Bớt một ngày">−</button>
                                <input type="number" name="days" id="sgDays" min="1" max="{{ (int) config('planner.max_days', 7) }}" value="{{ $v('days', 3) }}" aria-labelledby="sgDaysL" required>
                                <button type="button" class="tpf-stepper__b" data-d="1" aria-label="Thêm một ngày">+</button>
                            </div>
                        </div>
                    </div>
                    <fieldset class="tpf-months" id="sgMonths">
                        <legend>Chưa chốt ngày? Chọn tháng đi</legend>
                        <input type="hidden" name="month" id="sgMonth" value="{{ $v('month') }}">
                        @foreach ($months as $m)
                            <button type="button" class="tp-chip tpf-month" data-m="{{ $m }}" aria-pressed="false">Th {{ $m }}</button>
                        @endforeach
                        <p class="tpf-err" id="sgWhenErr" hidden>Hãy chọn ngày đi hoặc tháng đi.</p>
                    </fieldset>
                    {{-- Giờ tới nơi / giờ về: ngày đầu không xếp gì trước lúc tới, ngày cuối xong trước lúc phải ra ga / sân bay --}}
                    <details class="tpf-more" @if ($v('arriveAt') || $v('leaveAt')) open @endif>
                        <summary>Đã biết giờ tới nơi và giờ về? <small>(không bắt buộc)</small></summary>
                        <div class="tpf-row">
                            <label class="tpf-field"><span>Đi bằng</span>
                                <select name="moveKind" id="sgMoveKind" class="no-nice">
                                    @foreach (['flight' => 'Máy bay', 'train' => 'Tàu hỏa', 'bus' => 'Xe khách', 'self' => 'Tự lái xe / xe máy'] as $k => $l)
                                        <option value="{{ $k }}" @selected(($v('moveKind') ?? 'flight') === $k)>{{ $l }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="tpf-field"><span>Tới nơi ngày đầu lúc</span>
                                <input type="time" name="arriveAt" value="{{ $v('arriveAt') }}" step="300">
                            </label>
                            <label class="tpf-field"><span>Khởi hành về ngày cuối lúc</span>
                                <input type="time" name="leaveAt" value="{{ $v('leaveAt') }}" step="300">
                            </label>
                        </div>
                        <small class="tpf-note">Travela tự cộng thời gian ra khỏi sân bay / ga và trừ thời gian có mặt sớm (máy bay 2 giờ, tàu 40 phút, xe khách 30 phút). Có ngày đi thì chặng đi, về được ghi vào mục "Cần đặt" để nhắc bạn đặt vé.</small>
                    </details>
                </section>

                {{-- 3. Ai đi --}}
                <section class="tpf-step" aria-labelledby="sgS3">
                    <h2 id="sgS3"><span class="tpf-num" aria-hidden="true">3</span> Ai đi cùng, ngân sách bao nhiêu?</h2>
                    <div class="tpf-row">
                        <div class="tpf-field">
                            <span id="sgAdL">Người lớn</span>
                            <div class="tpf-stepper" data-min="1" data-max="50">
                                <button type="button" class="tpf-stepper__b" data-d="-1" aria-label="Bớt một người lớn">−</button>
                                <input type="number" name="adults" id="sgAdults" min="1" max="50" value="{{ $v('adults') ?? ($pre['groupSize'] ?? 2) }}" aria-labelledby="sgAdL" required>
                                <button type="button" class="tpf-stepper__b" data-d="1" aria-label="Thêm một người lớn">+</button>
                            </div>
                        </div>
                        <div class="tpf-field">
                            <span id="sgChL">Trẻ em <small>(dưới 12 tuổi)</small></span>
                            <div class="tpf-stepper" data-min="0" data-max="30">
                                <button type="button" class="tpf-stepper__b" data-d="-1" aria-label="Bớt một trẻ em">−</button>
                                <input type="number" name="children" id="sgChildren" min="0" max="30" value="{{ $v('children', 0) }}" aria-labelledby="sgChL">
                                <button type="button" class="tpf-stepper__b" data-d="1" aria-label="Thêm một trẻ em">+</button>
                            </div>
                        </div>
                    </div>
                    <label class="tpf-field"><span>Ngân sách cả nhóm <small>(không bắt buộc)</small></span>
                        <span class="tpf-money"><input type="number" name="budget" id="sgBudget" min="0" step="100000" value="{{ $v('budget') }}" placeholder="vd 5000000"><span aria-hidden="true">đ</span></span>
                        <small id="sgPer" aria-live="polite"></small>
                    </label>
                    <div class="tpf-chips" aria-label="Chọn nhanh ngân sách">
                        @foreach ([3000000, 5000000, 10000000, 20000000] as $b)
                            <button type="button" class="tp-chip tpf-budget" data-b="{{ $b }}">{{ $b / 1000000 }} triệu</button>
                        @endforeach
                    </div>
                </section>

                {{-- 4. Phong cách --}}
                <section class="tpf-step" aria-labelledby="sgS4">
                    <h2 id="sgS4"><span class="tpf-num" aria-hidden="true">4</span> Phong cách chuyến đi</h2>
                    <fieldset class="tp-seg">
                        <legend>Nhịp đi</legend>
                        @foreach ($paces as $key => [$label, $hint, $ic])
                            <label class="tp-seg__o"><input type="radio" name="pace" value="{{ $key }}" @checked(($v('pace') ?? 'normal') === $key)><span><b><i class="fas {{ $ic }}" aria-hidden="true"></i> {{ $label }}</b><small>{{ $hint }}</small></span></label>
                        @endforeach
                    </fieldset>
                    <fieldset class="tp-seg">
                        <legend>Độ khó tối đa</legend>
                        @foreach ($diffs as $key => [$label, $hint])
                            <label class="tp-seg__o"><input type="radio" name="maxDifficulty" value="{{ $key }}" @checked(($v('maxDifficulty') ?? 'hard') === $key)><span><b>{{ $label }}</b><small>{{ $hint }}</small></span></label>
                        @endforeach
                    </fieldset>
                    <fieldset class="tpf-toggles">
                        <legend>Sở thích <small>(chọn vài mục)</small></legend>
                        @foreach (config('interests') as $key => $def)
                            <label class="tpf-toggle"><input type="checkbox" name="interests[]" value="{{ $key }}" @checked(in_array($key, (array) old('interests', $pre['interests']), true))><span><i class="fas {{ $icons[$key] ?? 'fa-star' }}" aria-hidden="true"></i> {{ $def['label'] }}</span></label>
                        @endforeach
                    </fieldset>
                    <fieldset class="tpf-toggles tpf-toggles--neg">
                        <legend>Không thích</legend>
                        @foreach ($dislikes as $key => [$label, $ic])
                            @if (array_key_exists($key, (array) config('planner.dislikes')))
                                <label class="tpf-toggle"><input type="checkbox" name="dislikes[]" value="{{ $key }}" @checked(in_array($key, (array) old('dislikes', $pre['dislikes']), true))><span><i class="fas {{ $ic }}" aria-hidden="true"></i> {{ $label }}</span></label>
                            @endif
                        @endforeach
                    </fieldset>
                    <p class="tpf-small tp-muted">“Di chuyển xa”: tối đa khoảng 1,5 giờ đi lại mỗi ngày, không ghé nơi cách trung tâm quá 25 km.</p>
                </section>
            </div>

            <aside class="tpf-side" aria-label="Tóm tắt">
                <div class="tpf-sum" id="sgSum">
                    <p class="tpf-sum__eyebrow">Chuyến của bạn</p>
                    <p class="tpf-sum__dest" id="sgSumDest">Chưa chọn nơi đến</p>
                    <p class="tpf-sum__mode" id="sgSumMode" hidden></p>
                    <ul class="tpf-sum__list">
                        <li><i class="fas fa-calendar-alt" aria-hidden="true"></i> <span id="sgSumWhen">Chưa chọn ngày</span></li>
                        <li><i class="fas fa-user-friends" aria-hidden="true"></i> <span id="sgSumWho">2 người lớn</span></li>
                        <li><i class="fas fa-wallet" aria-hidden="true"></i> <span id="sgSumBudget">Không giới hạn ngân sách</span></li>
                        <li><i class="fas fa-walking" aria-hidden="true"></i> <span id="sgSumStyle">Nhịp vừa phải</span></li>
                    </ul>
                    <button type="submit" class="tp-btn tpf-cta">Xem trước lịch trình <i class="fas fa-arrow-right" aria-hidden="true"></i></button>
                    <p class="tpf-small tp-muted">Chưa lưu gì cho tới khi bạn bấm “Lưu thành chuyến” ở bước sau. Bạn sẽ thấy vì sao mỗi mục được chọn và mục nào bị loại.</p>
                </div>
                <p class="tpf-small tpf-alt">Chưa biết đi đâu? <a href="{{ route('planner.discover') }}">Chọn theo tháng và cảm hứng</a><br>Muốn tự chọn từng nơi? <a href="{{ route('planner') }}">Tạo chuyến đi trống</a></p>
            </aside>
            <div class="tpf-mbar" aria-hidden="true">
                <span id="sgMbar">Chưa chọn nơi đến</span>
                <button type="submit" class="tp-btn" tabindex="-1">Xem trước</button>
            </div>
        </form>
    </div>
</main>
<script type="application/json" id="sgData">@json($clientData)</script>
<script type="application/json" id="aiData">{!! json_encode($aiData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
<script src="{{ asset('clients/assets/js/planner-suggest.js') }}?v=2" defer></script>
<script src="{{ asset('clients/assets/js/planner-ai-brief.js') }}?v=1" defer></script>
@if (session('userId'))<script src="{{ asset('clients/assets/js/ai-feedback.js') }}?v=2" data-url="{{ route('planner.ai.rate') }}" data-csrf="{{ csrf_token() }}" defer></script>@endif

@include('clients.blocks.footer')
