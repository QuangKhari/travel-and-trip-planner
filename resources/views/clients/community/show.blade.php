@include('clients.blocks.header')
<link rel="stylesheet" href="{{ asset('clients/assets/css/community.css') }}?v=2">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
@php
    $money = fn($n) => number_format((float) $n, 0, ',', '.') . 'đ';
    $start = $trip->startDate ? \Carbon\Carbon::parse($trip->startDate) : null;
    $cover = $trip->destCover ? \App\Support\DiscoverImage::url($trip->destCover) : null;
    $typeLabel = ['destination' => 'Điểm đến', 'place' => 'Địa điểm', 'food' => 'Món ăn', 'event' => 'Lễ hội', 'experience' => 'Trải nghiệm', 'tour' => 'Tour', 'note' => 'Ghi chú', 'transport' => 'Di chuyển'];
    $slotLabel = ['morning' => 'Buổi sáng', 'noon' => 'Buổi trưa', 'afternoon' => 'Buổi chiều', 'evening' => 'Buổi tối'];
    $loggedIn = (bool) session('userId');
    $allItems = array_merge(...array_map(fn($d) => $d['items'], $payload['days']));
    $gallery = array_values(array_unique(array_filter(array_map(fn($it) => $it['image'] ?? null, $allItems))));
    $count = array_count_values(array_map(fn($it) => $it['type'] === 'note' ? 'place' : $it['type'], $allItems));
    $incl = array_filter([
        ($count['place'] ?? 0) + ($count['destination'] ?? 0) ? (($count['place'] ?? 0) + ($count['destination'] ?? 0)) . ' địa điểm tham quan' : null,
        ($count['food'] ?? 0) ? $count['food'] . ' món nên thử' : null,
        ($count['experience'] ?? 0) ? $count['experience'] . ' trải nghiệm' : null,
        ($count['event'] ?? 0) ? $count['event'] . ' lễ hội' : null,
        ($count['tour'] ?? 0) ? $count['tour'] . ' tour Travela' : null,
        !empty($payload['rain']['plan']) ? 'Phương án khi mưa cho ' . count($payload['rain']['plan']) . ' ngày' : null,
        'La bàn kiểm tra giờ mở cửa, quãng đường khi bạn sửa',
    ]);
    $mapPts = [];
    foreach ($payload['days'] as $d) {
        foreach ($d['items'] as $i => $it) {
            if ($it['lat'] !== null && $it['lng'] !== null) {
                $mapPts[] = ['d' => $d['day'], 'n' => $i + 1, 'name' => $it['name'], 'lat' => (float) $it['lat'], 'lng' => (float) $it['lng']];
            }
        }
    }
