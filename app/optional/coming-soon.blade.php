{{-- TÙY CHỌN: dùng thay cho blogs / blog-detail / travel-guides (hiện là nội dung mẫu của template).
     Chép nội dung file này đè lên từng file view đó, đổi $heading cho phù hợp. --}}
@include('clients.blocks.header')
@include('clients.blocks.banner')

@php($heading = 'Nội dung đang được cập nhật')

<main class="tv-status">
    <div class="container">
        <div class="tv-status__card">
            <div class="tv-status__icon is-ok"><i class="fal fa-pen-nib"></i></div>
            <h1>{{ $heading }}</h1>
            <p>Mục này sẽ sớm có bài viết thật. Trong lúc chờ, bạn có thể xem các tour đang mở bán.</p>
            <a href="{{ route('tours') }}" class="tv-btn-solid">Xem các tour</a>
        </div>
    </div>
</main>

@include('clients.blocks.footer')
