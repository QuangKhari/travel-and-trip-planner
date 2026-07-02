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

                    {{-- Admin và manager --}}
                    @if (in_array(session('adminRole'), ['admin', 'manager']))
                        <li><a href="{{ route('admin.admin') }}"><i class="fa fa-user"></i> Quản lý tài khoản</a></li>
                    @endif

                </ul>
            </div>
        </div>
        <!-- /sidebar menu -->

        <!-- menu footer buttons -->
        <div class="sidebar-footer hidden-small" style="width: 100% !important; position: static !important; left: auto !important; float: none !important; padding: 0 15px 20px 15px; box-sizing: border-box; margin-top: auto !important;">
            <a href="javascript:void(0)" id="btn-logout-admin" class="btn-logout-custom">
                <i class="fa fa-sign-out"></i>
                <span>Đăng xuất</span>
            </a>
        </div>
        <!-- /menu footer buttons -->

        <style>
            .left_col.scroll-view {
                display: flex !important;
                flex-direction: column !important;
                min-height: 100vh !important;
            }

            #sidebar-menu {
                flex: 1 !important;
            }

            .btn-logout-custom {
                display: flex !important;
                flex-direction: row !important;
                align-items: center;
                justify-content: center;
                gap: 8px;
                width: 100%;
                white-space: nowrap;
                padding: 10px 0;
                border-radius: 6px;
                background-color: rgba(231, 76, 60, 0.1);
                color: #e74c3c;
                font-size: 14px;
                font-weight: 600;
                text-decoration: none;
                border: 1px solid rgba(231, 76, 60, 0.3);
                transition: all 0.25s ease;
                box-sizing: border-box;
            }

            .btn-logout-custom:hover {
                background-color: #e74c3c;
                color: #ffffff;
                border-color: #e74c3c;
                text-decoration: none;
            }

            .btn-logout-custom i {
                font-size: 15px;
            }
        </style>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    document.getElementById('btn-logout-admin').addEventListener('click', function (e) {
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
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = "{{ route('admin.logout') }}";
            }
        });
    });
</script>