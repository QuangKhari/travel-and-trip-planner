@include('clients.blocks.header')
@include('clients.blocks.banner')

<section class="tv-booking">
    <div class="container">
        {{-- <h1 class="text-center booking-header">Tổng Quan Về Chuyến Đi</h1> --}}

        <form action="{{ route('create-booking') }}" method="POST" class="booking-container">
            @csrf
            <input type="hidden" name="requestToken" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <!-- Contact Information -->
            <div class="booking-info">
                <h2 class="booking-header"><span class="tv-step">1</span> Thông tin liên lạc</h2>
                <div class="booking__infor">
                    <div class="form-group">
                        <label for="username">Họ và tên <em>*</em></label>
                        <input type="text" id="username" placeholder="Nhập Họ và tên" name="fullName" required>
                        <span class="error-message" id="usernameError"></span>
                    </div>

                    <div class="form-group">
                        <label for="email">Email <em>*</em></label>
                        <input type="email" id="email" placeholder="sample@gmail.com" name="email" required>
                        <span class="error-message" id="emailError"></span>
                    </div>

                    <div class="form-group">
                        <label for="tel">Số điện thoại <em>*</em></label>
                        <input type="number" id="tel" placeholder="Nhập số điện thoại liên hệ" name="tel"
                            required>
                        <span class="error-message" id="telError"></span>
                    </div>

                    <div class="form-group">
                        <label for="address">Địa chỉ <em>*</em></label>
                        <input type="text" id="address" placeholder="Nhập địa chỉ liên hệ" name="address" required>
                        <span class="error-message" id="addressError"></span>
                    </div>

                </div>


                <!-- Passenger Details -->
                <h2 class="booking-header"><span class="tv-step">2</span> Số lượng hành khách</h2>

                <div class="booking__quantity">
                    <div class="form-group quantity-selector">
                        <label>Người lớn <small>từ 12 tuổi</small></label>
                        <div class="input__quanlity">
                            <button type="button" class="quantity-btn">-</button>
                            <input type="number" class="quantity-input" value="1" min="1" id="numAdults"
                                name="numAdults" data-price-adults="{{ $tour->priceAdult }}" readonly>
                            <button type="button" class="quantity-btn">+</button>
                        </div>
                    </div>

                    <div class="form-group quantity-selector">
                        <label>Trẻ em <small>dưới 12 tuổi</small></label>
                        <div class="input__quanlity">
                            <button type="button" class="quantity-btn">-</button>
                            <input type="number" class="quantity-input" value="0" min="0" id="numChildren"
                                name="numChildren" data-price-children="{{ $tour->priceChild }}" readonly>
                            <button type="button" class="quantity-btn">+</button>
                        </div>
                    </div>
                </div>
                <!-- Privacy Agreement Section -->
                <div class="privacy-section">
                    <p>Khi đặt tour, bạn đồng ý với điều kiện đặt chỗ và chính sách hủy tour của Travel. Vui lòng kiểm
                        tra kỹ thông tin trước khi xác nhận.</p>
                    <div class="privacy-checkbox">
                        <input type="checkbox" id="agree" name="agree" required>
                        <label for="agree">Tôi đã đọc và đồng ý với điều kiện đặt tour</label>
                    </div>
                </div>
                <!-- Payment Method -->
                <h2 class="booking-header"><span class="tv-step">3</span> Phương thức thanh toán</h2>

                <label class="payment-option">
                    <input type="radio" name="payment" value="office-payment" checked required>
                    <span class="tv-pay-ico"><i class="fal fa-building"></i></span>
                    <span><b>Thanh toán tại văn phòng</b><small>Giữ chỗ trước, thanh toán khi đến văn
                            phòng.</small></span>
                </label>
                <p class="tv-pay-note">Thanh toán trực tuyến (VNPay) sẽ sớm được hỗ trợ.</p>
                <input type="hidden" name="payment_hidden" id="payment_hidden">
            </div>

            <!-- Order Summary -->
            <div class="booking-summary">
                <div class="summary-section">
                    <div>
                        <p>Mã tour : {{ $tour->tourId }}</p>
                        <input type="hidden" name="tourId" id="tourId" value="{{ $tour->tourId }}">
                        <h5 class="widget-title">{{ $tour->title }}</h5>
                        <p>Ngày khởi hành : {{ date('d-m-Y', strtotime($tour->startDate)) }}</p>
                        <p>Ngày kết thúc : {{ date('d-m-Y', strtotime($tour->endDate)) }}</p>
                        <p class="quantityAvailable">Số chỗ còn nhận : {{ $tour->quantity }}</p>
                    </div>

                    <div class="order-summary">
                        <div class="summary-item">
                            <span>Người lớn:</span>
                            <div>
                                <span class="quantity__adults">1</span>
                                <span>X</span>
                                <span class="total-price">0 VNĐ</span>
                            </div>
                        </div>
                        <div class="summary-item">
                            <span>Trẻ em:</span>
                            <div>
                                <span class="quantity__children">0</span>
                                <span>X</span>
                                <span class="total-price">0 VNĐ</span>
                            </div>
                        </div>
                        <div class="summary-item">
                            <span>Giảm giá:</span>
                            <div>
                                <span class="total-price">0 VNĐ</span>
                            </div>
                        </div>
                        <div class="summary-item total-price">
                            <span>Tổng cộng:</span>
                            <span>0 VNĐ</span>
                            <input type="hidden" class="totalPrice" name="totalPrice" value="">
                        </div>
                    </div>
                    <div class="order-coupon">
                        <input type="text" id="couponCode" name="couponCode" placeholder="Mã giảm giá">
                        <button type="button" class="booking-btn btn-coupon">Áp dụng</button>
                    </div>
                    <p id="coupon-message" class="tv-coupon-msg"></p>
                    <input type="hidden" name="promotionId" id="promotionId" value="">
                    <input type="hidden" name="discountAmount" id="discountAmount" value="0">

                    <div id="paypal-button-container"></div>

                    <button type="submit" class="booking-btn btn-submit-booking">Xác nhận đặt tour</button>
                    <p class="tv-sum-note">Bạn chưa bị tính phí ở bước này. Chỗ sẽ được giữ để bạn hoàn tất thanh toán.
                    </p>
                </div>
            </div>
        </form>
    </div>
</section>
<script>
    var applyCouponUrl = "{{ route('apply-coupon') }}";
</script>

@include('clients.blocks.footer')
