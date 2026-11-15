@include('clients.blocks.header')
@include('clients.support._style')
@php
    $sigLabel = ['added' => 'thêm vào chuyến', 'visited' => 'đã ghé', 'applied' => 'nhận đề xuất', 'removed' => 'bỏ khỏi chuyến', 'skipped' => 'bỏ qua khi đi', 'ignored' => 'không nhận đề xuất'];
    $word = fn($s) => $s === null ? 'Chưa biết' : ($s >= 0.35 ? 'Bạn thích' : ($s >= 0.1 ? 'Hơi thích' : ($s > -0.1 ? 'Bình thường' : ($s > -0.35 ? 'Ít hợp' : 'Bạn ít chọn'))));
@endphp
<main class="sp"><div class="sp-wrap">
    <header class="sp-head"><h1>Travela nhớ gì về bạn</h1>
        <p>Travela học từ những gì bạn làm trong lịch (thêm, ghé, bỏ, nhận hay bỏ qua đề xuất) để xếp lịch hợp ý hơn. Đây là toàn bộ những gì đã học, bạn sửa hoặc xóa được.
            <a href="{{ route('planner') }}">← Chuyến đi của tôi</a></p></header>
    @if (session('success'))<div class="sp-alert" role="status" style="background:#e6f4f1;color:#135D65">{{ session('success') }}</div>@endif
    <section class="sp-card">
        <div class="sp-card__b">
            <p style="margin:0 0 8px">Dựa trên <b>{{ $total }}</b> thao tác trong 12 tháng qua. Chỉ bạn thấy trang này; Travela không dùng sở thích của bạn cho người khác.
                @if ($paused)<b style="color:#92400e">Đang tạm ngừng học.</b>@endif</p>
            <div style="display:flex;flex-wrap:wrap;gap:8px">
                @if ($ready)
                    <form method="POST" action="{{ route('planner.taste.pause') }}">@csrf<input type="hidden" name="on" value="{{ $paused ? 0 : 1 }}">
                        <button class="theme-btn style-two">{{ $paused ? 'Học tiếp từ thao tác của tôi' : 'Tạm ngừng học' }}</button></form>
                @endif
                <form method="POST" action="{{ route('planner.taste.forget') }}" onsubmit="return confirm('Xóa toàn bộ những gì Travela học về bạn?')">@csrf
                    <button class="theme-btn style-two">Xóa tất cả</button></form>
            </div>
        </div>
    </section>
    <ul class="tst">
        @foreach ($rows as $r)
            @php $cur = $r['override'] === null ? 'auto' : ['1' => 'like', '0' => 'neutral', '-1' => 'dislike'][(string) $r['override']]; @endphp
            <li class="tst__row">
                <div class="tst__h">
                    <b>{{ $labels[$r['grp']] ?? $r['grp'] }}</b>
                    <span class="tst__w {{ ($r['score'] ?? 0) >= 0.1 ? 'is-up' : (($r['score'] ?? 0) <= -0.1 ? 'is-down' : '') }}">{{ $word($r['score']) }}{{ $r['override'] !== null ? ' (bạn đặt)' : '' }}</span>
                    @if ($r['score'] !== null)
                        <span class="tst__bar" aria-hidden="true"><i style="left:{{ 50 + min(0, $r['score']) * 50 }}%;width:{{ abs($r['score']) * 50 }}%" class="{{ $r['score'] >= 0 ? 'is-up' : 'is-down' }}"></i></span>
                    @endif
                </div>
                <p class="tst__why">
                    @if ($r['counts'])
                        Vì bạn đã: {{ collect($r['counts'])->map(fn($n, $k) => $n . ' lần ' . ($sigLabel[$k] ?? $k))->implode(', ') }}.
                    @else
                        Chưa có thao tác nào với nhóm này.
                    @endif
                    @if ($r['examples']) Ví dụ: {{ implode(', ', $r['examples']) }}.@endif
                </p>
                @if ($ready)
                    <form method="POST" action="{{ route('planner.taste.set') }}" class="tst__set">@csrf<input type="hidden" name="grp" value="{{ $r['grp'] }}">
                        @foreach (['auto' => 'Để Travela tự học', 'like' => 'Tôi thích', 'neutral' => 'Bỏ qua nhóm này', 'dislike' => 'Tôi không thích'] as $k => $t)
                            <button name="value" value="{{ $k }}" class="tst__b {{ $cur === $k ? 'is-on' : '' }}" aria-pressed="{{ $cur === $k ? 'true' : 'false' }}">{{ $t }}</button>
                        @endforeach
                    </form>
                @endif
                @if ($r['counts'])
                    <form method="POST" action="{{ route('planner.taste.forget') }}">@csrf<input type="hidden" name="grp" value="{{ $r['grp'] }}">
                        <button class="tst__forget">Xóa những gì đã học về nhóm này</button></form>
                @endif
            </li>
        @endforeach
    </ul>
</div></main>
<style>
    .tst { list-style: none; margin: 16px 0 0; padding: 0; display: grid; gap: 10px; }
    .tst__row { background: #fff; border: 1px solid #dfe9e7; border-radius: 14px; padding: 12px 16px; }
    .tst__h { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; }
    .tst__w { font-weight: 700; font-size: 14px; color: #4d6b6e; } .tst__w.is-up { color: #166534; } .tst__w.is-down { color: #b42318; }
    .tst__bar { position: relative; flex: 1; min-width: 120px; max-width: 260px; height: 8px; background: #eef3f2; border-radius: 99px; }
    .tst__bar::after { content: ""; position: absolute; left: 50%; top: -2px; bottom: -2px; width: 2px; background: #9fb6b3; }
    .tst__bar i { position: absolute; top: 0; bottom: 0; border-radius: 99px; } .tst__bar i.is-up { background: #2f7d4f; } .tst__bar i.is-down { background: #d92d20; }
    .tst__why { margin: 6px 0; font-size: 14px; color: #35575c; }
    .tst__set { display: flex; flex-wrap: wrap; gap: 6px; }
    .tst__b { border: 1px solid #c9dcd8; background: #fff; border-radius: 999px; padding: 4px 12px; font-size: 13px; cursor: pointer; }
    .tst__b.is-on { background: #167a85; color: #fff; border-color: #167a85; }
    .tst__forget { border: 0; background: none; color: #8a3a2c; text-decoration: underline; font-size: 13px; padding: 4px 0; cursor: pointer; }
</style>
@include('clients.blocks.footer')
