@include('admin.blocks.header')
<div class="container body">
    <div class="main_container">
        @include('admin.blocks.sidebar')

        <!-- page content -->
        <div class="right_col" role="main">
            <div class="ad-wrap">
                @include('admin.partials.flash')
                <div class="ad-head">
                    <div>
                        <a class="ad-back" href="{{ route('admin.tours') }}"><i class="fa fa-arrow-left"></i> Danh sách tour</a>
                        <h1>Thêm tour mới</h1>
                        <p>Ba bước: thông tin cơ bản (tạo bản nháp ngay), ít nhất 5 ảnh, lộ trình từng ngày. Tour chỉ hiện cho khách khi đủ cả ba; sau đó bạn có thể mở thêm các ngày khởi hành khác.</p>
                    </div>
                </div>
                <div>
                    <div>
                        <div class="x_panel">
                            <div class="x_content add-tours">

                                <!-- Smart Wizard -->
                                <p>Thêm thông tin chi tiết để tạo một tour mới và bắt đầu thu hút khách hàng!</p>
                                <div id="wizard" class="form_wizard wizard_horizontal">
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
                                        <form class="form-info-tour" action="{{ route('admin.add-tours') }}"
                                            data-update-url="{{ route('admin.update-basic-tour') }}" method="POST"
                                            id="form-step1">
                                            @csrf
                                            <div class="field item form-group">
                                                <label class="col-form-label col-md-3 col-sm-3  label-align">Tên
                                                    <span>*</span></label>
                                                <div class="col-md-6 col-sm-6">
                                                    <input class="form-control" name="name"
                                                        placeholder="Nhập tên Tour" required>
                                                </div>
                                            </div>
                                            <div class="field item form-group">
                                                <label class="col-form-label col-md-3 col-sm-3  label-align">Điểm đến
                                                    <span>*</span></label>
                                                <div class="col-md-6 col-sm-6">
                                                    <input class="form-control" name="destination"
                                                        placeholder="Điểm đến" required>
                                                </div>
                                            </div>
                                            <div class="field item form-group">
                                                <label class="col-form-label col-md-3 col-sm-3  label-align">Gắn với
                                                    điểm đến <span>*</span></label>
                                                <div class="col-md-6 col-sm-6">
                                                    <select class="form-control" name="destinationId" id="destinationId"
                                                        required>
                                                        <option value="">-- Chọn điểm đến --</option>
                                                        @foreach ($destinations as $d)
                                                            <option value="{{ $d->destinationId }}"
                                                                data-name="{{ $d->name }}"
                                                                data-domain="{{ $d->domain }}">{{ $d->name }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                    <small class="text-muted">Dùng cho lọc theo cảm hứng, bản đồ và gợi
                                                        ý tour trong Trip Planner.</small>
                                                </div>
                                            </div>
                                            <div class="field item form-group">
                                                <label class="col-form-label col-md-3 col-sm-3  label-align">Link
                                                    video</label>
                                                <div class="col-md-6 col-sm-6">
                                                    <input class="form-control" type="url" name="video_url"
                                                        maxlength="255"
                                                        placeholder="https://www.youtube.com/watch?v=...">
                                                    <small class="form-text text-muted">YouTube, Vimeo hoặc file .mp4.
                                                        Để trống nếu chưa có.</small>
                                                </div>
                                            </div>
                                            <div class="field item form-group">
                                                <label class="col-form-label col-md-3 col-sm-3  label-align">Giá tour
                                                    bao gồm</label>
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
                                                <label class="col-form-label col-md-3 col-sm-3  label-align">Số chỗ (ngày đầu)
                                                    <span>*</span></label>
                                                <div class="col-md-6 col-sm-6">
                                                    <input class="form-control" type="number" name="number"
                                                        required>
                                                </div>
                                            </div>
                                            <div class="field item form-group">
                                                <label class="col-form-label col-md-3 col-sm-3  label-align">Giá người
                                                    lớn
                                                    <span>*</span></label>
                                                <div class="col-md-6 col-sm-6">
                                                    <input class="form-control" type="number" name="price_adult"
                                                        required>
                                                </div>
                                            </div>
                                            <div class="field item form-group">
                                                <label class="col-form-label col-md-3 col-sm-3  label-align">Giá trẻ em
                                                    <span>*</span></label>
                                                <div class="col-md-6 col-sm-6">
                                                    <input class="form-control" type="number" name="price_child"
                                                        required>
                                                </div>
                                            </div>
                                            <div class="field item form-group">
                                                <label class="col-form-label col-md-3 col-sm-3  label-align">Ngày khởi
                                                    hành<span>*</span></label>
                                                <div class="col-md-6 col-sm-6">
                                                    <input type="text" class="form-control datetimepicker"
                                                        id="start_date" name="start_date" required>
                                                </div>
                                            </div>
                                            <div class="field item form-group">
                                                <label class="col-form-label col-md-3 col-sm-3  label-align">Ngày kết
                                                    thúc<span>*</span></label>
                                                <div class="col-md-6 col-sm-6">
                                                    <input type="text" class="form-control datetimepicker"
                                                        id="end_date" name="end_date" required>
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
                                        <form action="{{ route('admin.add-temp-images') }}"
                                            class="dropzone dz-clickable" id="myDropzone" data-delete-url="{{ route('admin.delete-temp-image') }}"
                                            enctype="multipart/form-data">
                                            @csrf
                                            <div class="dz-default dz-message">
                                                <span>Chọn hình ảnh về tours để upload</span>
                                            </div>
                                        </form>
                                    </div>
                                    <form action="{{ route('admin.add-timeline') }}" id="timeline-form"
                                        method="POST">
                                        @csrf
                                        <input type="hidden" name="tourId" class="hiddenTourId">
                                        <div id="step-3">
                                            <h2 class="StepTitle">Nhập lộ trình</h2>

                                        </div>

                                    </form>

                                </div>
                                <!-- End SmartWizard Content -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- /page content -->

        <!-- footer content -->
        <footer>
            <div class="pull-right">
                Gentelella - Bootstrap Admin Template by <a href="https://colorlib.com">Colorlib</a>
            </div>
            <div class="clearfix"></div>
        </footer>
        <!-- /footer content -->
    </div>
</div>
@include('admin.blocks.footer')
