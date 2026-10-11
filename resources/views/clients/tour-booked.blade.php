@include('clients.blocks.header')
@include('clients.blocks.banner')

<section class="tv-booking tv-booking--view">
    <div class="container">
        {{-- <h1 class="text-center booking-header">Tổng Quan Về Chuyến Đi</h1> --}}

        <form action="{{ route('cancel-booking') }}" method="POST" class="booking-container">
            @csrf
            <!-- Contact Information -->
            <div class="booking-info">
                <h2 class="booking-header">Thông tin liên lạc</h2>
                <div class="booking__infor">
                    <div class="form-group">
                        <label for="username">Họ và tên*</label>
                        <input type="text" id="username" placeholder="Nhập Họ và tên" name="fullName"
                            value="{{ $tour_booked->fullName }}" readonly>
                        <span class="error-message" id="usernameError"></span>
                    </div>

                    <div class="form-group">
                        <label for="email">Email*</label>
                        <input type="email" id="email" placeholder="sample@gmail.com" name="email"
                            value="{{ $tour_booked->email }}" readonly>
                        <span class="error-message" id="emailError"></span>
                    </div>

                    <div class="form-group">
                        <label for="tel">Số điện thoại*</label>
                        <input type="number" id="tel" placeholder="Nhập số điện thoại liên hệ" name="tel"
                            value="{{ $tour_booked->phoneNumber }}" readonly>
                        <span class="error-message" id="telError"></span>
                    </div>

                    <div class="form-group">
                        <label for="address">Địa chỉ*</label>
                        <input type="text" id="address" placeholder="Nhập địa chỉ liên hệ" name="address"
                            value="{{ $tour_booked->address }}" readonly>
                        <span class="error-message" id="addressError"></span>
                    </div>

                </div>

                <!-- Privacy Agreement Section -->
                <div class="privacy-section">
                    <p>Bạn đã đồng ý với điều kiện đặt tour của Travel khi tạo đơn này.</p>
                    <div class="privacy-checkbox">
                        <input type="checkbox" id="agree" name="agree" checked disabled>
                        <label for="agree">Đã đồng ý với điều kiện đặt tour</label>
                    </div>
                </div>
                @if ($tour_booked->bookingStatus == 'n' && !empty($tour_booked->holdExpiresAt))
                    <p class="tv-alert tv-alert--warn">
                        Chỗ được giữ đến {{ \Carbon\Carbon::parse($tour_booked->holdExpiresAt)->format('H:i d/m/Y') }}.
                        Quá hạn mà đơn chưa được xác nhận, đơn sẽ tự hủy.
                    </p>
                @endif

                <!-- Payment Method -->
                <h2 class="booking-header">Phương thức thanh toán</h2>

                <label class="payment-option">
                    <input type="radio" value="office-payment" @if ($tour_booked->paymentMethod == 'office-payment') checked @endif
                        disabled>
                    <span class="tv-pay-ico"><i class="fal fa-building"></i></span>
                    <span><b>Thanh toán tại văn phòng</b></span>
                </label>

            </div>

            <!-- Order Summary -->
            <div class="booking-summary">
                <div class="summary-section">
                    <div>
                        <p>Mã tour : {{ $tour_booked->tourId }}</p>
                        <input type="hidden" name="tourId" id="tourId" value="{{ $tour_booked->tourId }}">
                        <h5 class="widget-title">{{ $tour_booked->title }}</h5>
                        <p>Ngày khởi hành : {{ date('d-m-Y', strtotime($tour_booked->startDate)) }}</p>
                        <p>Ngày kết thúc : {{ date('d-m-Y', strtotime($tour_booked->endDate)) }}</p>
                    </div>

                    <div class="order-summary">
                        <div class="summary-item">
                            <span>Người lớn:</span>
                            <div>
                                <span
                                    class="quantity__adults-booked">{{ number_format($tour_booked->numAdults) }}</span>
                                <input type="hidden" name="quantity__adults" value="{{ $tour_booked->numAdults }}">
                                <span>X</span>
                                <span
                                    class="total-price-booked">{{ number_format($tour_booked->priceAdult, 0, ',', '.') }}
                                    VNĐ</span>
                            </div>
                        </div>
                        <div class="summary-item">
                            <span>Trẻ em:</span>
                            <div>
                                <span
                                    class="quantity__children-booked">{{ number_format($tour_booked->numChildren) }}</span>
                                <input type="hidden" name="quantity__children"
                                    value="{{ $tour_booked->numChildren }}">
                                <span>X</span>
                                <span
                                    class="total-price-booked">{{ number_format($tour_booked->priceChild, 0, ',', '.') }}
                                    VNĐ</span>
                            </div>
                        </div>
                        <div class="summary-item">
                            <span>Giảm giá:</span>
                            <div>
                                <span class="total-price-booked">
                                    {{ number_format($tour_booked->numAdults * $tour_booked->priceAdult + $tour_booked->numChildren * $tour_booked->priceChild - $tour_booked->totalPrice, 0, ',', '.') }}
                                    VNĐ
                                </span>
                            </div>
                        </div>
                        <div class="summary-item total-price-booked">
                            <span>Tổng cộng:</span>
                            <span>{{ number_format($tour_booked->totalPrice, 0, ',', '.') }} VNĐ</span>
                        </div>
                    </div>

                    <input type="hidden" name="bookingId" value="{{ $bookingId }}">

                    @if ($tour_booked->bookingStatus == 'f')
                        <a href="{{ route('tour-detail', ['id' => $tour_booked->tourId]) }}" class="booking-btn">
                            Đánh giá
                        </a>
                    @elseif ($tour_booked->bookingStatus == 'c')
                        <p class="tv-alert tv-alert--bad">Đơn này đã được hủy.</p>
                    @elseif ($canCancel)
                        <button type="submit" class="booking-btn btn-cancel-booking">Hủy
                            Tour</button>
                    @else
                        <p class="tv-alert tv-alert--muted">
                            Không thể hủy trong vòng {{ \App\Support\CancelPolicy::MIN_DAYS }} ngày trước ngày khởi
                            hành.
                            Vui lòng liên hệ Travel để được hỗ trợ.
                        </p>
                    @endif

                </div>
            </div>
        </form>
    </div>
</section>


@include('clients.blocks.footer')
