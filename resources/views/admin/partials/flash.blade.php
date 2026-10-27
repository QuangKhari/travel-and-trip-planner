{{-- Thông báo dùng chung: @include('admin.partials.flash') ở đầu mỗi trang. Nhận cả success, error, status và lỗi validate. --}}
@foreach (['success' => 'ok', 'status' => 'ok', 'error' => 'bad'] as $key => $kind)
    @if (session($key))
        <div class="ad-flash ad-flash--{{ $kind }}" data-auto role="status">
            <i class="fa {{ $kind === 'ok' ? 'fa-check-circle' : 'fa-exclamation-circle' }}"></i>
            <div>{{ session($key) }}</div>
        </div>
    @endif
@endforeach
@if ($errors->any())
    <div class="ad-flash ad-flash--bad" role="alert">
        <i class="fa fa-exclamation-circle"></i>
        <div>@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
    </div>
@endif