@endphp
<main class="cm">
    <div class="cm-wrap">
        <a class="cm-back" href="{{ route('community.index') }}"><i class="fas fa-arrow-left" aria-hidden="true"></i> Cộng đồng hành trình</a>
        <div class="cm-detail">
            <div>
                <header class="cmx-head">
                    <p class="cmx-eyebrow">{{ $trip->destName ?: 'Hành trình' }}@if ($trip->interestList) · {{ implode(' · ', array_map(fn($i) => $interests[$i]['label'] ?? $i, array_slice($trip->interestList, 0, 2))) }}@endif</p>
                    <h1>{{ $trip->title }}</h1>
                    <p class="cmx-by"><img src="{{ \App\Support\Avatar::url($trip->avatar) }}" alt="" width="26" height="26"> Chia sẻ bởi <b>{{ $trip->author }}</b> · {{ $trip->cloneCount }} lượt dùng lại</p>
                    @if ($trip->summary)<p class="cmx-sum">{{ $trip->summary }}</p>@endif
                </header>
                @php $pics = array_slice(array_values(array_filter(array_merge([$cover], $gallery))), 0, 5); @endphp
                @if ($pics)
                    <div class="cmx-gal cmx-gal--{{ min(5, count($pics)) }}">
                        @foreach ($pics as $i => $src)<div class="cmx-gal__i" style="background-image:url('{{ $src }}')" role="img" aria-label="Ảnh {{ $i + 1 }} của lịch trình"></div>@endforeach
                    </div>
                @endif

                <section class="cmx-glance" aria-labelledby="cmxGlanceH">
                    <h2 id="cmxGlanceH">Chuyến đi trong một cái nhìn</h2>
                    <ul class="cmx-chips">
                        <li><i class="far fa-calendar" aria-hidden="true"></i> {{ $trip->days }} ngày</li>
                        <li><i class="fas fa-map-marker-alt" aria-hidden="true"></i> {{ count($allItems) }} điểm dừng</li>
                        <li><i class="fas fa-user-friends" aria-hidden="true"></i> cho {{ $trip->groupSize }} người</li>
                        @if ($trip->perPerson)<li><i class="fas fa-wallet" aria-hidden="true"></i> ≈ {{ $money($trip->perPerson) }}/người</li>@endif
                    </ul>
                    <p class="cmx-note"><i class="fas fa-magic" aria-hidden="true"></i> Mọi thứ ở đây là gợi ý: đổi ngày, số người, ngân sách hoặc sở thích, Travela dựng lại chuyến cho bạn.</p>
                </section>

                <ol class="cmx-tl">
                @foreach ($payload['days'] as $d)
                    @if ($d['items'])
                        <li class="cmx-day">
                            <span class="cmx-day__n" aria-hidden="true">{{ $d['day'] }}</span>
                            <h2>Ngày {{ $d['day'] }}@if ($start)<small>{{ $start->copy()->addDays($d['day'] - 1)->format('d/m') }}</small>@endif
                                @if ($d['legs'])<small>· khoảng {{ $d['km'] }} km di chuyển</small>@endif</h2>
                            <ol class="cmx-items">
                                @foreach ($d['items'] as $it)
                                    @php $tl = trim(implode(' · ', array_filter([$it['type'] === 'note' ? null : ($typeLabel[$it['type']] ?? null), $slotLabel[$it['slot'] ?? ''] ?? null]))); @endphp
                                    <li class="cmx-it">
                                        @if (!empty($it['image']))<span class="cmx-it__img" style="background-image:url('{{ $it['image'] }}')" aria-hidden="true"></span>
                                        @else<span class="cmx-it__img cmx-it__img--ph" aria-hidden="true"><i class="fas {{ ['food' => 'fa-utensils', 'event' => 'fa-calendar-alt', 'experience' => 'fa-hiking', 'tour' => 'fa-flag', 'transport' => 'fa-route'][$it['type']] ?? 'fa-map-marker-alt' }}"></i></span>@endif
                                        <span class="cmx-it__b">
                                            @if ($tl !== '')<small class="cmx-it__t">{{ $tl }}</small>@endif
                                            @if (!empty($it['url']))<a href="{{ $it['url'] }}">{{ $it['name'] }}</a>@else<b>{{ $it['name'] }}</b>@endif
                                            @if ($it['sub'])<small>{{ $it['sub'] }}</small>@endif
                                            @if ($it['note'])<span class="cm-note">{{ $it['note'] }}</span>@endif
                                        </span>
                                    </li>
                                @endforeach
                            </ol>
                        </li>
                    @endif
                @endforeach
                </ol>

                @if (!empty($payload['rain']['plan']))
                    <section class="cm-day">
                        <h2>Nếu trời mưa</h2>
                        @foreach ($payload['rain']['plan'] as $dayNo => $list)
                            <p style="margin:6px 0 2px;font-weight:600">Ngày {{ $dayNo }}</p>
                            <ul style="margin:0;padding-left:18px">@foreach ($list as $r)<li>@if (!empty($r['url']))<a href="{{ $r['url'] }}">{{ $r['name'] }}</a>@else{{ $r['name'] }}@endif</li>@endforeach</ul>
                        @endforeach
                    </section>
                @endif
                <p class="cm-muted" style="font-size:13px">Lịch trình do thành viên chia sẻ. Giờ mở cửa, giá và lịch lễ hội có thể đã thay đổi — hãy kiểm tra lại trước khi đi.</p>
            </div>

            <aside class="cm-side">
                @if ($mapPts)
                    <section class="cm-box cmx-mapbox"><div id="cmxMap" class="cmx-map" role="img" aria-label="Bản đồ các điểm dừng theo ngày"></div></section>
                @endif
                @if (!$isOwner)
                    <section class="cm-box cmx-remix" id="lam-cua-toi">
                        <p class="cmx-remix__k"><i class="fas fa-magic" aria-hidden="true"></i> Dựng lại theo bạn</p>
                        <h2>Ngân sách? Số người? Ngày đi?</h2>
                        @if ($loggedIn)
                            @if ($errors->has('remix'))<div class="cm-err" role="alert">{{ $errors->first('remix') }}</div>@endif
                            <form method="POST" action="{{ route('community.remix', $trip->tripId) }}">
                                @csrf
                                <label for="cmxText" class="sr-only">Kể yêu cầu cho chuyến của bạn</label>
                                <textarea id="cmxText" name="text" rows="3" maxlength="500" required placeholder="Ví dụ: 2 người, đầu tháng 12, 4 ngày, khoảng 6 triệu, thích ăn uống, không leo núi">{{ old('text') }}</textarea>
                                <button type="submit" class="cmx-remix__go">Làm thành chuyến của tôi <i class="fas fa-arrow-right" aria-hidden="true"></i></button>
                            </form>
                            <p class="cmx-remix__hint">Phần không nói thì giữ như bản gốc. Ngày bớt đi thì các điểm thừa chuyển vào “Đã lưu”, không mất.</p>
                        @else
                            <a class="cmx-remix__go" href="{{ route('login') }}">Đăng nhập để làm chuyến của bạn</a>
                        @endif
                    </section>
                @endif
                <section class="cm-box cmx-incl">
                    <h2>Có trong lịch trình</h2>
                    <ul>@foreach ($incl as $x)<li><i class="fas fa-check" aria-hidden="true"></i> {{ $x }}</li>@endforeach</ul>
                </section>
                <section class="cm-box">
                    <div class="cm-stats">
                        <div><b id="cmLikes">{{ $trip->likeCount }}</b><small>lượt thích</small></div>
                        <div><b>{{ $trip->cloneCount }}</b><small>lượt dùng lại</small></div>
                        <div><b>{{ $trip->viewCount }}</b><small>lượt xem</small></div>
                    </div>
                    <p class="cm-muted" style="font-size:13px;margin:0 0 12px;display:flex;gap:8px;align-items:center">
                        <img src="{{ \App\Support\Avatar::url($trip->avatar) }}" alt="" width="28" height="28" style="border-radius:50%;object-fit:cover">
                        <span>Chia sẻ bởi <b style="color:#1f3a44">{{ $trip->author }}</b> · {{ \Carbon\Carbon::parse($trip->publishedAt)->format('d/m/Y') }}</span>
                    </p>
                    @if ($loggedIn)
                        <form method="POST" action="{{ route('community.like', $trip->tripId) }}" id="cmLikeForm">
                            @csrf
                            <button type="submit" class="cm-btn cm-btn--ghost {{ $liked ? 'is-on' : '' }}" id="cmLikeBtn" aria-pressed="{{ $liked ? 'true' : 'false' }}">
                                <i class="{{ $liked ? 'fas' : 'far' }} fa-heart" aria-hidden="true"></i> <span>{{ $liked ? 'Đã thích' : 'Thích' }}</span>
                            </button>
                        </form>
                    @else
                        <a class="cm-btn cm-btn--ghost" href="{{ route('login') }}"><i class="far fa-heart" aria-hidden="true"></i> Đăng nhập để thích</a>
                    @endif
                </section>

                <section class="cm-box" id="dung-lai">
                    <h2>{{ $isOwner ? 'Chuyến của bạn' : 'Hoặc điền từng mục' }}</h2>
                    @if ($isOwner)
                        <p class="cm-muted" style="font-size:13.5px;margin:0 0 10px">Đây là chuyến của bạn.</p>
                        <a class="cm-btn" href="{{ route('planner.edit', $trip->tripId) }}">Mở để chỉnh sửa</a>
                    @elseif ($loggedIn)
                        <p class="cm-muted" style="font-size:13.5px;margin:0 0 10px">Tạo một chuyến đi riêng của bạn với cùng lịch trình. Bạn sửa thoải mái, bản gốc không bị ảnh hưởng.</p>
                        @if ($errors->any() && !$errors->has('remix'))<div class="cm-err" role="alert" style="margin-bottom:10px">{{ $errors->first() }}</div>@endif
                        <form method="POST" action="{{ route('community.use', $trip->tripId) }}" class="cm-form">
                            @csrf
                            <label>Tên chuyến của bạn
                                <input type="text" name="title" maxlength="160" value="{{ old('title', $trip->title) }}">
                            </label>
                            <div class="cm-row">
                                <label>Ngày đi
                                    <input type="date" name="startDate" min="{{ today()->toDateString() }}" value="{{ old('startDate') }}">
                                </label>
                                <label>Số người
                                    <input type="number" name="groupSize" min="1" max="50" required value="{{ old('groupSize', $trip->groupSize) }}">
                                </label>
                            </div>
                            <label>Ngân sách cả nhóm (đ)
                                <input type="number" name="budget" min="0" step="100000" value="{{ old('budget', $trip->budget !== null ? (int) $trip->budget : '') }}" placeholder="Để trống nếu chưa tính">
                            </label>
                            {{-- T-29: La bàn điều chỉnh bản sao theo sở thích của bạn (thay nơi trúng "không thích" bằng nơi gần đó) --}}
                            <details class="cm-personal" @if (old('interests') || old('dislikes')) open @endif>
                                <summary>Điều chỉnh theo sở thích của tôi <small>(không bắt buộc)</small></summary>
                                <fieldset>
                                    <legend>Tôi thích</legend>
                                    @foreach ((array) config('interests') as $k => $def)
                                        <label class="cm-chip"><input type="checkbox" name="interests[]" value="{{ $k }}" @checked(in_array($k, (array) old('interests', []), true))> {{ $def['label'] }}</label>
                                    @endforeach
                                </fieldset>
                                <fieldset>
                                    <legend>Tôi không thích</legend>
                                    @foreach (['trekking' => 'Leo núi, đi bộ đường dài', 'crowds' => 'Chỗ đông người', 'outdoor' => 'Hoạt động ngoài trời'] as $k => $label)
                                        <label class="cm-chip"><input type="checkbox" name="dislikes[]" value="{{ $k }}" @checked(in_array($k, (array) old('dislikes', []), true))> {{ $label }}</label>
                                    @endforeach
                                </fieldset>
                                <p class="cm-muted" style="font-size:12.5px;margin:6px 0 0">Nơi trúng điều bạn không thích được thay bằng nơi gần đó hợp sở thích; không có nơi thay thì chuyển vào “Đã lưu”. Bạn xem lại được toàn bộ thay đổi sau khi tạo.</p>
                            </details>
                            <button type="submit" class="cm-btn"><i class="far fa-copy" aria-hidden="true"></i> Tạo chuyến đi của tôi</button>
                        </form>
                    @else
                        <p class="cm-muted" style="font-size:13.5px;margin:0 0 10px">Đăng nhập để chép lịch trình này thành chuyến đi của bạn.</p>
                        <a class="cm-btn" href="{{ route('login') }}">Đăng nhập</a>
                    @endif
                </section>

                @if ($related)
                    <section class="cm-box cm-rel">
                        <h2>Cùng điểm đến</h2>
                        @foreach ($related as $r)
                            <a href="{{ route('community.show', $r->tripId) }}">{{ $r->title }}<small>{{ $r->days }} ngày · {{ $r->likeCount }} lượt thích</small></a>
                        @endforeach
                    </section>
                @endif
            </aside>
        </div>
    </div>
