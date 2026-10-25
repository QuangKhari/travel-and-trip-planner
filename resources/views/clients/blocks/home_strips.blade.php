{{-- Các dải trang chủ theo tài liệu mục 4.1. Cần: $feed (HomeFeedService), $month. Dải "theo cảm hứng" và "tour nổi bật" include riêng ở home.blade.php. --}}
@php
    $plannerBase = config('postcards.planner_url');
    $monthHref = $plannerBase ? url($plannerBase) . '?thang=' . $month : route('seasons.now', ['thang' => $month]);
    $plannerHref = $plannerBase ? url($plannerBase) : route('itineraries.index');
@endphp

@if ($which === 'season')
    @php
        // Hero đã khoe các điểm đến đẹp trong tháng; dải này bổ sung lễ hội và điểm khác, không lặp lại. Còn quá ít thì giữ nguyên danh sách.
        $heroSlugs = collect($postcards ?? [])->pluck('slug')->filter()->all();
        $mixAll = collect(!empty($feed['seasonMix']) ? $feed['seasonMix'] : $feed['season']);
        $mixDedup = $mixAll->reject(fn($c) => ($c->kind ?? '') === 'destination' && in_array($c->slug, $heroSlugs, true))->values();
        $seasonItems = $mixDedup->count() >= 3 ? $mixDedup->all() : $mixAll->all();
    @endphp
    @include('clients.blocks.home_rail', [
        'id' => 'dang-vao-mua',
        'eyebrow' => 'Đang diễn ra',
        'title' => 'Đang vào mùa',
        'lead' => 'Điểm đến đẹp nhất và lễ hội sắp tới trong tháng ' . $month . '. Ngày lễ hội đã quy đổi từ lịch âm, kiểm tra lại nguồn chính thức trước khi đi.',
        'items' => $seasonItems,
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
    @include('clients.blocks.home_food_accordion', [
        'items' => $feed['foods'],
        'moreUrl' => route('foods.index'),
        'moreLabel' => 'Tất cả món ăn',
    ])
@elseif ($which === 'experiences')
    @include('clients.blocks.home_rail', [
        'id' => 'lam-gi',
        'eyebrow' => 'Làm gì',
        'title' => 'Trải nghiệm đáng thử',
        'lead' => 'Những việc đáng làm khi đến từng nơi: đi thuyền, học nấu ăn, xem biểu diễn, ngắm cảnh.',
        'items' => $feed['experiences'] ?? [],
        'moreUrl' => \Illuminate\Support\Facades\Route::has('experiences.index') ? route('experiences.index') : null,
        'moreLabel' => 'Tất cả trải nghiệm',
    ])
@elseif ($which === 'itineraries')
    @include('clients.blocks.home_rail', [
        'id' => 'lich-trinh-goi-y',
        'eyebrow' => 'Đi thế nào',
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
        'eyebrow' => 'Chuẩn bị',
        'title' => 'Cẩm nang và tin mới',
        'items' => $feed['guides'],
        'moreUrl' => route('guides.index'),
        'moreLabel' => 'Tất cả cẩm nang',
    ])
@endif
