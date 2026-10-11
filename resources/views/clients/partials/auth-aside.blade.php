{{-- Cột ảnh bên trái của các trang tài khoản. Biến: $asideTitle, $asideText --}}
<aside class="tv-auth__aside" style="background-image:url('{{ asset('clients/assets/images/hero/hero.jpg') }}')">
    <div class="tv-auth__aside-in">
        <a href="{{ route('home') }}" class="tv-auth__logo"><img src="{{ asset('clients/assets/images/logos/logo.png') }}"
                alt="{{ config('site.name') }}"></a>
        <div>
            <h2>{{ $asideTitle ?? 'Đặt tour gọn hơn, theo dõi dễ hơn' }}</h2>
            <p>{{ $asideText ?? 'Lưu thông tin liên hệ, xem lại các đơn đã đặt và nhận email xác nhận ngay trong tài khoản của bạn.' }}
            </p>
        </div>
    </div>
</aside>
