{{-- Bong bóng liên hệ nổi + cấu hình cho khung chat (travel-chat.js).
     Trên trang /contact không hiện bong bóng (trang đó đã có khung chat lớn), nhưng vẫn nạp cấu hình. --}}
@php
    $cwPhone = config('site.phone');
    $cwZalo = config('site.zalo');
    $cwMessenger = config('site.messenger');
    $cwEmail = config('site.email');

    $cancelDays = \App\Support\CancelPolicy::MIN_DAYS;
    $holdHours = (int) config('travela.hold_hours', 48);

    $chatChips = ['Tư vấn chọn tour', 'Chính sách hủy tour', 'Thời gian giữ chỗ', 'Cách thanh toán'];
    $chatFaq = [
        'Chính sách hủy tour' => "Bạn có thể tự hủy đơn trong mục “Tour đã đặt” khi còn hơn {$cancelDays} ngày trước ngày khởi hành. Gần ngày hơn, vui lòng nhắn cho tư vấn viên để được hỗ trợ.",
        'Thời gian giữ chỗ' => "Sau khi đặt, chỗ của bạn được giữ {$holdHours} giờ để hoàn tất thanh toán. Quá hạn mà đơn chưa được xác nhận, đơn sẽ tự hủy.",
        'Cách thanh toán' => 'Hiện hỗ trợ thanh toán tại văn phòng. Thanh toán trực tuyến (VNPay) sẽ sớm được hỗ trợ.',
    ];
    // Lưu ý: không viết json nhiều dòng bằng lệnh Blade; Blade cắt chuỗi theo dấu phẩy nên sẽ gây ParseError.
    $chatConfig = [
        'url' => route('contact.send'),
        'csrf' => csrf_token(),
        'siteName' => config('site.name'),
        'title' => 'Hỗ trợ khách hàng',
        'chips' => $chatChips,
        'faq' => $chatFaq,
    ];
@endphp

<script>
    window.TV_CHAT = {!! json_encode(
        $chatConfig,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
    ) !!};
</script>

@unless (request()->routeIs('contact'))
    <div class="tc-widget" id="tcWidget">
        <section class="tc-panel" id="tcPanel" hidden role="dialog" aria-label="Chat hỗ trợ khách hàng"></section>

        <div class="tc-dial" id="tcDial" role="menu" aria-label="Các cách liên hệ">
            <button type="button" class="tc-dial__item" data-tc-open role="menuitem">
                <span class="tc-dial__ic is-chat"><i class="fal fa-comment-alt-dots"></i></span><b>Nhắn tin tại đây</b>
            </button>
            @if ($cwPhone)
                <a class="tc-dial__item" role="menuitem" href="tel:{{ preg_replace('/\s+/', '', $cwPhone) }}">
                    <span class="tc-dial__ic is-phone"><i class="fal fa-phone"></i></span><b>Gọi ngay</b>
                </a>
            @endif
            @if ($cwMessenger)
                <a class="tc-dial__item" role="menuitem" target="_blank" rel="noopener"
                    href="https://m.me/{{ $cwMessenger }}">
                    <span class="tc-dial__ic is-mess"><i class="fab fa-facebook-messenger"></i></span><b>Messenger</b>
                </a>
            @endif
            @if ($cwZalo)
                <a class="tc-dial__item" role="menuitem" target="_blank" rel="noopener"
                    href="https://zalo.me/{{ preg_replace('/\D+/', '', $cwZalo) }}">
                    <span class="tc-dial__ic is-zalo">Z</span><b>Zalo</b>
                </a>
            @endif
            @if ($cwEmail)
                <a class="tc-dial__item" role="menuitem" href="mailto:{{ $cwEmail }}">
                    <span class="tc-dial__ic is-mail"><i class="fal fa-envelope"></i></span><b>Gửi email</b>
                </a>
            @endif
        </div>

        <button type="button" class="tc-fab" id="tcFab" aria-expanded="false" aria-label="Liên hệ với chúng tôi">
            <i class="fal fa-headset tc-fab__open"></i>
            <i class="fal fa-times tc-fab__close"></i>
        </button>
    </div>
@endunless
