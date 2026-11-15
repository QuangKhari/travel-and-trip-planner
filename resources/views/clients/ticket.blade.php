{{-- /ve-tour/{bookingId}: vé điện tử của đơn tour. Trang nhẹ, tự đủ (không header/footer chung) để mở nhanh và dùng khi MẤT MẠNG:
     sw.js lưu bản sao sau lần mở đầu; QR vẽ tại chỗ bằng qrcode-generator (MIT, đã đặt trong public/clients/assets/vendor).
     Biến: $b (đơn + tour), $ok (có vé), $code (mã ký số), $meet (điểm tập trung ngày 1, TourStops), $policy (CancelPolicy::freeUntil), $phone. --}}
@php
    $start = \Carbon\Carbon::parse($b->startDate);
    $guests = (int) $b->numAdults + (int) $b->numChildren;
    $hm = fn($m) => $m === null ? null : sprintf('%02d:%02d', intdiv((int) $m, 60), (int) $m % 60);
@endphp
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
    <meta name="theme-color" content="#167A85">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <title>Vé tour · {{ $b->title }}</title>
    <style>
        :root { --b: #167A85; --ink: #10272b; --mut: #5b6f72; --line: #dfe9e7; --bg: #f2f7f6; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: var(--bg); color: var(--ink); }
        main { max-width: 460px; margin: 0 auto; padding: 16px; }
        .tk { background: #fff; border-radius: 18px; border: 1px solid var(--line); overflow: hidden; }
        .tk__h { background: var(--b); color: #fff; padding: 14px 16px; }
        .tk__h small { opacity: .85; display: block; font-size: .8rem; letter-spacing: .04em; text-transform: uppercase; }
        .tk__h h1 { margin: 2px 0 0; font-size: 1.12rem; line-height: 1.3; }
        .tk__qr { display: grid; place-items: center; padding: 18px 16px 8px; border-bottom: 2px dashed var(--line); }
        .tk__qr canvas, .tk__qr img { width: 220px; height: 220px; image-rendering: pixelated; }
        .tk__code { font: 700 1.05rem/1.2 ui-monospace, SFMono-Regular, Menlo, monospace; letter-spacing: .06em; margin: 8px 0 4px; }
        .tk__q { font-size: .82rem; color: var(--mut); text-align: center; margin: 0 0 8px; }
        dl { margin: 0; padding: 12px 16px; display: grid; grid-template-columns: auto 1fr; gap: 6px 12px; font-size: .92rem; }
        dt { color: var(--mut); } dd { margin: 0; font-weight: 600; }
        .tk__join { padding: 12px 16px; border-top: 1px solid var(--line); font-size: .9rem; }
        .tk__join h2 { font-size: .95rem; margin: 0 0 4px; }
        .tk__join p { margin: 4px 0; }
        .tk__note { font-size: .8rem; color: var(--mut); margin: 12px 4px; }
        .tk__off { display: none; background: #fff4e5; color: #92400e; padding: 8px 12px; border-radius: 10px; font-size: .85rem; margin-bottom: 10px; }
        .tk__off.on { display: block; }
        .tk__bad { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 16px; }
        a { color: var(--b); }
        .tk__cancel { padding: 0 16px 12px; font-size: .85rem; color: #166534; font-weight: 600; }
        @media print { body { background: #fff; } .tk__note, .tk__off, .tk__back { display: none !important; } }
    </style>
</head>
<body><main>
    <p class="tk__off" id="tkOff" role="status">Đang mất mạng: bạn đang xem bản đã lưu của vé. Mã QR vẫn dùng được.</p>
    <p class="tk__back"><a href="{{ route('tour-booked', ['bookingId' => $b->bookingId, 'checkoutId' => \Illuminate\Support\Facades\DB::table('tbl_checkout')->where('bookingId', $b->bookingId)->value('checkoutId')]) }}">← Chi tiết đơn</a></p>
    @if (!$ok)
        <div class="tk__bad">
            <h1 style="font-size:1.05rem;margin:0 0 6px">Đơn chưa có vé điện tử</h1>
            <p>Vé có khi đơn đã được xác nhận hoặc đã thanh toán. {{ $b->bookingStatus === 'c' ? 'Đơn này đã hủy.' : 'Travela sẽ gửi thông báo khi đơn được xác nhận.' }}</p>
        </div>
    @else
        <article class="tk" aria-label="Vé điện tử">
            <header class="tk__h"><small>Vé điện tử · Travela</small><h1>{{ $b->title }}</h1></header>
            <div class="tk__qr">
                <div id="tkQr" role="img" aria-label="Mã QR vé {{ $code }}"></div>
                <div class="tk__code" id="tkCode">{{ $code }}</div>
                <p class="tk__q">Đưa mã này cho hướng dẫn viên khi tập trung. Không chia sẻ ảnh vé cho người ngoài đoàn.</p>
            </div>
            <dl>
                <dt>Khởi hành</dt><dd>{{ $start->format('d/m/Y') }} ({{ ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'][$start->dayOfWeek] }})</dd>
                <dt>Số khách</dt><dd>{{ $guests }} ({{ (int) $b->numAdults }} người lớn{{ $b->numChildren ? ', ' . (int) $b->numChildren . ' trẻ em' : '' }})</dd>
                <dt>Mã đơn</dt><dd>{{ $b->bookingCode ?? '#' . $b->bookingId }}</dd>
                <dt>Người đặt</dt><dd>{{ $b->fullName }}</dd>
            </dl>
            @if ($policy && $policy['percent'] > 0)<p class="tk__cancel">{{ $policy['text'] }}</p>@endif
            <section class="tk__join">
                <h2>Cách tham gia</h2>
                @if ($meet)
                    <p><b>{{ $hm($meet->startMin) ? $hm($meet->startMin) . ' · ' : '' }}{{ $meet->title }}</b></p>
                    @if (!empty($meet->note))<p>{{ $meet->note }}</p>@endif
                    @if (!empty($meet->latitude) && !empty($meet->longitude))
                        <p><a href="https://www.google.com/maps/search/?api=1&query={{ $meet->latitude }},{{ $meet->longitude }}" target="_blank" rel="noopener">Mở chỉ đường tới điểm tập trung</a></p>
                    @endif
                @else
                    <p>Điểm và giờ tập trung sẽ được hướng dẫn viên gọi xác nhận trước ngày đi.</p>
                @endif
                <p>Có mặt trước giờ tập trung 15 phút, mang giấy tờ tùy thân.@if ($phone) Cần hỗ trợ: <a href="tel:{{ preg_replace('/\D/', '', (string) $phone) }}">{{ $phone }}</a>.@endif</p>
            </section>
        </article>
        <p class="tk__note">Đã mở trang này một lần khi có mạng thì vé được lưu trên máy, mở lại được khi mất sóng. Có thể bấm “Thêm vào màn hình chính” để mở nhanh.</p>
    @endif
</main>
@if ($ok)
<script src="{{ asset('clients/assets/vendor/qrcode-generator-1.4.4.js') }}"></script>
<script>
    (function () {
        var box = document.getElementById("tkQr"), code = @json($code);
        try {
            var q = qrcode(0, "M"); q.addData(code); q.make();
            box.innerHTML = q.createSvgTag({ cellSize: 6, margin: 2, scalable: true });   // SVG do thư viện tạo từ mã ký số (chỉ chữ số / chữ in hoa)
            var svg = box.querySelector("svg"); if (svg) { svg.setAttribute("width", "220"); svg.setAttribute("height", "220"); svg.setAttribute("aria-hidden", "true"); }
        } catch (e) { box.textContent = "Không vẽ được mã QR, đọc mã bên dưới cho hướng dẫn viên."; }
        function net() { document.getElementById("tkOff").classList.toggle("on", navigator.onLine === false); }
        window.addEventListener("online", net); window.addEventListener("offline", net); net();
        if ("serviceWorker" in navigator) navigator.serviceWorker.register("/sw.js").catch(function () {});
    })();
</script>
@endif
</body></html>
