@php
    $adRole = session('adminRole');
    $adMgr = in_array($adRole, ['admin', 'manager'], true);
    $on = fn(string ...$p) => request()->routeIs(...$p);
    $badge = \App\Support\AdminBadges::counts();
    $roleLabel = ['admin' => 'Quản trị viên', 'manager' => 'Quản lý', 'staff' => 'Nhân viên'][$adRole] ?? 'Nhân viên';
    $has = fn(string $r) => \Illuminate\Support\Facades\Route::has($r);

    // Nhóm "Soạn và duyệt": mỗi loại nội dung là một mục con
    $kindNow = request()->route('kind');
    $onKind = fn(string $k) => ($k === 'itineraries' && $on('admin.itinerary*'))
        || ($on('admin.content', 'admin.content.create', 'admin.content.edit', 'admin.content.preview') && ($kindNow ?? 'foods') === $k);
    $inTours = $on('admin.tours', 'admin.page-add-tours', 'admin.tour-edit', 'admin.departures*', 'admin.resources*', 'admin.incidents*', 'admin.waitlist*', 'admin.feasibility*', 'admin.profit*', 'admin.suppliers*');
    $inWrite = $on('admin.destinations*', 'admin.blog*', 'admin.itinerary*')
        || $on('admin.content', 'admin.content.create', 'admin.content.edit', 'admin.content.preview');
    $inReport = $on('admin.content.overview', 'admin.content.quality*');
    $inSetup = $on('admin.inspirations*', 'admin.guide-categories*');

    /** Một mục menu: [route, nhãn, icon, đang chọn?, số đếm] */
    $item = function (string $route, string $label, string $icon, bool $active, int $count = 0, array $params = []) use ($has) {
        if (!$has($route)) return '';
        $href = route($route, $params);
        $pill = $count > 0 ? '<span class="ad-nav__pill">' . ($count > 99 ? '99+' : $count) . '</span>' : '';
        return '<a class="ad-nav__link' . ($active ? ' is-on' : '') . '" href="' . e($href) . '"' . ($active ? ' aria-current="page"' : '') . ' title="' . e($label) . '">'
            . '<i class="fa ' . $icon . '" aria-hidden="true"></i><span class="ad-nav__txt">' . e($label) . '</span>' . $pill . '</a>';
    };
    $sub = function (string $route, string $label, bool $active, array $params = [], int $count = 0) use ($has) {
        if (!$has($route)) return '';
        $pill = $count > 0 ? '<span class="ad-nav__pill">' . ($count > 99 ? '99+' : $count) . '</span>' : '';
        return '<a class="ad-nav__sub' . ($active ? ' is-on' : '') . '" href="' . e(route($route, $params)) . '"' . ($active ? ' aria-current="page"' : '') . '>' . e($label) . $pill . '</a>';
    };
    // Việc về lịch khởi hành: lịch / đoàn sắp thiếu khách + phương án chờ áp dụng
    $depWork = (int) ($badge['shortfall'] ?? 0) + (int) ($badge['rescue'] ?? 0);
    $tourWork = $depWork + (int) ($badge['incident'] ?? 0) + (int) ($badge['waitlist'] ?? 0);
@endphp

