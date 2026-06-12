@include('admin.blocks.header')

<div class="container body">
    <div class="main_container">

        @include('admin.blocks.sidebar')

        <div class="right_col" role="main">

            <div class="page-title">
                <div class="title_left">
                    <h3>Sửa Promotion</h3>
                </div>
            </div>

            <div class="clearfix"></div>

            @if(session('success'))
                <div class="alert alert-success">
                    {{ session('success') }}
                </div>
            @endif

            @if(session('error'))
                <div class="alert alert-danger">
                    {{ session('error') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="alert alert-danger">
                    <ul>
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="x_panel">

                <div class="x_title">
                    <h2>Cập nhật Promotion</h2>
                    <div class="clearfix"></div>
                </div>

                <div class="x_content">

                    <form action="{{ route('admin.edit-promotion') }}"
                          method="POST">

                        @csrf

                        <input type="hidden"
                               name="promotionId"
                               value="{{ $promotion->promotionId }}">

                        <div class="form-group">
                            <label>Mã Voucher</label>
                            <input type="text"
                                   name="code"
                                   class="form-control"
                                   value="{{ old('code', $promotion->code) }}"
                                   required>
                        </div>

                        <div class="form-group">
                            <label>Mô tả</label>
                            <textarea name="description"
                                      class="form-control"
                                      rows="4"
                                      required>{{ old('description', $promotion->description) }}</textarea>
                        </div>

                        <div class="form-group">
                            <label>Giảm (%)</label>
                            <input type="number"
                                   name="discount"
                                   min="1"
                                   max="100"
                                   class="form-control"
                                   value="{{ old('discount', $promotion->discount) }}"
                                   required>
                        </div>

                        <div class="form-group">
                            <label>Ngày bắt đầu</label>
                            <input type="date"
                                   name="startDate"
                                   class="form-control"
                                   value="{{ old('startDate', $promotion->startDate) }}"
                                   required>
                        </div>

                        <div class="form-group">
                            <label>Ngày kết thúc</label>
                            <input type="date"
                                   name="endDate"
                                   class="form-control"
                                   value="{{ old('endDate', $promotion->endDate) }}"
                                   required>
                        </div>

                        <div class="form-group">
                            <label>Số lượng</label>
                            <input type="number"
                                   name="quantity"
                                   min="1"
                                   class="form-control"
                                   value="{{ old('quantity', $promotion->quantity) }}"
                                   required>
                        </div>

                        <div class="form-group">
                            <label>Trạng thái</label>

                            <select name="status"
                                    class="form-control">

                                <option value="y"
                                    {{ $promotion->status == 'y' ? 'selected' : '' }}>
                                    Hoạt động
                                </option>

                                <option value="n"
                                    {{ $promotion->status == 'n' ? 'selected' : '' }}>
                                    Ngừng hoạt động
                                </option>

                            </select>
                        </div>

                        <button type="submit"
                                class="btn btn-success">
                            Cập nhật
                        </button>

                        <a href="{{ url('/admin/promotion') }}"
                           class="btn btn-secondary">
                            Quay lại
                        </a>

                    </form>

                </div>

            </div>

        </div>

    </div>
</div>

@include('admin.blocks.footer')