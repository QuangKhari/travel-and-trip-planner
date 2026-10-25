@include('clients.blocks.header_home')
{{--
  Mạch trang chủ (mỗi phần trả lời một câu hỏi; giao diện thống nhất bởi home-unify.css):
    Khi nào   : hero "Tháng N đang đẹp ở đâu?"
    Bắt đầu   : MỘT thẻ .hq-card gồm Lên kế hoạch + Tìm tour theo ngày (bưu ảnh trắng của hero dẫn xuống #hpl)
    Đi đâu    : Khám phá theo cảm hứng
    Đang diễn ra: điểm đến + lễ hội trong tháng (bỏ điểm đã có trên hero)
    Ăn gì / Làm gì: Món ngon nên thử, Trải nghiệm đáng thử
    Đi thế nào: Lịch trình gợi ý -> Tour nổi bật
    Chuẩn bị  : Cẩm nang
--}}
@if (count($postcards) >= 3)
    @include('clients.blocks.hero-postcards')
@else
    @include('clients.blocks.banner_home')
@endif

<section class="hq" aria-label="Bắt đầu chuyến đi">
    <div class="container">
        <div class="hq-card">
            @include('clients.blocks.home_planner')
            @if (count($postcards) >= 3)
                @include('clients.blocks.home_search_strip')
            @endif
        </div>
    </div>
</section>

@include('clients.blocks.interest_section')
@include('clients.blocks.home_strips', ['which' => 'season'])
@include('clients.blocks.home_strips', ['which' => 'foods'])
@include('clients.blocks.home_strips', ['which' => 'experiences'])
@include('clients.blocks.home_strips', ['which' => 'itineraries'])
@include('clients.blocks.tour_featured')
@include('clients.blocks.home_strips', ['which' => 'guides'])

@include('clients.blocks.footer_home')
