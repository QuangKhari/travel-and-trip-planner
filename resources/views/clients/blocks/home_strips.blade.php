{{-- Các dải trang chủ theo tài liệu mục 4.1. Cần: $feed (HomeFeedService), $month. Dải "theo cảm hứng" và "tour nổi bật" include riêng ở home.blade.php. --}}
@php
    $plannerBase = config('postcards.planner_url');
    $monthHref = $plannerBase ? url($plannerBase) . '?thang=' . $month : route('seasons.now', ['thang' => $month]);
    $plannerHref = $plannerBase ? url($plannerBase) : route('itineraries.index');
@endphp

@if ($which === 'season')
    @include('clients.blocks.home_rail', [
        'id' => 'dang-vao-mua',
        'eyebrow' => 'Đang vào mùa',
        'title' => 'Tháng ' . $month . ' nên đi đâu?',
        'lead' => 'Những điểm đến đang đẹp nhất trong tháng này.',
        'items' => $feed['season'],
        'cta' => ['url' => $monthHref, 'label' => $plannerBase ? 'Lên kế hoạch cho tháng này' : 'Xem tất cả tháng ' . $month],
        'moreUrl' => route('seasons.matrix'),
        'moreLabel' => 'Bảng "Nên đi tháng nào" cả năm',
    ])
@elseif ($which === 'events')
    @include('clients.blocks.home_rail', [
        'id' => 'le-hoi-sap-toi',
        'eyebrow' => 'Lễ hội',
        'title' => 'Lễ hội sắp diễn ra',
        'lead' => 'Ngày dương đã quy đổi từ lịch âm, kiểm tra lại với nguồn chính thức trước khi đi.',
        'items' => $feed['events'],
        'moreUrl' => route('events.index'),
        'moreLabel' => 'Tất cả lễ hội',
    ])
@elseif ($which === 'foods')
    @include('clients.blocks.home_rail', [
        'id' => 'am-thuc-nen-thu',
        'eyebrow' => 'Ẩm thực',
        'title' => 'Món nên thử',
        'items' => $feed['foods'],
        'moreUrl' => route('foods.index'),
        'moreLabel' => 'Tất cả món ăn',
    ])
@elseif ($which === 'itineraries')
    @include('clients.blocks.home_rail', [
        'id' => 'lich-trinh-goi-y',
        'eyebrow' => 'Lịch trình gợi ý',
        'title' => 'Đi mấy ngày, đi những đâu?',
        'lead' => 'Lịch trình mẫu theo ngày, dùng làm điểm xuất phát để chỉnh lại theo ý bạn.',
        'items' => $feed['itins'],
        'tail' => true,
        'plannerHref' => $plannerHref,
        'moreUrl' => route('itineraries.index'),
        'moreLabel' => 'Tất cả lịch trình',
    ])
@elseif ($which === 'guides')
    @include('clients.blocks.home_rail', [
        'id' => 'cam-nang-moi',
        'eyebrow' => 'Cẩm nang',
        'title' => 'Cẩm nang và tin mới',
        'items' => $feed['guides'],
        'moreUrl' => route('guides.index'),
        'moreLabel' => 'Tất cả cẩm nang',
    ])
@endif
