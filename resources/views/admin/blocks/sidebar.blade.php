<div class="col-md-3 left_col">
    <div class="left_col scroll-view">
        <div class="navbar nav_title" style="border: 0;">
            <a href="{{ route('admin.dashboard') }}" class="site_title"><i class="fa fa-paw"></i> <span>Travela</span></a>
        </div>

        <div class="clearfix"></div>

        <!-- menu profile quick info -->
        <div class="profile clearfix">
            <div class="profile_pic">
                <img src="{{ asset('admin/assets/images/user-profile/avt_admin.jpg') }}" alt="..."
                    class="img-circle profile_img">
            </div>
            <div class="profile_info">
                <span>Xin chào,</span>
                <h2>{{ session('admin') }}</h2>
                <small>
                    @if (session('adminRole') == 'admin')
                        <span class="badge badge-danger">Admin</span>
                    @elseif (session('adminRole') == 'manager')
                        <span class="badge badge-warning">Quản lý</span>
                    @else
                        <span class="badge badge-info">Nhân viên</span>
                    @endif
                </small>
            </div>
        </div>
        <!-- /menu profile quick info -->

        <br />

        <!-- sidebar menu -->
        <div id="sidebar-menu" class="main_menu_side hidden-print main_menu">
            <div class="menu_section">
                <h3>Tổng quan</h3>
                <ul class="nav side-menu">

                    {{-- Tất cả role --}}
                    <li><a href="{{ route('admin.dashboard') }}"><i class="fa fa-dashboard"></i> Dashboard</a></li>
                    <li><a href="{{ route('admin.booking') }}"><i class="fa fa-home"></i> Quản lý Booking</a></li>
                    <li><a href="{{ route('admin.reviews') }}"><i class="fa fa-star"></i> Quản lý Đánh giá</a></li>

                    {{-- Chỉ admin và manager --}}
                    @if (in_array(session('adminRole'), ['admin', 'manager']))
                        <li><a href="{{ route('admin.users') }}"><i class="fa fa-table"></i> Quản lý người dùng</a></li>
                        <li>
                            <a><i class="fa fa-table"></i> Quản lý Tours<span class="fa fa-chevron-down"></span></a>
                            <ul class="nav child_menu">
                                <li><a href="{{ route('admin.page-add-tours') }}">Thêm Tours</a></li>
                                <li><a href="{{ route('admin.tours') }}">Danh sách Tours</a></li>
                            </ul>
                        </li>
                        <li><a href="{{ route('admin.promotion') }}"><i class="fa fa-tags"></i> Quản lý Khuyến mãi</a></li>
                    @endif

                    {{-- Chỉ admin --}}
                    @if (session('adminRole') == 'admin')
                        <li><a href="{{ route('admin.admin') }}"><i class="fa fa-user"></i> Quản lý tài khoản</a></li>
                    @endif

                </ul>
            </div>
        </div>
        <!-- /sidebar menu -->

        <!-- menu footer buttons -->
        <div class="sidebar-footer hidden-small">
            <a data-toggle="tooltip" data-placement="top" title="Settings">
                <span class="glyphicon glyphicon-cog" aria-hidden="true"></span>
            </a>
            <a data-toggle="tooltip" data-placement="top" title="FullScreen">
                <span class="glyphicon glyphicon-fullscreen" aria-hidden="true"></span>
            </a>
            <a data-toggle="tooltip" data-placement="top" title="Lock">
                <span class="glyphicon glyphicon-eye-close" aria-hidden="true"></span>
            </a>
            <a data-toggle="tooltip" data-placement="top" title="Logout" href="{{ route('admin.logout') }}">
                <span class="glyphicon glyphicon-off" aria-hidden="true"></span>
            </a>
        </div>
        <!-- /menu footer buttons -->
    </div>
</div>