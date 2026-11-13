@include('clients.blocks.header')
<link rel="stylesheet" href="{{ asset('clients/assets/css/community.css') }}?v=1">
@php
    $money = fn($n) => number_format((float) $n, 0, ',', '.') . 'đ';
    $start = $trip->startDate ? \Carbon\Carbon::parse($trip->startDate) : null;
    $cover = $trip->destCover ? \App\Support\DiscoverImage::url($trip->destCover) : null;
    $typeLabel = ['destination' => 'Điểm đến', 'place' => 'Địa điểm', 'food' => 'Món ăn', 'event' => 'Lễ hội', 'experience' => 'Trải nghiệm', 'tour' => 'Tour', 'note' => 'Ghi chú', 'transport' => 'Di chuyển'];
    $slotLabel = ['morning' => 'Buổi sáng', 'noon' => 'Buổi trưa', 'afternoon' => 'Buổi chiều', 'evening' => 'Buổi tối'];
    $loggedIn = (bool) session('userId');
@endphp
<main class="cm">
    <div class="cm-wrap">
        <a class="cm-back" href="{{ route('community.index') }}"><i class="fas fa-arrow-left" aria-hidden="true"></i> Cộng đồng hành trình</a>
        <div class="cm-detail">
            <div>
                <section class="cm-hero">
                    <div class="cm-hero__ph" @if ($cover) style="background-image:url('{{ $cover }}')" @endif></div>
                    <div class="cm-hero__b">
                        <h1>{{ $trip->title }}</h1>
                        @if ($trip->summary)<p class="cm-muted" style="margin:0">{{ $trip->summary }}</p>@endif
                        <div class="cm-facts">
                            <span><i class="far fa-calendar" aria-hidden="true"></i> {{ $trip->days }} ngày</span>
                            <span><i class="fas fa-user-friends" aria-hidden="true"></i> {{ $trip->groupSize }} người</span>
                            @if ($trip->destName)<span><i class="fas fa-map-marker-alt" aria-hidden="true"></i> {{ $trip->destName }}</span>@endif
                            @if ($trip->perPerson)<span><i class="fas fa-wallet" aria-hidden="true"></i> ≈ {{ $money($trip->perPerson) }}/người</span>@endif
                            @foreach ($trip->interestList as $i)<span class="cm-tag">{{ $interests[$i]['label'] ?? $i }}</span>@endforeach
                        </div>
                    </div>
                </section>

                @foreach ($payload['days'] as $d)
                    @if ($d['items'])
                        <section class="cm-day">
                            <h2>Ngày {{ $d['day'] }}@if ($start)<small>{{ $start->copy()->addDays($d['day'] - 1)->format('d/m') }}</small>@endif</h2>
                            <ol class="cm-items">
                                @foreach ($d['items'] as $it)
                                    <li>
                                        @php $tl = trim(implode(' · ', array_filter([$it['type'] === 'note' ? null : ($typeLabel[$it['type']] ?? null), $slotLabel[$it['slot'] ?? ''] ?? null]))); @endphp
                                        @if ($tl !== '')<span class="cm-type">{{ $tl }}</span>@endif
                                        @if (!empty($it['url']))<a href="{{ $it['url'] }}">{{ $it['name'] }}</a>@else<span class="cm-name">{{ $it['name'] }}</span>@endif
                                        @if ($it['sub'])<small>{{ $it['sub'] }}</small>@endif
                                        @if ($it['note'])<p class="cm-note">{{ $it['note'] }}</p>@endif
                                    </li>
                                @endforeach
                            </ol>
                            @if ($d['legs'])<p class="cm-muted" style="font-size:13px;margin:8px 0 0">Di chuyển khoảng {{ $d['km'] }} km (ước tính).</p>@endif
                        </section>
                    @endif
                @endforeach

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
                    <h2>Dùng hành trình này</h2>
                    @if ($isOwner)
                        <p class="cm-muted" style="font-size:13.5px;margin:0 0 10px">Đây là chuyến của bạn.</p>
                        <a class="cm-btn" href="{{ route('planner.edit', $trip->tripId) }}">Mở để chỉnh sửa</a>
                    @elseif ($loggedIn)
                        <p class="cm-muted" style="font-size:13.5px;margin:0 0 10px">Tạo một chuyến đi riêng của bạn với cùng lịch trình. Bạn sửa thoải mái, bản gốc không bị ảnh hưởng.</p>
                        @if ($errors->any())<div class="cm-err" role="alert" style="margin-bottom:10px">{{ $errors->first() }}</div>@endif
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
@include('clients.blocks.footer')
