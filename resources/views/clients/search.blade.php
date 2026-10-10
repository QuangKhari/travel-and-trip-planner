@include('clients.blocks.header')
@include('clients.blocks.banner')

<section class="tv-search-page">
    <div class="container">
        @if ($tours->isEmpty())
            <div class="tv-empty">
                <div class="tv-empty__icon"><i class="fal fa-compass"></i></div>
                <h3>Không có tour nào khớp với tìm kiếm của bạn</h3>
                <p>
                    @if (!empty($keyword))
                        Chưa có kết quả cho “{{ $keyword }}”.
                    @endif
                    Hãy thử từ khóa ngắn hơn (tên điểm đến như Đà Nẵng, Phú Quốc) hoặc xem toàn bộ tour.
                </p>
                <a href="{{ route('tours') }}" class="tv-btn-solid">Xem tất cả tour</a>
            </div>
        @else
            <div class="tv-toolbar">
                <div class="tv-count">
                    Tìm thấy <b>{{ $tours->count() }}</b> tour
                    @if (!empty($keyword))
                        cho “<b>{{ $keyword }}</b>”
                    @endif
                </div>
                <a href="{{ route('tours') }}" class="tv-all">Lọc chi tiết hơn <i class="fal fa-arrow-right"></i></a>
            </div>
            <div class="tv-grid">
                @foreach ($tours as $tour)
                    @include('clients.partials.tour-card', ['tour' => $tour])
                @endforeach
            </div>
        @endif
    </div>
</section>

@include('clients.blocks.new_letter')
@include('clients.blocks.footer')
