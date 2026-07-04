@include('admin.blocks.header')
<div class="container body">
    <div class="main_container">
        @include('admin.blocks.sidebar')
        <!-- page content -->
        <div class="right_col" role="main">
            <div class="">
                <div class="page-title">
                    <div class="title_left">
                        <h3>Hóa đơn <small>đặt tour du lịch</small></h3>
                    </div>
                </div>

                <div class="clearfix"></div>

                <div class="row">
                    <div class="col-md-12">
                        <div class="x_panel">
                            <div class="invoice_booking">
                                <div class="x_title">
                                    <h2>Hóa đơn chi tiết</h2>
                                    <ul class="nav navbar-right panel_toolbox">
                                        <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a>
                                        </li>
                                        <li><a class="close-link"><i class="fa fa-close"></i></a>
                                        </li>
                                    </ul>
                                    <div class="clearfix"></div>
                                </div>
                                <div class="x_content">
                                    <section class="content invoice">
                                        <!-- title row -->
                                        <div class="row">
                                            <div class="  invoice-header">
                                                <h3>
                                                    <img src="{{ asset('admin/assets/images/icon/icon_office.png') }}"
                                                        alt=""
                                                        style="margin-right: 10px">{{ $invoice_booking->title }}
                                                    <small class="pull-right">Ngày:
                                                        {{ date('d-m-Y', strtotime($invoice_booking->bookingDate)) }}</small>
                                                </h3>
                                            </div>
                                            <!-- /.col -->
                                        </div>
                                        <!-- info row -->
                                        <div class="row invoice-info">
                                            <div class="col-sm-4 invoice-col">
                                                Từ
                                                <address>
                                                    <strong>{{ $invoice_booking->fullName }}</strong>
                                                    <br>{{ $invoice_booking->address }}
                                                    <br>Số điện thoại: {{ $invoice_booking->phoneNumber }}
                                                    <br>Email:{{ $invoice_booking->email }}
                                                </address>
                                            </div>
                                            <!-- /.col -->
                                            <div class="col-sm-4 invoice-col">
                                                Đến
                                                <address>
                                                    <strong>Công ty Travela</strong>
                                                    <br>470 Trần Đại Nghĩa
                                                    <br>Gỉai Phóng, Hai Bà Trưng, Hà Nội
                                                    <br>Phone: 12345678901
                                                    <br>Email: aaaaaa@gmail.com
                                                </address>
                                            </div>
                                            <!-- /.col -->
                                            <div class="col-sm-4 invoice-col">
                                                <b>Mã hóa đơn #{{ $invoice_booking->checkoutId }}</b>
                                                <br>
                                                <br>
                                                <b>Mã giao dịch:</b> {{ $invoice_booking->transactionId }}
                                                <br>
                                                <b>Ngày thanh toán:</b> {{ $invoice_booking->paymentDate }}
                                                <br>
                                                <b>Tài khoản:</b> {{ $invoice_booking->userId }}
                                            </div>
                                            <!-- /.col -->
                                        </div>
                                        <!-- /.row -->

                                        <!-- Table row -->
                                        <div class="row">
                                            <div class="  table">
                                                <table class="table table-striped">
                                                    <thead>
                                                        <tr>
                                                            <th></th>
                                                            <th>Số lượng</th>
                                                            <th>Đơn giá</th>
                                                            <th>Điểm đến</th>
                                                            <th>Tổng tiền</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <tr>
                                                            <td>Người lớn</td>
                                                            <td>{{ $invoice_booking->numAdults }}</td>
                                                            <td>{{ number_format($invoice_booking->priceAdult, 0, ',', '.') }}
                                                                vnđ</td>
                                                            <td>{{ $invoice_booking->destination }}</td>
                                                            <td>{{ number_format($invoice_booking->priceAdult * $invoice_booking->numAdults, 0, ',', '.') }}
                                                                vnđ</td>
                                                        </tr>
                                                        <tr>
                                                            <td>Trẻ em</td>
                                                            <td>{{ $invoice_booking->numChildren }}</td>
                                                            <td>{{ number_format($invoice_booking->priceChild, 0, ',', '.') }}
                                                                vnđ</td>
                                                            <td>{{ $invoice_booking->destination }}</td>
                                                            <td>{{ number_format($invoice_booking->priceChild * $invoice_booking->numChildren, 0, ',', '.') }}
                                                                vnđ</td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <!-- /.col -->
                                        </div>
                                        <!-- /.row -->

                                        <div class="row">
                                            <!-- accepted payments column -->
                                            <div class="col-md-6">
                                                <p class="lead">Phương thức thanh toán:</p>
                                                @if ($invoice_booking->paymentMethod == 'momo-payment')
                                                    <img src="{{ asset('admin/assets/images/icon/icon_momo.png') }}"
                                                        class="invoice_payment-method" alt="">
                                                @elseif ($invoice_booking->paymentMethod == 'banking')
                                                    <i class="fa fa-university" style="font-size: 40px; color: #26B99A;"></i>
                                                    <span class="badge badge-success">Chuyển khoản ngân hàng</span>
                                                @else
                                                    <img src="{{ asset('admin/assets/images/icon/icon_office.png') }}"
                                                        alt="">
                                                    <span class="badge badge-info">Thanh toán tại văn phòng</span>
                                                @endif
                                                @if (!empty($invoice_booking->transferProofImage))
                                                    <p class="lead" style="margin-top: 20px;">Ảnh biên lai chuyển khoản:</p>
                                                        <a href="{{ asset('clients/assets/images/transfer-proofs/' . $invoice_booking->transferProofImage) }}"
                                                            target="_blank">
                                                            <img src="{{ asset('clients/assets/images/transfer-proofs/' . $invoice_booking->transferProofImage) }}"
                                                            alt="Ảnh biên lai chuyển khoản"
                                                            style="max-width: 280px; border: 1px solid #ddd; border-radius: 6px;">
                                                        </a>
                                                    <p class="text-muted" style="margin-top: 5px;"><small>Bấm vào ảnh để xem kích thước đầy đủ</small></p>
                                                @endif
                                                <p class="text-muted well well-sm no-shadow" style="margin-top: 10px;">
                                                    Vui lòng hoàn tất thanh toán theo hướng dẫn hoặc liên hệ với chúng
                                                    tôi nếu cần hỗ trợ.
                                                </p>
                                            </div>
                                            <!-- /.col -->
                                            <div class="col-md-6">
                                                <p class="lead">Số tiền phải trả trước
                                                    {{ date('d-m-Y', strtotime($invoice_booking->startDate)) }}</p>
                                                <div class="table-responsive">
                                                    <table class="table">
                                                        <tbody>
                                                            <tr>
                                                                <th style="width:50%">Tổng tiền:</th>
                                                                <td>{{ number_format($invoice_booking->totalPrice, 0, ',', '.') }}
                                                                    vnđ</td>
                                                            </tr>
                                                            
                                                            
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                            <!-- /.col -->
                                        </div>
                                        <!-- /.row -->


                                    </section>
                                </div>
                            </div>
                            <!-- this row will not appear when printing -->
                            <div class="row no-print">
                                <div class=" ">
                                    <button class="btn btn-default" onclick="window.print();"><i
                                            class="fa fa-print"></i> Print</button>

                                    @if ($invoice_booking->bookingStatus == 'y')
                                        <button class="btn btn-warning pull-right finish-booking"
                                            data-bookingid="{{ $invoice_booking->bookingId }}"
                                            data-urlfinish="{{ route('admin.finish-booking') }}"
                                            style="margin-right: 5px;">
                                            <i class="fa fa-check-circle"></i> Tour đã hoàn thành
                                        </button>
                                    @endif

                                    
                                    <button id="received-money" data-bookingid= "{{ $invoice_booking->bookingId }}"
                                         data-urlPaid="{{ route('admin.received') }}"
                                        class="btn btn-info pull-right {{ $hide }}" style="margin-right: 5px;"><i
                                            class="glyphicon glyphicon-usd"></i> Đã thanh toán</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- /page content -->
    </div>
</div>
@include('admin.blocks.footer')