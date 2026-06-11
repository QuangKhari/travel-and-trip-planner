@include('admin.blocks.header')

<div class="container body">
    <div class="main_container">

        @include('admin.blocks.sidebar')

        <!-- page content -->
        <div class="right_col" role="main">

            <div class="">

                <div class="page-title">
                    <div class="title_left">
                        <h3>Quản lý <small>Đánh giá</small></h3>
                    </div>
                </div>

                <div class="clearfix"></div>

                {{-- Thống kê --}}
                <div class="row">

                    <div class="col-md-6">
                        <div class="x_panel">
                            <div class="x_content text-center">
                                <h2>{{ $stats['total'] }}</h2>
                                <p>Tổng số đánh giá</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="x_panel">
                            <div class="x_content text-center">
                                <h2>{{ $stats['avg_rating'] }}</h2>
                                <p>Điểm đánh giá trung bình</p>
                            </div>
                        </div>
                    </div>

                </div>

                <div class="row">

                    <div class="col-md-12 col-sm-12">

                        <div class="x_panel">

                            <div class="x_title">
                                <h2>Danh sách đánh giá</h2>

                                <ul class="nav navbar-right panel_toolbox">
                                    <li>
                                        <a class="collapse-link">
                                            <i class="fa fa-chevron-up"></i>
                                        </a>
                                    </li>
                                </ul>

                                <div class="clearfix"></div>
                            </div>

                            <div class="x_content">

                                @if(session('success'))
                                    <div class="alert alert-success">
                                        {{ session('success') }}
                                    </div>
                                @endif

                                <form method="GET"
                                      action="{{ route('admin.reviews') }}"
                                      style="margin-bottom:20px">

                                    <div class="row">

                                        <div class="col-md-4">

                                            <input type="text"
                                                   name="search"
                                                   class="form-control"
                                                   placeholder="Tìm kiếm đánh giá..."
                                                   value="{{ $search }}">

                                        </div>

                                        <div class="col-md-2">

                                            <button class="btn btn-primary">
                                                <i class="fa fa-search"></i>
                                                Tìm kiếm
                                            </button>

                                        </div>

                                    </div>

                                </form>

                                <div class="table-responsive">

                                    <table class="table table-striped table-bordered">

                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Khách hàng</th>
                                                <th>Tour</th>
                                                <th>Rating</th>
                                                <th>Bình luận</th>
                                                <th>Ngày đánh giá</th>
                                                <th width="120">Hành động</th>
                                            </tr>
                                        </thead>

                                        <tbody>

                                        @forelse($reviews as $review)

                                            <tr>

                                                <td>
                                                    {{ $review->reviewId }}
                                                </td>

                                                <td>
                                                    {{ $review->user->fullName ?? 'N/A' }}
                                                </td>

                                                <td>
                                                    {{ $review->tour->title ?? 'N/A' }}
                                                </td>

                                                <td>

                                                    @for($i = 1; $i <= 5; $i++)

                                                        @if($i <= $review->rating)
                                                            <i class="fa fa-star text-warning"></i>
                                                        @else
                                                            <i class="fa fa-star-o"></i>
                                                        @endif

                                                    @endfor

                                                    ({{ $review->rating }})

                                                </td>

                                                <td>

                                                    {{ Str::limit($review->comment, 60) }}

                                                </td>

                                                <td>

                                                    {{ date('d/m/Y H:i', strtotime($review->timestamp)) }}

                                                </td>

                                                <td class="text-center">

                                                    <div style="display:flex;gap:5px;justify-content:center;align-items:center;">

                                                        <a href="{{ route('admin.reviews.show', $review->reviewId) }}"
                                                            class="btn btn-info btn-xs">
                                                            <i class="fa fa-eye"></i>
                                                        </a>

                                                        <form action="{{ route('admin.reviews.destroy', $review->reviewId) }}"
                                                                method="POST"
                                                                style="margin:0">

                                                                @csrf
                                                                @method('DELETE')

                                                            <button type="submit"
                                                                class="btn btn-danger btn-xs"
                                                                onclick="return confirm('Xóa đánh giá này?')">
                                                                <i class="fa fa-trash"></i>
                                                            </button>

                                                        </form>

                                                    </div>

                                                </td>

                                            </tr>

                                        @empty

                                            <tr>
                                                <td colspan="7" class="text-center">
                                                    Chưa có đánh giá nào
                                                </td>
                                            </tr>

                                        @endforelse

                                        </tbody>

                                    </table>

                                </div>

                                <div class="text-center">
                                    {{ $reviews->links() }}
                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>
    </div>
</div>

@include('admin.blocks.footer')