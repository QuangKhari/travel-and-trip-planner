@include('admin.blocks.header')

<div class="container body">
    <div class="main_container">

        @include('admin.blocks.sidebar')

        <!-- page content -->
        <div class="right_col" role="main">

            <div class="">

                <div class="page-title">
                    <div class="title_left">
                        <h3>
                            Chi tiết <small>Đánh giá</small>
                        </h3>
                    </div>

                    <div class="title_right">
                        <a href="{{ route('admin.reviews') }}"
                           class="btn btn-default pull-right">

                            <i class="fa fa-arrow-left"></i>
                            Quay lại

                        </a>
                    </div>
                </div>

                <div class="clearfix"></div>

                <div class="row">

                    {{-- Nội dung đánh giá --}}
                    <div class="col-md-8 col-sm-8">

                        <div class="x_panel">

                            <div class="x_title">
                                <h2>Thông tin đánh giá</h2>
                                <div class="clearfix"></div>
                            </div>

                            <div class="x_content">

                                <div class="form-group">

                                    <label>Điểm đánh giá</label>

                                    <div style="margin-top:10px">

                                        @for($i = 1; $i <= 5; $i++)

                                            @if($i <= $review->rating)

                                                <i class="fa fa-star"
                                                   style="font-size:24px;color:#f0ad4e"></i>

                                            @else

                                                <i class="fa fa-star-o"
                                                   style="font-size:24px;color:#ccc"></i>

                                            @endif

                                        @endfor

                                        <span style="margin-left:10px;font-size:16px">
                                            {{ $review->rating }}/5
                                        </span>

                                    </div>

                                </div>

                                <hr>

                                <div class="form-group">

                                    <label>Nội dung đánh giá</label>

                                    <div style="
                                        background:#f7f7f7;
                                        padding:15px;
                                        border-left:4px solid #337ab7;
                                        border-radius:4px;
                                        margin-top:10px">

                                        {{ $review->comment }}

                                    </div>

                                </div>

                                <hr>

                                <div class="form-group">

                                    <label>Ngày đánh giá</label>

                                    <p>

                                        <i class="fa fa-calendar"></i>

                                        {{ date('d/m/Y H:i', strtotime($review->timestamp)) }}

                                    </p>

                                </div>

                            </div>

                        </div>

                    </div>

                    {{-- Thông tin bên phải --}}
                    <div class="col-md-4 col-sm-4">

                        {{-- User --}}
                        <div class="x_panel">

                            <div class="x_title">
                                <h2>Khách hàng</h2>
                                <div class="clearfix"></div>
                            </div>

                            <div class="x_content">

                                <p>
                                    <strong>Họ tên:</strong><br>

                                    {{ $review->user->fullName ?? 'N/A' }}
                                </p>

                                @if(isset($review->user->email))
                                    <p>
                                        <strong>Email:</strong><br>
                                        {{ $review->user->email }}
                                    </p>
                                @endif

                                @if(isset($review->user->phone))
                                    <p>
                                        <strong>Số điện thoại:</strong><br>
                                        {{ $review->user->phone }}
                                    </p>
                                @endif

                            </div>

                        </div>

                        {{-- Tour --}}
                        <div class="x_panel">

                            <div class="x_title">
                                <h2>Tour được đánh giá</h2>
                                <div class="clearfix"></div>
                            </div>

                            <div class="x_content">

                                <p>
                                    <strong>Tên tour:</strong><br>

                                    {{ $review->tour->title ?? 'N/A' }}
                                </p>

                                @if(isset($review->tour->price))
                                    <p>
                                        <strong>Giá tour:</strong><br>

                                        {{ number_format($review->tour->price, 0, ',', '.') }}
                                        VNĐ
                                    </p>
                                @endif

                            </div>

                        </div>

                        {{-- Thao tác --}}
                        <div class="x_panel">

                            <div class="x_title">
                                <h2>Thao tác</h2>
                                <div class="clearfix"></div>
                            </div>

                            <div class="x_content">

                                <form action="{{ route('admin.reviews.destroy', $review->reviewId) }}"
                                      method="POST"
                                      onsubmit="return confirm('Bạn có chắc muốn xóa đánh giá này?')">

                                    @csrf
                                    @method('DELETE')

                                    <button type="submit"
                                            class="btn btn-danger btn-block">

                                        <i class="fa fa-trash"></i>
                                        Xóa đánh giá

                                    </button>

                                </form>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>
    </div>
</div>

@include('admin.blocks.footer')