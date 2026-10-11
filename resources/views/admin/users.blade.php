@include('admin.blocks.header')
<div class="container body">
    <div class="main_container">
        @include('admin.blocks.sidebar')

        <!-- page content -->
        <div class="right_col" role="main">
            <div class="">
                <div class="page-title">
                    <div class="title_left">
                        <h3>Quản lý người dùng</h3>
                    </div>

                    <div class="title_right">
                        <form method="GET" action="{{ route('admin.users') }}"
                            class="col-md-5 col-sm-5  form-group pull-right top_search">
                            <div class="input-group">
                                <input type="text" name="q" value="{{ $q ?? '' }}" class="form-control"
                                    placeholder="Tìm theo tên, email, số điện thoại...">
                                <span class="input-group-btn">
                                    <button class="btn btn-default" type="submit">Tìm</button>
                                </span>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="clearfix"></div>

                <div class="x_panel">
                    <div class="x_content row">
                        @foreach ($users as $user)
                            <div class="col-md-4 col-sm-4  profile_details">
                                <div class="well profile_view">
                                    <div class="col-sm-12">
                                        <h4 class="brief"><i>{{ $user->isActive }}</i></h4>
                                        <div class="left col-md-7 col-sm-7">
                                            <h2>{{ $user->fullName }}</h2>
                                            <p><strong>About: </strong> {{ $user->username }} </p>
                                            <ul class="list-unstyled">
                                                <li><i class="fa fa-building"></i> Address: {{ $user->address }}</li>
                                                <li><i class="fa fa-phone"></i> Phone #: {{ $user->phoneNumber }}</li>
                                            </ul>
                                        </div>
                                        <div class="right col-md-5 col-sm-5 text-center">
                                            <img src="{{ \App\Support\Avatar::url($user->avatar) }}" alt=""
                                                class="img-circle img-fluid">
                                        </div>
                                    </div>
                                    <div class=" profile-bottom text-center">
                                        <div class=" col-sm-12 emphasis" style="display: flex; justify-content: end">
                                            @if ($user->isActive == 'Chưa kích hoạt')
                                                <button type="button" class="btn btn-primary btn-sm btn-active"
                                                    data-attr='{"userId": "{{ $user->userId }}", "action": "{{ route('admin.active-user') }}"}'>
                                                    <i class="fa fa-check"> </i> Kích hoạt
                                                </button>
                                            @endif

                                            <button type="button" class="btn btn-primary btn-warning btn-ban"
                                                data-attr='{"userId": "{{ $user->userId }}", "action": "{{ route('admin.status-user') }}", "status": "b"}'
                                                style="{{ $user->status === 'b' ? 'display: none;' : '' }}">
                                                <i class="fa fa-ban"> </i> Chặn
                                            </button>

                                            <button type="button" class="btn btn-primary btn-warning btn-unban"
                                                data-attr='{"userId": "{{ $user->userId }}", "action": "{{ route('admin.status-user') }}", "status": "active"}'
                                                style="{{ $user->status !== 'b' ? 'display: none;' : '' }}">
                                                <i class="fa fa-ban"> </i> Bỏ chặn
                                            </button>

                                            <button type="button" class="btn btn-primary btn-danger btn-delete"
                                                data-attr='{"userId": "{{ $user->userId }}", "action": "{{ route('admin.status-user') }}", "status": "d"}'
                                                style="{{ $user->status === 'd' ? 'display: none;' : '' }}">
                                                <i class="fa fa-close"> </i> Xóa
                                            </button>

                                            <button type="button" class="btn btn-primary btn-danger btn-restore"
                                                data-attr='{"userId": "{{ $user->userId }}", "action": "{{ route('admin.status-user') }}", "status": "active"}'
                                                style="{{ $user->status !== 'd' ? 'display: none;' : '' }}">
                                                <i class="fa fa-close"> </i> Khôi phục
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach

                        @if ($users->count() === 0)
                            <p class="text-center text-muted" style="padding:30px 0">Không tìm thấy người dùng
                                nào{{ !empty($q) ? ' cho “' . $q . '”' : '' }}.</p>
                        @endif
                    </div>

                    @if ($users->lastPage() > 1)
                        <div class="tv-admin-pager tv-admin-pager--static">
                            <span class="tv-admin-pager__info">Trang {{ $users->currentPage() }} /
                                {{ $users->lastPage() }} · {{ $users->total() }} người dùng</span>
                            <div>
                                @if ($users->currentPage() > 1)
                                    <a href="{{ $users->previousPageUrl() }}">‹</a>
                                @endif
                                @for ($p = max(1, $users->currentPage() - 2); $p <= min($users->lastPage(), $users->currentPage() + 2); $p++)
                                    <a href="{{ $users->url($p) }}"
                                        class="{{ $p === $users->currentPage() ? 'is-active' : '' }}">{{ $p }}</a>
                                @endfor
                                @if ($users->hasMorePages())
                                    <a href="{{ $users->nextPageUrl() }}">›</a>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
        <!-- /page content -->
    </div>
</div>
@include('admin.blocks.footer')