<aside class="ad-side" id="ad-side" aria-label="Menu quản trị">
    <a href="{{ route('admin.dashboard') }}" class="ad-brand">
        <span class="ad-brand__logo"><i class="fa fa-compass" aria-hidden="true"></i></span>
        <span class="ad-brand__txt">{{ config('site.name', 'Travela') }}<small>Quản trị</small></span>
    </a>

    <nav class="ad-nav">
        <div class="ad-nav__group">
            {!! $item('admin.dashboard', 'Tổng quan', 'fa-th-large', $on('admin.dashboard')) !!}
        </div>

        <div class="ad-nav__group">
            <div class="ad-nav__h">Bán tour</div>
            {!! $item('admin.booking', 'Đơn đặt tour', 'fa-ticket', $on('admin.booking', 'admin.booking-detail', 'admin.transfer-proof', 'admin.booking.create'), $badge['booking']) !!}
            @if ($adMgr)
                {!! $item('admin.refunds', 'Hoàn tiền', 'fa-undo', $on('admin.refunds*'), ($badge['refund'] ?? 0)) !!}
                {!! $item('admin.payments', 'Thanh toán trực tuyến', 'fa-credit-card', $on('admin.payments*')) !!}
                {!! $item('admin.reconciliation', 'Đối soát', 'fa-balance-scale', $on('admin.reconciliation*'), (int) ($badge['reconcile'] ?? 0)) !!}
                {!! $item('admin.adjustments', 'Điều chỉnh số khách', 'fa-users', $on('admin.adjustments*'), (int) ($badge['adjust'] ?? 0)) !!}
                {!! $item('admin.complaints', 'Khiếu nại', 'fa-commenting-o', $on('admin.complaints*'), (int) ($badge['complaint'] ?? 0)) !!}
                <details class="ad-nav__fold" {{ $inTours ? 'open' : '' }}>
                    <summary class="ad-nav__link{{ $inTours ? ' is-in' : '' }}" title="Tour"><i class="fa fa-suitcase" aria-hidden="true"></i><span class="ad-nav__txt">Tour</span>@if ($tourWork > 0)<span class="ad-nav__pill" title="Lịch sắp thiếu khách / phương án chờ / sự cố tour">{{ $tourWork }}</span>@endif<i class="fa fa-angle-down ad-nav__caret" aria-hidden="true"></i></summary>
                    <div class="ad-nav__subs">
                        {!! $sub('admin.tours', 'Danh sách tour', $on('admin.tours', 'admin.tour-edit')) !!}
                        {!! $sub('admin.departures', 'Lịch khởi hành', $on('admin.departures', 'admin.departures.store', 'admin.departures.update', 'admin.departures.toggle', 'admin.departures.destroy')) !!}
                        {!! $sub('admin.resources', 'Xếp xe & HDV', $on('admin.resources*')) !!}
                        {!! $sub('admin.departures.rescue', 'Xử lý thiếu khách', $on('admin.departures.rescue*'), [], $depWork) !!}
                        {!! $sub('admin.departures.grouping', 'Ghép đoàn', $on('admin.departures.grouping*')) !!}
                        {!! $sub('admin.incidents', 'Sự cố tour', $on('admin.incidents*'), [], (int) ($badge['incident'] ?? 0)) !!}
                        {!! $sub('admin.waitlist', 'Danh sách chờ', $on('admin.waitlist*'), [], (int) ($badge['waitlist'] ?? 0)) !!}
                        {!! $sub('admin.feasibility', 'Kiểm tra khả thi', $on('admin.feasibility*')) !!}
                        {!! $sub('admin.profit', 'Lợi nhuận đoàn', $on('admin.profit*')) !!}
                        {!! $sub('admin.suppliers', 'Nhà cung cấp', $on('admin.suppliers*')) !!}
                        {!! $sub('admin.page-add-tours', 'Thêm tour mới', $on('admin.page-add-tours')) !!}
                    </div>
                </details>
                {!! $item('admin.promotion', 'Khuyến mãi', 'fa-tags', $on('admin.promotion', 'admin.promotion-edit')) !!}
            @endif
            {!! $item('admin.reviews', 'Đánh giá', 'fa-star-o', $on('admin.reviews*')) !!}
        </div>

        <div class="ad-nav__group">
            <div class="ad-nav__h">Khách hàng</div>
            {!! $item('admin.support', 'Yêu cầu tư vấn', 'fa-life-ring', $on('admin.support*'), $badge['support'] ?? 0) !!}
            @if ($adMgr)
                {!! $item('admin.contact', 'Hộp thư liên hệ', 'fa-envelope-o', $on('admin.contact*'), $badge['contact']) !!}
                {!! $item('admin.users', 'Người dùng', 'fa-users', $on('admin.users*')) !!}
                {!! $item('admin.trips', 'Chuyến đi (Planner)', 'fa-map-o', $on('admin.trips*')) !!}
                {!! $item('admin.content-requests', 'Nhu cầu thiếu dữ liệu', 'fa-lightbulb-o', $on('admin.content-requests*')) !!}
            @endif
        </div>

        @if ($adMgr)
            <div class="ad-nav__group">
                <div class="ad-nav__h">Nội dung Khám phá</div>
                <details class="ad-nav__fold" {{ $inWrite ? 'open' : '' }}>
                    <summary class="ad-nav__link{{ $inWrite ? ' is-in' : '' }}" title="Soạn và duyệt"><i class="fa fa-pencil-square-o" aria-hidden="true"></i><span class="ad-nav__txt">Soạn và duyệt</span>
                        @if ($badge['pending'] > 0)<span class="ad-nav__pill" title="Bài chưa xuất bản">{{ $badge['pending'] }}</span>@endif
                        <i class="fa fa-angle-down ad-nav__caret" aria-hidden="true"></i></summary>
                    <div class="ad-nav__subs">
                        {!! $sub('admin.destinations', 'Điểm đến', $on('admin.destinations*')) !!}
                        {!! $sub('admin.content', 'Món ăn', $onKind('foods'), ['foods']) !!}
                        {!! $sub('admin.content', 'Trải nghiệm', $onKind('experiences'), ['experiences']) !!}
                        {!! $sub('admin.content', 'Lễ hội', $onKind('events'), ['events']) !!}
                        {!! $sub('admin.content', 'Địa điểm (OSM)', $onKind('places'), ['places']) !!}
                        {!! $sub('admin.content', 'Lịch trình mẫu', $onKind('itineraries'), ['itineraries']) !!}
                        {!! $sub('admin.content', 'Cẩm nang', $onKind('guides'), ['guides']) !!}
                        {!! $sub('admin.blog', 'Blog', $on('admin.blog*')) !!}
                    </div>
                </details>
                <details class="ad-nav__fold" {{ $inReport ? 'open' : '' }}>
                    <summary class="ad-nav__link{{ $inReport ? ' is-in' : '' }}" title="Báo cáo chất lượng"><i class="fa fa-bar-chart" aria-hidden="true"></i><span class="ad-nav__txt">Báo cáo chất lượng</span><i class="fa fa-angle-down ad-nav__caret" aria-hidden="true"></i></summary>
                    <div class="ad-nav__subs">
                        {!! $sub('admin.content.overview', 'Số bài và độ phủ', $on('admin.content.overview')) !!}
                        {!! $sub('admin.content.quality', 'Điểm và lỗi dữ liệu', $on('admin.content.quality*')) !!}
                    </div>
                </details>
                <details class="ad-nav__fold" {{ $inSetup ? 'open' : '' }}>
                    <summary class="ad-nav__link{{ $inSetup ? ' is-in' : '' }}" title="Danh mục dùng chung"><i class="fa fa-sliders" aria-hidden="true"></i><span class="ad-nav__txt">Danh mục</span><i class="fa fa-angle-down ad-nav__caret" aria-hidden="true"></i></summary>
                    <div class="ad-nav__subs">
                        {!! $sub('admin.inspirations', 'Cảm hứng', $on('admin.inspirations*')) !!}
                        {!! $sub('admin.guide-categories', 'Danh mục cẩm nang', $on('admin.guide-categories*')) !!}
                    </div>
                </details>
            </div>

            <div class="ad-nav__group">
                <div class="ad-nav__h">Hệ thống</div>
                {!! $item('admin.admin', session('adminRole') === 'admin' ? 'Tài khoản quản trị' : 'Tài khoản & nhân viên', 'fa-shield', $on('admin.admin')) !!}
                {!! $item('admin.audit', 'Nhật ký thao tác', 'fa-history', $on('admin.audit*')) !!}
                {!! $item('admin.chat', 'Nhật ký hỏi đáp & AI', 'fa-comments-o', $on('admin.chat*')) !!}
                {!! $item('admin.system', 'Bảo trì hệ thống', 'fa-heartbeat', $on('admin.system*')) !!}
            </div>
        @endif
    </nav>

    <div class="ad-side__foot">
        <button type="button" class="ad-nav__link ad-logout" id="btn-logout-admin" title="Đăng xuất">
            <i class="fa fa-sign-out" aria-hidden="true"></i><span class="ad-nav__txt">Đăng xuất</span>
        </button>
    </div>
</aside>
<div class="ad-scrim" id="ad-scrim" hidden></div>

<header class="ad-top">
    <button type="button" class="ad-top__btn" id="ad-nav-toggle" aria-controls="ad-side" aria-label="Thu gọn / mở menu"><i class="fa fa-bars"></i></button>
    <div class="ad-top__title">
        <small>Quản trị</small>
        <span>{{ $title ?? 'Admin' }}</span>
    </div>
    <div class="ad-top__right">
        <a class="ad-top__link" href="{{ url('/') }}" target="_blank" rel="noopener"><i class="fa fa-external-link"></i><span>Xem website</span></a>
        <a class="ad-user" href="{{ route('admin.admin') }}" title="Tài khoản của tôi">
            <img src="{{ \App\Support\Avatar::admin() }}" alt="" width="32" height="32">
            <span><b>{{ session('admin') }}</b><small>{{ $roleLabel }}</small></span>
        </a>
    </div>
</header>

<form id="form-logout-admin" action="{{ route('admin.logout') }}" method="POST" hidden>@csrf</form>
