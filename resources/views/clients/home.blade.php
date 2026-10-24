@include('clients.blocks.header_home')
@if (count($postcards) >= 3)
    @include('clients.blocks.hero-postcards')
    @include('clients.blocks.home_planner')
    @include('clients.blocks.home_search_strip')
@else
    @include('clients.blocks.banner_home')
    {{-- Chưa đủ ảnh cho hero: khối Planner vẫn hiện (D4 không phụ thuộc hero) --}}
    @include('clients.blocks.home_planner')
@endif

{{-- Thứ tự dải theo tài liệu mục 4.1: mùa, lễ hội, cảm hứng, ẩm thực, tour, lịch trình, cẩm nang --}}
@include('clients.blocks.home_strips', ['which' => 'season'])
{{-- Lễ hội đã gộp vào dải "Đang vào mùa" (seasonMix). Muốn tách lại: bỏ comment dòng dưới. --}}
{{-- @include('clients.blocks.home_strips', ['which' => 'events']) --}}
@include('clients.blocks.interest_section')
@include('clients.blocks.home_strips', ['which' => 'foods'])
@include('clients.blocks.tour_featured')
@include('clients.blocks.home_strips', ['which' => 'itineraries'])
@include('clients.blocks.home_strips', ['which' => 'guides'])

@include('clients.blocks.footer_home')
