@include('clients.blocks.header_home')
{{--
  Mạch trang chủ (một câu chuyện, không phải nhiều cửa vào song song):
    Khi nào  : hero "Tháng N đang đẹp ở đâu?"
    Bắt đầu  : khối lên kế hoạch ngay dưới hero (bưu ảnh trắng cũng dẫn xuống đây)
    Có gì    : Đang vào mùa (điểm đến + lễ hội, bỏ điểm đã có trên hero)
    Đi đâu   : Khám phá theo cảm hứng
    Ăn gì    : Món ngon nên thử
    Làm gì   : Trải nghiệm đáng thử
    Đi thế nào: Lịch trình gợi ý -> tìm tour theo ngày -> tour nổi bật
    Chuẩn bị : Cẩm nang
--}}
@if (count($postcards) >= 3)
    @include('clients.blocks.hero-postcards')
@else
    @include('clients.blocks.banner_home')
@endif
@include('clients.blocks.home_planner')

@include('clients.blocks.home_strips', ['which' => 'season'])
@include('clients.blocks.interest_section')
@include('clients.blocks.home_strips', ['which' => 'foods'])
@include('clients.blocks.home_strips', ['which' => 'experiences'])
@include('clients.blocks.home_strips', ['which' => 'itineraries'])
@if (count($postcards) >= 3)
    @include('clients.blocks.home_search_strip')
@endif
@include('clients.blocks.tour_featured')
@include('clients.blocks.home_strips', ['which' => 'guides'])

@include('clients.blocks.footer_home')
