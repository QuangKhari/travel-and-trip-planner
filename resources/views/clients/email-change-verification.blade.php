@include('clients.blocks.header')

<div class="container py-5">
    <div class="text-center">
        @if ($success)
            <h2>Xác minh email thành công</h2>
            <p>{{ $message }}</p>
            <a href="{{ route('user-profile') }}" class="btn btn-primary">Quay lại hồ sơ</a>
        @else
            <h2>Không thể xác minh email</h2>
            <p>{{ $message }}</p>
            <a href="{{ route('user-profile') }}" class="btn btn-primary">Quay lại hồ sơ</a>
        @endif
    </div>
</div>

@include('clients.blocks.footer')
