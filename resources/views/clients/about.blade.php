@include('clients.blocks.header')
@include('clients.blocks.banner')

<section class="tv-about">
    <div class="container">
        <div class="tv-about__intro">
            <h2 class="td-h2">Đặt tour du lịch rõ ràng, dễ theo dõi</h2>
            <p class="tv-lead">Travel là nền tảng đặt tour trực tuyến: bạn xem lịch trình từng ngày, so sánh giá và số
                chỗ còn lại, rồi đặt tour ngay trên website. Mọi thông tin hiển thị đều lấy từ hệ thống, không phải con
                số quảng cáo.</p>
        </div>

        <h3 class="td-h3 tv-about__h">Cách đặt tour</h3>
        <ol class="tv-steps">
            <li><span>1</span>
                <div><b>Chọn tour</b>
                    <p>Lọc theo miền, ngân sách, số ngày và đánh giá để tìm hành trình hợp với bạn.</p>
                </div>
            </li>
            <li><span>2</span>
                <div><b>Xem chi tiết</b>
                    <p>Đọc lịch trình từng ngày, xem ảnh và nhận xét của những khách đã đi.</p>
                </div>
            </li>
            <li><span>3</span>
                <div><b>Đặt chỗ</b>
                    <p>Điền thông tin liên hệ, chọn số hành khách và mã giảm giá nếu có. Chỗ của bạn được giữ trong
                        {{ (int) config('travela.hold_hours', 48) }} giờ.</p>
                </div>
            </li>
            <li><span>4</span>
                <div><b>Theo dõi đơn</b>
                    <p>Xem trạng thái ở mục “Tour đã đặt”, hủy đơn khi còn trong thời hạn cho phép và đánh giá sau
                        chuyến đi.</p>
                </div>
            </li>
        </ol>

        <div class="tv-about__cta">
            <a href="{{ route('tours') }}" class="tv-btn-solid">Xem các tour</a>
            <a href="{{ route('contact') }}" class="tv-btn-ghost">Liên hệ chúng tôi</a>
        </div>
    </div>
</section>

@include('clients.blocks.footer')
