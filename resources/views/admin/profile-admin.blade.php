@include('admin.blocks.header')
<div class="container body">
    <div class="main_container">
        @include('admin.blocks.sidebar')

        <!-- page content -->
        <div class="right_col" role="main">
            <div class="">
                <div class="page-title">
                    <div class="title_left">
                        <h3>Quản lý tài khoản</h3>
                    </div>
                </div>

                <div class="clearfix"></div>

                {{-- Thông tin Admin --}}
                <div class="row">
                    <div class="col-md-12">
                        <div class="x_panel">
                            <div class="x_title">
                                <h2>Thông tin Admin</h2>
                                <div class="clearfix"></div>
                            </div>
                            <div class="x_content">
                                <div class="col-md-3 col-sm-3 profile_left">
                                    <div class="profile_img">
                                        <div id="crop-avatar">
                                            <img id="avatarAdminPreview" class="img-responsive avatar-view"
                                                src="{{ asset('admin/assets/images/user-profile/avt_admin.jpg') }}"
                                                alt="Avatar" style="width:100%">
                                            <input type="file" name="avatarAdmin" id="avatarAdmin"
                                                style="display: none" accept="image/*">
                                        </div>
                                    </div>
                                    <br>
                                    <label for="avatarAdmin" id="btn_avatar" class="btn btn-success"
                                        style="align-items: center; text-align: center; width: 78%; margin: 10px 24px;"
                                        action="{{ route('admin.update-avatar') }}">
                                        <i class="fa fa-edit m-right-xs"></i>Tải ảnh lên
                                    </label>
                                    <h3 id="nameAdmin">{{ $admin->fullName }}</h3>
                                    <ul class="list-unstyled user_data">
                                        <li><i class="fa fa-map-marker user-profile-icon"></i>
                                            <span id="emailAdmin">{{ $admin->address }}</span>
                                        </li>
                                        <li><i class="fa fa-briefcase user-profile-icon"></i>
                                            <span id="addressAdmin">{{ $admin->email }}</span>
                                        </li>
                                    </ul>
                                </div>
                                <div class="col-md-9 col-sm-9">
                                    <form action="{{ route('admin.update-admin') }}" id="formProfileAdmin"
                                        class="form-horizontal form-label-left">
                                        @csrf
                                        <div class="item form-group">
                                            <label class="col-form-label col-md-3 col-sm-3 label-align">Tên admin <span class="required">*</span></label>
                                            <div class="col-md-6 col-sm-6">
                                                <input type="text" id="fullName" name="fullName" required
                                                    class="form-control" placeholder="Nhập tên admin"
                                                    value="{{ $admin->fullName }}">
                                            </div>
                                        </div>
                                        <div class="item form-group">
                                            <label class="col-form-label col-md-3 col-sm-3 label-align">Mật khẩu <span class="required">*</span></label>
                                            <div class="col-md-6 col-sm-6">
                                                <input type="password" id="password" name="password" required
                                                    class="form-control" placeholder="Nhập mật khẩu"
                                                    value="{{ $admin->passWord }}">
                                            </div>
                                        </div>
                                        <div class="item form-group">
                                            <label class="col-form-label col-md-3 col-sm-3 label-align">Email</label>
                                            <div class="col-md-6 col-sm-6">
                                                <input class="form-control" type="email" name="email" required
                                                    placeholder="Nhập email" value="{{ $admin->email }}">
                                            </div>
                                        </div>
                                        <div class="item form-group">
                                            <label class="col-form-label col-md-3 col-sm-3 label-align">Địa chỉ</label>
                                            <div class="col-md-6 col-sm-6">
                                                <input class="form-control" type="text" name="address" required
                                                    placeholder="Nhập địa chỉ" value="{{ $admin->address }}">
                                            </div>
                                        </div>
                                        <div class="ln_solid"></div>
                                        <div class="item form-group">
                                            <div class="col-md-6 col-sm-6 offset-md-3">
                                                <button type="submit" class="btn btn-success">Cập nhật</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Danh sách Quản lý --}}
                <div class="row">
                    <div class="col-md-12">
                        <div class="x_panel">
                            <div class="x_title">
                                <h2>Danh sách Quản lý</h2>
                                <div class="clearfix"></div>
                            </div>
                            <div class="x_content">
                                {{-- Form thêm manager --}}
                                <form action="{{ route('admin.add-manager') }}" method="POST"
                                    class="form-horizontal mb-20">
                                    @csrf
                                    <h4>Thêm Quản lý mới</h4>
                                    <div class="row" style="margin-top: 10px">
                                        <div class="col-md-4">
                                            <input type="text" name="userName" class="form-control mb-10"
                                                placeholder="Tên đăng nhập" required>
                                        </div>
                                        <div class="col-md-4">
                                            <input type="password" name="password" class="form-control mb-10"
                                                placeholder="Mật khẩu" required>
                                        </div>
                                        <div class="col-md-4">
                                            <input type="email" name="email" class="form-control mb-10"
                                                placeholder="Email" required>
                                        </div>
                                        <div class="col-md-4" style="margin-top: 10px">
                                            <input type="text" name="fullName" class="form-control mb-10"
                                                placeholder="Họ và tên" required>
                                        </div>
                                        <div class="col-md-4" style="margin-top: 10px">
                                            <input type="text" name="address" class="form-control mb-10"
                                                placeholder="Địa chỉ" required>
                                        </div>
                                        <div class="col-md-4" style="margin-top: 10px">
                                            <button type="submit" class="btn btn-success">
                                                <i class="fa fa-plus"></i> Thêm Quản lý
                                            </button>
                                        </div>
                                    </div>
                                </form>

                                {{-- Bảng danh sách manager --}}
                                <table class="table table-striped table-bordered mt-20">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Tên đăng nhập</th>
                                            <th>Họ và tên</th>
                                            <th>Email</th>
                                            <th>Địa chỉ</th>
                                            <th>Hành động</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($managers as $index => $manager)
                                            <tr id="manager-row-{{ $manager->adminId }}">
                                                <td>{{ $index + 1 }}</td>
                                                <td>{{ $manager->username }}</td>
                                                <td>{{ $manager->fullName }}</td>
                                                <td>{{ $manager->email }}</td>
                                                <td>{{ $manager->address }}</td>
                                                <td>
                                                    <button class="btn btn-danger btn-delete"
                                                        data-id="{{ $manager->adminId }}"
                                                        data-role="manager"
                                                        data-url="{{ route('admin.delete-manager') }}">
                                                        <i class="fa fa-trash"></i> Xóa
                                                    </button>
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="6" class="text-center">Chưa có quản lý nào</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Danh sách Nhân viên --}}
                <div class="row">
                    <div class="col-md-12">
                        <div class="x_panel">
                            <div class="x_title">
                                <h2>Danh sách Nhân viên</h2>
                                <div class="clearfix"></div>
                            </div>
                            <div class="x_content">
                                {{-- Form thêm staff --}}
                                <form action="{{ route('admin.add-staff') }}" method="POST"
                                    class="form-horizontal mb-20">
                                    @csrf
                                    <h4>Thêm Nhân viên mới</h4>
                                    <div class="row" style="margin-top: 10px">
                                        <div class="col-md-4">
                                            <input type="text" name="userName" class="form-control mb-10"
                                                placeholder="Tên đăng nhập" required>
                                        </div>
                                        <div class="col-md-4">
                                            <input type="password" name="password" class="form-control mb-10"
                                                placeholder="Mật khẩu" required>
                                        </div>
                                        <div class="col-md-4">
                                            <input type="email" name="email" class="form-control mb-10"
                                                placeholder="Email" required>
                                        </div>
                                        <div class="col-md-4" style="margin-top: 10px">
                                            <input type="text" name="fullName" class="form-control mb-10"
                                                placeholder="Họ và tên" required>
                                        </div>
                                        <div class="col-md-4" style="margin-top: 10px">
                                            <input type="text" name="address" class="form-control mb-10"
                                                placeholder="Địa chỉ" required>
                                        </div>
                                        <div class="col-md-4" style="margin-top: 10px">
                                            <button type="submit" class="btn btn-success">
                                                <i class="fa fa-plus"></i> Thêm Nhân viên
                                            </button>
                                        </div>
                                    </div>
                                </form>

                                {{-- Bảng danh sách staff --}}
                                <table class="table table-striped table-bordered mt-20">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Tên đăng nhập</th>
                                            <th>Họ và tên</th>
                                            <th>Email</th>
                                            <th>Địa chỉ</th>
                                            <th>Hành động</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($staffs as $index => $staff)
                                            <tr id="staff-row-{{ $staff->adminId }}">
                                                <td>{{ $index + 1 }}</td>
                                                <td>{{ $staff->username }}</td>
                                                <td>{{ $staff->fullName }}</td>
                                                <td>{{ $staff->email }}</td>
                                                <td>{{ $staff->address }}</td>
                                                <td>
                                                    <button class="btn btn-danger btn-delete"
                                                        data-id="{{ $staff->adminId }}"
                                                        data-role="staff"
                                                        data-url="{{ route('admin.delete-staff') }}">
                                                        <i class="fa fa-trash"></i> Xóa
                                                    </button>
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="6" class="text-center">Chưa có nhân viên nào</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
        <!-- /page content -->
    </div>
</div>

{{-- JS xóa manager và staff --}}
<script>
$('.btn-delete').on('click', function () {
    if (!confirm('Bạn có chắc muốn xóa tài khoản này?')) return;

    const adminId = $(this).data('id');
    const role = $(this).data('role');
    const url = $(this).data('url');
    const row = $(this).closest('tr');

    $.ajax({
        url: url,
        method: 'POST',
        data: {
            _token: '{{ csrf_token() }}',
            adminId: adminId
        },
        success: function (res) {
            if (res.success) {
                row.remove();
                toastr.success(res.message);
            } else {
                toastr.error(res.message);
            }
        },
        error: function () {
            toastr.error('Có lỗi xảy ra, vui lòng thử lại');
        }
    });
});
</script>

@include('admin.blocks.footer')