</main>
<style>
.cm-personal{margin:4px 0 12px;border:1px solid #e5e7eb;border-radius:12px;padding:8px 12px;background:#fbfdfd}
.cm-personal summary{cursor:pointer;font-weight:600;font-size:14px}.cm-personal summary small{font-weight:400;color:#6b7280}
.cm-personal fieldset{border:0;margin:8px 0 0;padding:0;display:flex;flex-wrap:wrap;gap:6px}.cm-personal legend{font-size:13px;color:#4b5563;margin-bottom:4px;width:100%}
.cm-chip{display:inline-flex;align-items:center;gap:5px;font-size:13px;padding:4px 10px;border:1px solid #dbe5e8;border-radius:999px;background:#fff;cursor:pointer}
</style>
<script>
    (function () {
        var f = document.getElementById('cmLikeForm');
        if (!f || !window.fetch) return;
        f.addEventListener('submit', function (e) {
            e.preventDefault();
            var b = document.getElementById('cmLikeBtn');
            b.disabled = true;
            fetch(f.action, { method: 'POST', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: new FormData(f), credentials: 'same-origin' })
                .then(function (r) { if (!r.ok) throw r; return r.json(); })
                .then(function (d) {
                    b.classList.toggle('is-on', d.liked);
                    b.setAttribute('aria-pressed', d.liked ? 'true' : 'false');
                    b.innerHTML = '<i class="' + (d.liked ? 'fas' : 'far') + ' fa-heart" aria-hidden="true"></i> <span>' + (d.liked ? 'Đã thích' : 'Thích') + '</span>';
                    document.getElementById('cmLikes').textContent = d.likes;
                })
                .catch(function () { f.submit(); })
                .finally(function () { b.disabled = false; });
        });
    })();
</script>
@if ($mapPts)
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" defer></script>
<script type="application/json" id="cmxPts">{!! json_encode($mapPts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
<script>
    window.addEventListener('load', function () {
        var box = document.getElementById('cmxMap');
        if (!box || !window.L) { if (box) box.closest('.cmx-mapbox').hidden = true; return; }
        var pts = JSON.parse(document.getElementById('cmxPts').textContent);
        var C = ['#167A85', '#c2410c', '#7c3aed', '#0369a1', '#b45309', '#be185d', '#15803d'];
        var m = L.map(box, { scrollWheelZoom: false, attributionControl: true });
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 18, attribution: '© OpenStreetMap' }).addTo(m);
        var byDay = {}, all = [];
        pts.forEach(function (p) {
            var c = C[(p.d - 1) % C.length];
            L.marker([p.lat, p.lng], { icon: L.divIcon({ className: 'cmx-pin', html: '<span style="background:' + c + '">' + p.d + '</span>', iconSize: [24, 24] }) })
                .bindTooltip('Ngày ' + p.d + ': ' + p.name).addTo(m);
            (byDay[p.d] = byDay[p.d] || []).push([p.lat, p.lng]); all.push([p.lat, p.lng]);
        });
        Object.keys(byDay).forEach(function (d) { if (byDay[d].length > 1) L.polyline(byDay[d], { color: C[(d - 1) % C.length], weight: 3, opacity: .7 }).addTo(m); });
        m.fitBounds(all, { padding: [24, 24], maxZoom: 13 });
    });
</script>
@endif
@include('clients.blocks.footer')
