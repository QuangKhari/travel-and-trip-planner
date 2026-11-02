@include('admin.blocks.header')
@php
    $all = collect($tours);
    $today = now()->toDateString();
    $on = $all->where('availability', 1)->count();
@endphp
<div class="container body">
    <div class="main_container">
        @include('admin.blocks.sidebar')

        <div class="right_col" role="main">
            <div class="ad-wrap">
                @include('admin.partials.flash')
                <div class="ad-head">
                    <div>
                        <h1>Tour</h1>
                        <p>Quản lý thông tin, ảnh, lộ trình và trạng thái hiển thị của tour. Mỗi tour có thể mở nhiều ngày khởi hành ở mục “Lịch”. Tour đã có đơn khi xóa sẽ chỉ bị ẩn (ngừng bán) để giữ lịch sử.</p>
                    </div>
                    <div class="ad-actions">
                        <a class="ad-btn" href="{{ route('admin.departures') }}"><i class="fa fa-calendar"></i> Lịch khởi hành</a>
                        <a class="ad-btn ad-btn--primary" href="{{ route('admin.page-add-tours') }}"><i class="fa fa-plus"></i> Thêm tour</a>
                    </div>
                </div>

                <div class="ad-card">
                    <div class="ad-toolbar">
                        <div class="ad-chips">
                            <button type="button" class="ad-chip is-on" data-ad-status-chip="" data-target="datatable-listTours">Tất cả <b>{{ $all->count() }}</b></button>
                            <button type="button" class="ad-chip" data-ad-status-chip="on" data-target="datatable-listTours">Đang hiện <b>{{ $on }}</b></button>
                            <button type="button" class="ad-chip" data-ad-status-chip="off" data-target="datatable-listTours">Đang ẩn <b>{{ $all->count() - $on }}</b></button>
                        </div>
                    </div>
                    <div class="table-responsive ad-tablewrap">
                        <table id="datatable-listTours" class="ad-table">
                            <thead>
                                <tr><th>Tour</th><th>Điểm đến</th><th class="num">Giá NL</th><th>Khởi hành gần nhất</th><th class="num">Đơn mở</th><th>Trạng thái</th><th class="num">Thao tác</th></tr>
                            </thead>
                            <tbody id="tbody-listTours">
                                @include('admin.partials.list-tours')
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <!-- /page content -->
        <!-- Modal Edit Tour-->
        <div class="modal fade" id="edit-tour-modal" tabindex="-1" role="dialog" aria-labelledby="edit-tour-Label"
            aria-hidden="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="edit-tour-Label">Chỉnh sửa Tour</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div id="wizard" class="form_wizard wizard_horizontal wizard-edit-tour">
                            <ul class="wizard_steps">
                                <li>
                                    <a href="#step-1">
                                        <span class="step_no">1</span>
                                        <span class="step_descr">
                                            Bước 1<br />
                                            <small>Thông tin cơ bản</small>
                                        </span>
                                    </a>
                                </li>
                                <li>
                                    <a href="#step-2">
                                        <span class="step_no">2</span>
                                        <span class="step_descr">
                                            Bước 2<br />
                                            <small>Hình ảnh</small>
                                        </span>
                                    </a>
                                </li>
                                <li>
                                    <a href="#step-3">
                                        <span class="step_no">3</span>
                                        <span class="step_descr">
                                            Bước 3<br />
                                            <small>Lộ trình</small>
                                        </span>
                                    </a>
                                </li>
                            </ul>
                            <div id="step-1">
                                <form class="form-info-tour" method="POST" id="form-step1">
                                    @csrf
                                    <div class="field item form-group">
                                        <label class="col-form-label col-md-3 col-sm-3  label-align">Tên
                                            <span>*</span></label>
                                        <div class="col-md-6 col-sm-6">
                                            <input class="form-control" name="name" placeholder="Nhập tên Tour"
                                                required>
                                        </div>
                                    </div>
                                    <div class="field item form-group">
                                        <label class="col-form-label col-md-3 col-sm-3  label-align">Điểm đến
                                            <span>*</span></label>
                                        <div class="col-md-6 col-sm-6">
                                            <input class="form-control" name="destination" placeholder="Điểm đến"
                                                required>
                                        </div>
                                    </div>
                                    <div class="field item form-group">
                                        <label class="col-form-label col-md-3 col-sm-3  label-align">Gắn với điểm
                                            đến</label>
                                        <div class="col-md-6 col-sm-6">
                                            <select class="form-control" name="destinationId" id="destinationId">
                                                <option value="">-- Chưa gắn --</option>
                                                @foreach ($destinations as $d)
                                                    <option value="{{ $d->destinationId }}"
                                                        data-name="{{ $d->name }}"
                                                        data-domain="{{ $d->domain }}">{{ $d->name }}</option>
                                                @endforeach
                                            </select>
                                            <small class="text-muted">Dùng cho lọc theo cảm hứng, bản đồ và gợi ý tour
                                                trong Trip Planner.</small>
                                        </div>
                                    </div>
                                    <div class="field item form-group">
                                        <label class="col-form-label col-md-3 col-sm-3  label-align">Link video</label>
                                        <div class="col-md-6 col-sm-6">
                                            <input class="form-control" type="url" name="video_url" maxlength="255"
                                                placeholder="https://www.youtube.com/watch?v=...">
                                            <small class="form-text text-muted">YouTube, Vimeo hoặc file .mp4. Để trống
                                                nếu chưa có.</small>
                                        </div>
                                    </div>
                                    <div class="field item form-group">
                                        <label class="col-form-label col-md-3 col-sm-3  label-align">Giá tour bao
                                            gồm</label>
                                        <div class="col-md-6 col-sm-6">
                                            <textarea class="form-control" name="includes" rows="4" maxlength="3000"
                                                placeholder="Mỗi dòng một mục, ví dụ:&#10;Xe đưa đón&#10;Khách sạn 3 sao"></textarea>
                                        </div>
                                    </div>
                                    <div class="field item form-group">
                                        <label class="col-form-label col-md-3 col-sm-3  label-align">Không bao
                                            gồm</label>
                                        <div class="col-md-6 col-sm-6">
                                            <textarea class="form-control" name="excludes" rows="3" maxlength="3000"
                                                placeholder="Mỗi dòng một mục, ví dụ:&#10;Chi phí cá nhân&#10;Vé tham quan ngoài chương trình"></textarea>
                                        </div>
                                    </div>

                                    <div class="field item form-group">
                                        <label class="col-form-label col-md-3 col-sm-3  label-align">Khu
                                            vực<span>*</span></label>
                                        <div class="col-md-6 col-sm-6">
                                            <select class="form-control" name="domain" id="domain">
                                                <option value="">Chọn khu vực</option>
                                                <option value="b">Miền Bắc</option>
                                                <option value="t">Miền Trung</option>
                                                <option value="n">Miền Nam</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="field item form-group">
                                        <label class="col-form-label col-md-3 col-sm-3  label-align">Số chỗ còn lại
                                            <span>*</span></label>
                                        <div class="col-md-6 col-sm-6">
                                            <input class="form-control" type="number" name="number" required>
                                        </div>
                                    </div>
                                    <div class="field item form-group">
                                        <label class="col-form-label col-md-3 col-sm-3  label-align">Giá người
                                            lớn
                                            <span>*</span></label>
                                        <div class="col-md-6 col-sm-6">
                                            <input class="form-control" type="number" name="price_adult" required>
                                        </div>
                                    </div>
                                    <div class="field item form-group">
                                        <label class="col-form-label col-md-3 col-sm-3  label-align">Giá trẻ em
                                            <span>*</span></label>
                                        <div class="col-md-6 col-sm-6">
                                            <input class="form-control" type="number" name="price_child" required>
                                        </div>
                                    </div>
                                    <div class="field item form-group">
                                        <label class="col-form-label col-md-3 col-sm-3  label-align">Ngày khởi
                                            hành<span>*</span></label>
                                        <div class="col-md-6 col-sm-6">
                                            <input type="text" class="form-control datetimepicker" id="start_date"
                                                name="start_date" disabled>
                                        </div>
                                    </div>
                                    <div class="field item form-group">
                                        <label class="col-form-label col-md-3 col-sm-3  label-align">Ngày kết
                                            thúc<span>*</span></label>
                                        <div class="col-md-6 col-sm-6">
                                            <input type="text" class="form-control datetimepicker" id="end_date"
                                                name="end_date" disabled>
                                        </div>
                                    </div>

                                    <div class="field item form-group bad">
                                        <label class="col-form-label col-md-3 col-sm-3  label-align">Mô
                                            tả<span>*</span></label>
                                        <div class="col-md-6 col-sm-6">
                                            <textarea name="description" id="description" rows="10" required></textarea>
                                        </div>
                                    </div>
                                </form>

                            </div>
                            <div id="step-2">
                                <h2 class="StepTitle">Thêm hình ảnh</h2>
                                <form action="{{ route('admin.upload-tour-image') }}" class="dropzone dz-clickable"
                                    id="myDropzone-listTour" enctype="multipart/form-data">
                                    @csrf
                                    <div class="dz-default dz-message">
                                        <span>Chọn hình ảnh về tours để upload</span>
                                    </div>
                                </form>
                            </div>
                            <form action="{{ route('admin.edit-tour') }}" id="timeline-form" method="POST">
                                @csrf
                                <input type="hidden" name="tourId" class="hiddenTourId">
                                <div id="step-3">
                                    <h2 class="StepTitle">Nhập lộ trình</h2>

                                </div>

                            </form>

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@include('admin.blocks.footer')
