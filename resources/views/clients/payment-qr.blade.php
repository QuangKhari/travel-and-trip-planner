@include('clients.blocks.header')
@php $money = fn($v) => number_format((float) $v, 0, ',', '.') . ' đ'; @endphp
<style>
    .pq { padding: 130px 16px 80px; background: #f6faf9; min-height: 70vh; }
    .pq-card { max-width: 860px; margin: 0 auto; display: grid; grid-template-columns: 320px 1fr; gap: 28px; background: #fff; border: 1px solid #e3ecef; border-radius: 20px; padding: 28px; box-shadow: 0 12px 34px rgba(15, 47, 58, .07); }
    .pq-qr { text-align: center; }
    .pq-qr img { width: 100%; max-width: 300px; aspect-ratio: 1 / 1.15; object-fit: contain; border: 1px solid #e3ecef; border-radius: 14px; background: #fff; }
    .pq-qr small { display: block; color: #6b8592; margin-top: 8px; }
    .pq h1 { font-size: 22px; margin: 0 0 6px; color: #0f2f3a; }
    .pq-lead { color: #557080; margin: 0 0 16px; }
    .pq dl { margin: 0 0 16px; display: grid; grid-template-columns: 130px 1fr auto; gap: 8px 12px; align-items: center; }
    .pq dt { color: #6b8592; font-size: 14px; }
    .pq dd { margin: 0; font-weight: 700; color: #0f2f3a; word-break: break-all; }
    .pq dd.pq-amt { color: #168F99; font-size: 20px; }
    .pq-copy { border: 1px solid #cfdde2; background: #fff; border-radius: 8px; padding: 4px 10px; font-size: 12.5px; color: #0f2f3a; cursor: pointer; }
    .pq-copy:hover { background: #f4fbfa; }
    .pq-note { padding: 10px 14px; border-radius: 12px; background: #fffbeb; border: 1px solid #fde68a; color: #78350f; font-size: 13.5px; margin: 0 0 14px; }
    .pq-state { display: flex; gap: 10px; align-items: center; padding: 12px 14px; border-radius: 12px; background: #f0fdfa; border: 1px solid #ccebe7; color: #0f5f58; font-size: 14px; margin-bottom: 14px; }
    .pq-state.is-ok { background: #dcfce7; border-color: #86efac; color: #166534; }
    .pq-state.is-bad { background: #fee2e2; border-color: #fecaca; color: #991b1b; }
    .pq-dot { width: 10px; height: 10px; border-radius: 50%; background: currentColor; animation: pq-pulse 1.4s infinite; flex: none; }
    @keyframes pq-pulse { 50% { opacity: .25; } }
    @media (prefers-reduced-motion: reduce) { .pq-dot { animation: none; } }
    .pq-acts { display: flex; gap: 10px; flex-wrap: wrap; }
    .pq-btn { display: inline-flex; padding: 10px 18px; border-radius: 10px; background: #168F99; color: #fff; font-weight: 700; text-decoration: none; }
    .pq-btn:hover { background: #0f766e; color: #fff; }
    .pq-btn--ghost { background: #fff; color: #0f2f3a; border: 1px solid #cfdde2; }
    .pq-btn--ghost:hover { background: #f4fbfa; color: #0f2f3a; }
    @media (max-width: 760px) { .pq-card { grid-template-columns: 1fr; padding: 20px; } .pq dl { grid-template-columns: 110px 1fr auto; } }
</style>
<main class="pq">
    <div class="pq-card">
        <div class="pq-qr">
            <img src="{{ $qr['image'] }}" alt="Mã VietQR chuyển {{ $money($qr['amount']) }} vào tài khoản {{ $qr['accountNo'] }}, nội dung {{ $qr['content'] }}" width="300" height="345">
            <small>Mở app ngân hàng → Quét mã QR</small>
        </div>
        <div>
            <h1>Chuyển khoản bằng mã QR</h1>
            <p class="pq-lead">Đơn <b>{{ $b->bookingCode }}</b> · {{ $b->title }}</p>

            <div class="pq-state" id="pqState" role="status" aria-live="polite">
                <span class="pq-dot" aria-hidden="true"></span>
                <span id="pqStateText">{{ $qr['auto'] ? 'Đang chờ tiền về. Trang tự chuyển khi Travela nhận được tiền.' : 'Sau khi chuyển khoản, Travela đối chiếu và xác nhận đơn trong giờ làm việc.' }}</span>
            </div>

            <dl>
                <dt>Ngân hàng</dt><dd>{{ $qr['bank'] }}</dd><span></span>
                <dt>Số tài khoản</dt><dd>{{ $qr['accountNo'] }}</dd><button type="button" class="pq-copy" data-copy="{{ $qr['accountNo'] }}">Sao chép</button>
                <dt>Chủ tài khoản</dt><dd>{{ $qr['accountName'] }}</dd><span></span>
                <dt>Số tiền</dt><dd class="pq-amt">{{ $money($qr['amount']) }}</dd><button type="button" class="pq-copy" data-copy="{{ $qr['amount'] }}">Sao chép</button>
                <dt>Nội dung</dt><dd>{{ $qr['content'] }}</dd><button type="button" class="pq-copy" data-copy="{{ $qr['content'] }}">Sao chép</button>
            </dl>

            <p class="pq-note">Giữ nguyên <b>nội dung chuyển khoản</b> và <b>số tiền</b> để hệ thống nhận ra đơn của bạn.
                @if ($holdAt) Chỗ được giữ đến <b>{{ $holdAt->format('H:i d/m/Y') }}</b> (còn <b class="bk-countdown" data-deadline="{{ $holdAt->toIso8601String() }}">…</b>).@endif</p>

            <div class="pq-acts">
                <a class="pq-btn pq-btn--ghost" href="{{ route('tour-booked', ['bookingId' => $b->bookingId, 'checkoutId' => $b->checkoutId]) }}">Xem đơn / đổi cách thanh toán</a>
            </div>
        </div>
    </div>
</main>
<script>
    (function () {
        document.querySelectorAll('.pq-copy').forEach(function (b) {
            b.addEventListener('click', function () {
                var t = b.getAttribute('data-copy');
                (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(function () {
                    b.textContent = 'Đã chép';
                    setTimeout(function () { b.textContent = 'Sao chép'; }, 1500);
                }).catch(function () {});
            });
        });
        // đồng hồ đếm ngược hạn giữ chỗ
        var cd = document.querySelector('.bk-countdown');
        if (cd) {
            var end = new Date(cd.getAttribute('data-deadline')).getTime();
            var tick = function () {
                var s = Math.max(0, Math.floor((end - Date.now()) / 1000));
                cd.textContent = Math.floor(s / 60) + ' phút ' + String(s % 60).padStart(2, '0') + ' giây';
            };
            tick();
            setInterval(tick, 1000);
        }
        // hỏi trạng thái mỗi 5 giây; tiền về thì chuyển sang trang đơn
        var url = @json(route('payment.status', ['bookingId' => $b->bookingId]));
        var box = document.getElementById('pqState'), txt = document.getElementById('pqStateText'), stop = false;
        function poll() {
            if (stop || document.hidden) return;
            fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (j) {
                    if (!j) return;
                    if (j.paid) {
                        stop = true;
                        box.classList.add('is-ok');
                        txt.textContent = 'Đã nhận được tiền, đơn của bạn đã được xác nhận. Đang chuyển trang…';
                        setTimeout(function () { location.href = j.redirect; }, 1500);
                    } else if (j.expired) {
                        stop = true;
                        box.classList.add('is-bad');
                        txt.textContent = 'Đơn đã hết thời gian giữ chỗ. Nếu bạn đã chuyển tiền, Travela sẽ liên hệ để xác nhận lại hoặc hoàn tiền.';
                    }
                })
                .catch(function () {});
        }
        setInterval(poll, 5000);
    })();
</script>
@include('clients.blocks.footer')
