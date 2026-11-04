@include('clients.blocks.header')
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;600&family=Bricolage+Grotesque:wght@500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('clients/assets/css/planner-ai-chat.css') }}?v=5">
<link rel="stylesheet" href="{{ asset('clients/assets/css/nature-luxury-planner.css') }}?v=bl20261104">
<link rel="stylesheet" href="{{ asset('clients/assets/css/travela-nature-all-pages.css') }}?v=bl20261104">

{{-- /len-ke-hoach/tro-ly : "Vạch hải trình". Trái = nhật ký hội thoại (không bong bóng), phải = hải đồ (la bàn, 2 phao, hải trình) + chú giải. JS vẽ cả hai. Biến: $cfg. --}}
<main class="pai" id="paiRoot">
    <section class="pai-log" aria-label="Kể chuyến đi của bạn">
        <header>
            <div class="pai-bar">
                <a href="{{ route('planner') }}">← Chuyến đi của tôi</a>
                <span>
                    @unless ($cfg['aiOn'])<span class="pai-badge" title="AI đang tắt: Travela đọc yêu cầu bằng bộ luật cơ bản">Chế độ cơ bản</span>@endunless
                    <button type="button" class="pai-reset" id="paiReset">Bắt đầu lại</button>
                </span>
            </div>
            <div class="pai-eyebrow"><i class="fas fa-compass" aria-hidden="true"></i> TRAVELA · LÊN KẾ HOẠCH VỚI AI</div>
            <h1>Kể chuyến đi của bạn</h1>
            <p>Chỉ cần kể nơi muốn đến và cách bạn thích du lịch. Travela sẽ hỏi thêm từng bước, sau đó cho bạn xem lại trước khi tạo lịch.</p>
            <div class="pai-progress" aria-label="Tiến độ thông tin chuyến đi"><div class="pai-progress__line"><span id="paiProgressFill"></span></div><span id="paiProgressLabel" role="status" aria-live="polite">Đang chuẩn bị câu hỏi…</span></div>
        </header>
        <div class="pai-rail" id="paiMsgs" role="log" aria-label="Hội thoại lập kế hoạch" aria-live="polite" aria-relevant="additions"></div>
        <div class="pai-network" id="paiNetwork" role="status" aria-live="polite" hidden>Mất kết nối mạng. Tin nhắn chưa được gửi; nội dung vẫn được giữ.</div>
        <div class="pai-opts" id="paiChips"></div>
        <form class="pai-in" id="paiForm" autocomplete="off">
            <label class="pai-inputlabel" for="paiTxt">Nội dung chuyến đi</label>
            <input id="paiTxt" maxlength="500" placeholder="Ví dụ: Mình muốn đi Đà Lạt 3 ngày, 2 người, thích thiên nhiên…" aria-label="Tin nhắn gửi Travela AI">
            <button type="submit" id="paiSend" aria-label="Gửi tin nhắn">Gửi <i class="fas fa-arrow-up" aria-hidden="true"></i></button>
        </form>
        <p class="pai-priv">AI chỉ đọc nội dung bạn gõ ở đây để xếp lịch. Lịch luôn do Travela kiểm chứng và bạn chỉnh được sau.</p>
    </section>
    <section class="pai-plate" id="paiPanel" aria-label="Hải đồ chuyến đi"></section>
</main>
<script type="application/json" id="paiData">@json($cfg)</script>
<script src="{{ asset('clients/assets/js/planner-ai-chat.js') }}?v=3" defer></script>
@include('clients.blocks.footer')
