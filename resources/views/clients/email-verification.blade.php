@include('clients.blocks.header')

<main class="tv-status">
    <div class="container">
        <div class="tv-status__card">
            <div class="tv-status__icon {{ $success ? 'is-ok' : 'is-fail' }}">
                <i class="fal {{ $success ? 'fa-check' : 'fa-times' }}"></i>
            </div>
            <h1>{{ $success ? 'Kích hoạt tài khoản thành công' : 'Không thể kích hoạt tài khoản' }}</h1>
            <p>{{ $message }}</p>
            <a href="{{ route('login') }}" class="tv-btn-solid">Đăng nhập</a>
        </div>
    </div>
</main>

@include('clients.blocks.footer')
