{{-- Cột ảnh bên phải của các trang tài khoản. Biến: $asideTitle, $asideText
     Ảnh: public/clients/assets/images/hero/auth-aside.jpg (đổi ảnh = thay file, giữ tên) --}}
@php
    $tips = [
        ['Du lịch theo cách của bạn', 'Chọn tour theo sở thích, giữ chỗ trước và thanh toán sau.'],
        ['Lịch trình rõ ràng từng ngày', 'Xem trước lịch trình, bản đồ và video của chuyến đi trước khi đặt.'],
        ['Mọi đơn đặt nằm trong một nơi', 'Theo dõi trạng thái, hủy hoặc đánh giá tour ngay trong tài khoản.'],
    ];
@endphp
<aside class="tv-auth__aside px-aside"
    style="background-image:url('{{ asset('clients/assets/images/hero/auth-aside.jpg') }}')">
    <div class="px-aside__tip" data-tips aria-live="polite">
        <i class="fas fa-heart px-aside__heart" aria-hidden="true"></i>
        <div class="px-aside__tip-body">
            @foreach ($tips as $i => $tip)
                <div class="px-aside__tip-item {{ $i === 0 ? 'is-on' : '' }}">
                    <b>{{ $tip[0] }}</b>
                    <p>{{ $tip[1] }}</p>
                </div>
            @endforeach
        </div>
        <button type="button" class="px-aside__next" data-tips-next aria-label="Mẹo tiếp theo"><i
                class="fal fa-chevron-right" aria-hidden="true"></i></button>
    </div>

    <div class="px-aside__cap">
        <h2>{{ $asideTitle ?? 'Khám phá thế giới, không giới hạn' }}</h2>
        <p>{{ $asideText ?? 'Lưu thông tin liên hệ, xem lại các đơn đã đặt và nhận email xác nhận ngay trong tài khoản của bạn.' }}
        </p>
    </div>
</aside>
<script src="{{ asset('clients/assets/js/travel-pages.js') }}?v=1" defer></script>
