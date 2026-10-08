@include('clients.blocks.header')

<div class="container" style="padding: 100px 20px; text-align: center;">
    <h2>{{ $success ? 'Kích hoạt tài khoản thành công' : 'Kích hoạt tài khoản thất bại' }}</h2>

    <p style="margin-top: 20px;">
        {{ $message }}
    </p>

    <p style="margin-top: 30px;">
        <a href="{{ route('login') }}" class="btn btn-primary">
            Đăng nhập
        </a>
    </p>
</div>

@include('clients.blocks.footer')
