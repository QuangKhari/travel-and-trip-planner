@include('clients.blocks.header')

<main class="tv-status">
    <div class="container">
        <div class="tv-status__card">
            <div class="tv-status__icon {{ $success ? 'is-ok' : 'is-fail' }}">
                <i class="fal {{ $success ? 'fa-check' : 'fa-times' }}"></i>
            </div>
            <h1>{{ $success ? 'Xác minh email thành công' : 'Không thể xác minh email' }}</h1>
            <p>{{ $message }}</p>
            <a href="{{ route('user-profile') }}" class="tv-btn-solid">Quay lại hồ sơ</a>
        </div>
    </div>
</main>

@include('clients.blocks.footer')
