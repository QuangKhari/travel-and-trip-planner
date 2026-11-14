<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Hướng dẫn viên · {{ $dep->title }}</title>
    <style>
        :root { --b: #167A85; --ink: #111827; --mut: #6b7280; --line: #e5e7eb; --bg: #f4f7f7; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: var(--bg); color: var(--ink); }
        main { max-width: 720px; margin: 0 auto; padding: 16px; }
        h1 { font-size: 1.15rem; margin: 0 0 4px; }
        .mut { color: var(--mut); font-size: .85rem; }
        .card { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 12px 14px; margin: 12px 0; }
        .days { display: flex; gap: 6px; overflow-x: auto; }
        .days a { padding: 6px 12px; border-radius: 999px; border: 1px solid var(--line); text-decoration: none; color: var(--ink); white-space: nowrap; }
        .days a.on { background: var(--b); color: #fff; border-color: var(--b); }
        ul { list-style: none; margin: 0; padding: 0; }
        .bk li { display: grid; grid-template-columns: 1fr auto; gap: 6px; padding: 10px 0; border-bottom: 1px solid var(--line); align-items: center; }
        .bk li:last-child { border-bottom: 0; }
        .bk small { display: block; color: var(--mut); }
        .cnt { display: flex; gap: 4px; align-items: center; }
        .cnt button { width: 36px; height: 36px; border-radius: 10px; border: 1px solid var(--line); background: #fff; font-size: 1.1rem; }
        .cnt output { min-width: 44px; text-align: center; font-weight: 600; }
        .is-full output { color: #167A85; } .is-miss output { color: #B42318; }
        .st li { display: grid; grid-template-columns: 52px 1fr; gap: 8px; padding: 6px 0; }
        .st time { color: var(--mut); font-variant-numeric: tabular-nums; }
        label { display: block; font-size: .85rem; margin-top: 8px; }
        input, select, textarea { width: 100%; font: inherit; padding: 8px 10px; border: 1px solid var(--line); border-radius: 10px; }
        .btn { margin-top: 10px; padding: 10px 14px; border: 0; border-radius: 10px; background: var(--b); color: #fff; font: inherit; font-weight: 600; }
        .ok { background: #e6f4f1; color: #135D65; padding: 10px 12px; border-radius: 10px; }
        a.tel { color: var(--b); }
    </style>
</head>
<body><main>
    <h1>{{ $dep->title }}</h1>
    <p class="mut">Khởi hành {{ \Carbon\Carbon::parse($dep->departureDate)->format('d/m/Y') }} · {{ $days }} ngày @if ($l->guideName)· HDV {{ $l->guideName }}@endif · trang hết hạn {{ \Carbon\Carbon::parse($l->expiresOn)->format('d/m') }}</p>
    @if (session('success'))<p class="ok" role="status">{{ session('success') }}</p>@endif
    <nav class="days" aria-label="Chọn ngày">@for ($d = 1; $d <= $days; $d++)<a href="?ngay={{ $d }}" class="{{ $d === $day ? 'on' : '' }}">Ngày {{ $d }}</a>@endfor</nav>

    @php $total = $bookings->sum(fn($b) => $b->numAdults + $b->numChildren); $here = array_sum($checks); @endphp
    <section class="card">
        <h2 style="font-size:1rem;margin:0 0 4px">Điểm danh ngày {{ $day }}: <span id="gHere">{{ $here }}</span>/{{ $total }} khách</h2>
        <ul class="bk">
            @forelse ($bookings as $b)
                @php $n = $b->numAdults + $b->numChildren; $p = $checks[$b->bookingId] ?? null; @endphp
                <li class="{{ $p === null ? '' : ($p >= $n ? 'is-full' : 'is-miss') }}" data-id="{{ $b->bookingId }}" data-max="{{ $n }}">
                    <span><b>{{ $b->fullName }}</b> <small>{{ $b->bookingCode }} · {{ $b->numAdults }} NL{{ $b->numChildren ? ' · ' . $b->numChildren . ' TE' : '' }}{{ $b->bookingStatus === 'n' ? ' · chưa xác nhận' : '' }}
                        · <a class="tel" href="tel:{{ preg_replace('/\D/', '', (string) $b->phoneNumber) }}">{{ $b->phoneNumber }}</a></small></span>
                    <span class="cnt"><button type="button" data-d="-1" aria-label="Bớt một khách có mặt">−</button><output>{{ $p ?? '–' }}/{{ $n }}</output><button type="button" data-d="1" aria-label="Thêm một khách có mặt">+</button></span>
                </li>
            @empty<li class="mut">Đoàn chưa có khách.</li>@endforelse
        </ul>
    </section>

    @if ($stops)
        <section class="card"><h2 style="font-size:1rem;margin:0 0 4px">Lịch ngày {{ $day }}</h2>
            <ul class="st">@foreach ($stops as $s)<li><time>{{ $s->startMin !== null ? sprintf('%02d:%02d', intdiv($s->startMin, 60), $s->startMin % 60) : '' }}</time><span><b>{{ $s->title }}</b>@if ($s->note)<br><span class="mut">{{ $s->note }}</span>@endif</span></li>@endforeach</ul>
        </section>
    @endif

    <section class="card"><h2 style="font-size:1rem;margin:0">Báo sự cố cho điều hành</h2>
        <form method="POST" action="{{ route('guide.incident', $l->token) }}">@csrf
            <label>Loại<select name="type">@foreach ($types as $k => $t)<option value="{{ $k }}">{{ $t }}</option>@endforeach</select></label>
            <label>Mức độ<select name="severity">@foreach ($sev as $k => $t)<option value="{{ $k }}" @selected($k === 'medium')>{{ $t }}</option>@endforeach</select></label>
            <label>Chuyện gì xảy ra<input name="title" required minlength="5" maxlength="200" placeholder="Ví dụ: xe hỏng ở đèo Khau Phạ, dự kiến trễ 2 giờ"></label>
            <label>Chi tiết<textarea name="description" rows="3" maxlength="3000"></textarea></label>
            <button class="btn">Gửi về điều hành</button>
        </form>
    </section>
</main>
<script>
    (function () {
        var token = document.querySelector('meta[name=csrf-token]').content, url = @json(route('guide.checkin', $l->token)), day = {{ $day }};
        document.querySelectorAll(".bk li[data-id]").forEach(function (li) {
            var out = li.querySelector("output"), max = +li.dataset.max;
            li.querySelectorAll("button").forEach(function (b) {
                b.addEventListener("click", function () {
                    var cur = parseInt(out.textContent, 10); if (isNaN(cur)) cur = b.dataset.d > 0 ? 0 : max;
                    var v = Math.max(0, Math.min(max, cur + (+b.dataset.d)));
                    out.textContent = v + "/" + max;
                    li.className = v >= max ? "is-full" : "is-miss";
                    fetch(url, { method: "POST", headers: { "Content-Type": "application/json", "Accept": "application/json", "X-CSRF-TOKEN": token }, body: JSON.stringify({ bookingId: +li.dataset.id, dayNo: day, present: v }) });
                    var sum = 0; document.querySelectorAll(".bk output").forEach(function (o) { var n = parseInt(o.textContent, 10); if (!isNaN(n)) sum += n; });
                    document.getElementById("gHere").textContent = sum;
                });
            });
        });
    })();
</script>
</body></html>
