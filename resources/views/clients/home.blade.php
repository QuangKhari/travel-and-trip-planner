@include('clients.blocks.header_home')
{{--
  Mạch trang chủ: GỢI CẢM HỨNG TRƯỚC, bán tour sau.
  Khối nào chưa có dữ liệu thì tự ẩn (xem home_rail.blade.php).
--}}
@if (count($postcards) >= 3)
    @include('clients.blocks.hero-postcards')
@else
    @include('clients.blocks.banner_home')
@endif

@include('clients.blocks.home_strips', ['which' => 'season'])
@include('clients.blocks.interest_section')
@include('clients.blocks.home_strips', ['which' => 'foods'])
@include('clients.blocks.home_strips', ['which' => 'experiences'])
@include('clients.blocks.home_strips', ['which' => 'events'])
@include('clients.blocks.home_strips', ['which' => 'itineraries'])
@include('clients.blocks.home_strips', ['which' => 'guides'])

{{-- Cuối trang: chuyển cảm hứng thành hành động. #hpl là đích của "bưu ảnh trắng" trong hero. --}}
<section class="hq hq--end" aria-label="Bắt đầu chuyến đi">
    <div class="container">
        <div class="hq-card">
            @include('clients.blocks.home_planner')
            @if (count($postcards) >= 3)
                @include('clients.blocks.home_search_strip')
            @endif
        </div>
    </div>
</section>
@include('clients.blocks.tour_featured')

@include('clients.blocks.footer_home')
