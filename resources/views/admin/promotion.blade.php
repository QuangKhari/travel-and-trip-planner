@include('admin.blocks.header')

<div class="container body">
    <div class="main_container">

        @include('admin.blocks.sidebar')

        <div class="right_col" role="main">

            <div class="page-title">
                <div class="title_left">
                    <h3>Quản lý <small>Promotion</small></h3>
                </div>
            </div>

            <div class="clearfix"></div>

            {{-- Thông báo --}}
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
                    <ul style="margin-bottom:0;">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="x_panel">

                <div class="x_title">
                    <h2>Danh sách Promotion</h2>

                    <ul class="nav navbar-right panel_toolbox">
                        <button type="button"
                            class="btn btn-success"
                            data-toggle="modal"
                            data-target="#modal-add-promotion">
                            Thêm Promotion
                        </button>
                    </ul>

                    <div class="clearfix"></div>
                </div>

                <div class="x_content">

                    <table id="datatable-promotion"
                        class="table table-striped table-bordered">

                        <thead>
                            <tr>
                                <th>Mã</th>
                                <th>Mô tả</th>
                                <th>Giảm (%)</th>
                                <th>Ngày bắt đầu</th>
                                <th>Ngày kết thúc</th>
                                <th>Số lượng</th>
                                <th>Trạng thái</th>
                                <th>Hành động</th>
                            </tr>
                        </thead>

                        <tbody id="tbody-promotion">
                            @include('admin.partials.list-promotion')
                        </tbody>

                    </table>

                </div>

            </div>

        </div>

    </div>
</div>

<!-- Modal Add Promotion -->
<div class="modal fade"
    id="modal-add-promotion"
    tabindex="-1">

    <div class="modal-dialog">
        <div class="modal-content">

            <form id="form-add-promotion"
                action="{{ route('admin.add-promotion') }}"
                method="POST">

                @csrf

                <div class="modal-header">
                    <h4 class="modal-title">
                        Thêm Promotion
                    </h4>

                    <button type="button"
                        class="close"
                        data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>

                <div class="modal-body">

                    <div class="form-group">
                        <label>Mã Voucher</label>

                        <input type="text"
                            name="code"
                            class="form-control"
                            value="{{ old('code') }}"
                            required>
                    </div>

                    <div class="form-group">
                        <label>Mô tả</label>

                        <textarea
                            name="description"
                            class="form-control"
                            rows="3"
                            required>{{ old('description') }}</textarea>
                    </div>

                    <div class="form-group">
                        <label>Giảm (%)</label>

                        <input type="number"
                            name="discount"
                            min="1"
                            max="100"
                            class="form-control"
                            value="{{ old('discount') }}"
                            required>
                    </div>

                    <div class="form-group">
                        <label>Ngày bắt đầu</label>

                        <input type="date"
                            name="startDate"
                            class="form-control"
                            value="{{ old('startDate') }}"
                            required>
                    </div>

                    <div class="form-group">
                        <label>Ngày kết thúc</label>

                        <input type="date"
                            name="endDate"
                            class="form-control"
                            value="{{ old('endDate') }}"
                            required>
                    </div>

                    <div class="form-group">
                        <label>Số lượng</label>

                        <input type="number"
                            name="quantity"
                            min="1"
                            class="form-control"
                            value="{{ old('quantity') }}"
                            required>
                    </div>

                    <div class="form-group">
                        <label>Trạng thái</label>

                        <select name="status"
                            class="form-control">

                            <option value="y"
                                {{ old('status', 'y') == 'y' ? 'selected' : '' }}>
                                Hoạt động
                            </option>

                            <option value="n"
                                {{ old('status') == 'n' ? 'selected' : '' }}>
                                Ngưng hoạt động
                            </option>

                        </select>
                    </div>

                </div>

                <div class="modal-footer">

                    <button type="submit"
                        class="btn btn-success">
                        Thêm
                    </button>

                    <button type="button"
                        class="btn btn-secondary"
                        data-dismiss="modal">
                        Đóng
                    </button>

                </div>

            </form>

        </div>
    </div>

</div>

@include('admin.blocks.footer')