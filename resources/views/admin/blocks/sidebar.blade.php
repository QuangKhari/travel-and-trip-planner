@php
    $adRole = session('adminRole');
    $adMgr = in_array($adRole, ['admin', 'manager'], true);
    $on = fn(string ...$p) => request()->routeIs(...$p);
    $badge = \App\Support\AdminBadges::counts();
    $roleLabel = ['admin' => 'Admin', 'manager' => 'Quản lý', 'staff' => 'Nhân viên'][$adRole] ?? 'Nhân viên';

    $inTours = $on('admin.tours', 'admin.page-add-tours', 'admin.tour-edit');
    $inContent = $on('admin.content*', 'admin.destinations*', 'admin.inspirations*', 'admin.guide-categories*', 'admin.blog*', 'admin.itinerary*');
@endphp

<div class="col-md-3 left_col">
    <div class="left_col scroll-view">
        <div class="navbar nav_title" style="border:0;">
            <a href="{{ route('admin.dashboard') }}" class="site_title"><i class="fa fa-paw"></i> <span>{{ config('site.name', 'Travela') }}</span></a>
        </div>
        <div class="clearfix"></div>

        <div id="sidebar-menu" class="main_menu_side hidden-print main_menu">
            <div class="menu_section">
                <h3>Tổng quan</h3>
                <ul class="nav side-menu">
                    <li class="{{ $on('admin.dashboard') ? 'ad-on' : '' }}"><a href="{{ route('admin.dashboard') }}"><i class="fa fa-dashboard"></i> Dashboard</a></li>
                </ul>

                <h3>Vận hành</h3>
                <ul class="nav side-menu">
                    <li class="{{ $on('admin.booking*', 'admin.transfer-proof') ? 'ad-on' : '' }}">
                        <a href="{{ route('admin.booking') }}"><i class="fa fa-ticket"></i> Đơn đặt tour
                            @if ($badge['booking'] > 0)<span class="ad-pill" title="Đơn chưa xác nhận">{{ $badge['booking'] }}</span>@endif
                        </a>
                    </li>
                    <li class="{{ $on('admin.reviews*') ? 'ad-on' : '' }}"><a href="{{ route('admin.reviews') }}"><i class="fa fa-star"></i> Đánh giá</a></li>
                    @if ($adMgr)
                        <li class="{{ $inTours ? 'active' : '' }}">
                            <a><i class="fa fa-suitcase"></i> Tours <span class="fa fa-chevron-down"></span></a>
                            <ul class="nav child_menu" style="{{ $inTours ? 'display:block' : '' }}">
                                <li class="{{ $on('admin.tours', 'admin.tour-edit') ? 'ad-on' : '' }}"><a href="{{ route('admin.tours') }}">Danh sách tour</a></li>
                                <li class="{{ $on('admin.page-add-tours') ? 'ad-on' : '' }}"><a href="{{ route('admin.page-add-tours') }}">Thêm tour</a></li>
                            </ul>
                        </li>
                        <li class="{{ $on('admin.promotion*', 'admin.promotion-edit') ? 'ad-on' : '' }}"><a href="{{ route('admin.promotion') }}"><i class="fa fa-tags"></i> Khuyến mãi</a></li>
                        <li class="{{ $on('admin.users') ? 'ad-on' : '' }}"><a href="{{ route('admin.users') }}"><i class="fa fa-users"></i> Người dùng</a></li>
                        <li class="{{ $on('admin.trips*') ? 'ad-on' : '' }}"><a href="{{ route('admin.trips') }}"><i class="fa fa-road"></i> Chuyến đi (Planner)</a></li>
                    @endif
                </ul>

                @if ($adMgr)
                    <h3>Nội dung</h3>
                    <ul class="nav side-menu">
                        <li class="{{ $inContent ? 'active' : '' }}">
                            <a><i class="fa fa-compass"></i> Khám phá
                                @if ($badge['reviewed'] > 0)<span class="ad-pill" title="Nội dung đã xem, chờ xuất bản">{{ $badge['reviewed'] }}</span>@endif
                                <span class="fa fa-chevron-down"></span></a>
                            <ul class="nav child_menu" style="{{ $inContent ? 'display:block' : '' }}">
                                <li class="{{ $on('admin.content.overview') ? 'ad-on' : '' }}"><a href="{{ route('admin.content.overview') }}">Tổng quan</a></li>
                                <li class="{{ $on('admin.content.quality*') ? 'ad-on' : '' }}"><a href="{{ route('admin.content.quality') }}">Chất lượng nội dung</a></li>
                                <li class="{{ $on('admin.content', 'admin.content.create', 'admin.content.edit', 'admin.content.preview', 'admin.itinerary*') ? 'ad-on' : '' }}"><a href="{{ route('admin.content') }}">Duyệt nội dung</a></li>
                                <li class="{{ $on('admin.destinations*') ? 'ad-on' : '' }}"><a href="{{ route('admin.destinations') }}">Điểm đến</a></li>
                                <li class="{{ $on('admin.inspirations*') ? 'ad-on' : '' }}"><a href="{{ route('admin.inspirations') }}">Cảm hứng</a></li>
                                <li class="{{ $on('admin.guide-categories*') ? 'ad-on' : '' }}"><a href="{{ route('admin.guide-categories') }}">Danh mục cẩm nang</a></li>
                                <li class="{{ $on('admin.blog*') ? 'ad-on' : '' }}"><a href="{{ route('admin.blog') }}">Blog</a></li>
                            </ul>
                        </li>
                    </ul>

                    <h3>Hỗ trợ</h3>
                    <ul class="nav side-menu">
                        <li class="{{ $on('admin.contact*') ? 'ad-on' : '' }}">
                            <a href="{{ route('admin.contact') }}"><i class="fa fa-envelope"></i> Hộp thư liên hệ
                                @if ($badge['contact'] > 0)<span class="ad-pill">{{ $badge['contact'] }}</span>@endif
                            </a>
                        </li>
                        <li class="{{ $on('admin.chat*') ? 'ad-on' : '' }}"><a href="{{ route('admin.chat') }}"><i class="fa fa-comments"></i> Nhật ký hỏi đáp</a></li>
                    </ul>

                    <h3>Hệ thống</h3>
                    <ul class="nav side-menu">
                        <li class="{{ $on('admin.admin') ? 'ad-on' : '' }}"><a href="{{ route('admin.admin') }}"><i class="fa fa-user"></i> Tài khoản quản trị</a></li>
                        <li class="{{ $on('admin.system*') ? 'ad-on' : '' }}"><a href="{{ route('admin.system') }}"><i class="fa fa-heartbeat"></i> Bảo trì hệ thống</a></li>
                    </ul>
                @endif
            </div>
        </div>

        <div class="sidebar-footer hidden-small" style="width:100%!important;position:static!important;float:none!important;padding:0 15px 20px;box-sizing:border-box;margin-top:auto!important;">
            <a href="javascript:void(0)" id="btn-logout-admin" class="btn-logout-custom"
                style="display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:10px 0;border-radius:10px;background:rgba(231,76,60,.1);color:#f87171;font-weight:600;border:1px solid rgba(231,76,60,.3);text-decoration:none">
                <i class="fa fa-sign-out"></i><span>Đăng xuất</span>
            </a>
        </div>
    </div>
</div>

{{-- Thanh trên: nút thu gọn menu (#menu_toggle do custom.js xử lý) + tên trang + người dùng --}}
<div class="top_nav">
    <div class="nav_menu">
        <div class="nav toggle"><a id="menu_toggle" title="Thu gọn / mở menu"><i class="fa fa-bars"></i></a></div>
        <div class="ad-crumb"><small>Quản trị /</small>{{ $title ?? 'Admin' }}</div>
        <div class="ad-top-right">
            <a class="ad-top-link" href="{{ url('/') }}" target="_blank" rel="noopener"><i class="fa fa-external-link"></i><span>Xem website</span></a>
            <div class="ad-user-chip">
                <img src="{{ \App\Support\Avatar::admin() }}" alt="">
                <div><b>{{ session('admin') }}</b><span>{{ $roleLabel }}</span></div>
            </div>
        </div>
    </div>
</div>

<form id="form-logout-admin" action="{{ route('admin.logout') }}" method="POST" style="display:none;">@csrf</form>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    document.getElementById('btn-logout-admin').addEventListener('click', function(e) {
        e.preventDefault();
        Swal.fire({
            title: 'Đăng xuất?',
            text: 'Bạn có chắc chắn muốn đăng xuất khỏi hệ thống quản trị?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Đăng xuất',
            cancelButtonText: 'Hủy',
            confirmButtonColor: '#e74c3c',
            cancelButtonColor: '#95a5a6',
            reverseButtons: true
        }).then(function(result) {
            if (result.isConfirmed) document.getElementById('form-logout-admin').submit();
        });
    });
</script>
