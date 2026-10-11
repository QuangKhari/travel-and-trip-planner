@include('clients.blocks.header')
@include('clients.blocks.banner', [
    'title' => 'Giới thiệu',
    'subtitle' => 'Một không gian để chọn hành trình rõ ràng, nhẹ nhàng và đáng nhớ.',
])

<section class="tv-about">
    <div class="container">
        <div class="tv-about__hero">
            <div class="tv-about__hero-copy">
                <span class="tv-tag"><i class="fal fa-leaf"></i> Travel with intention</span>
                <h2>Du lịch thiên nhiên, nhưng trải nghiệm thật hiện đại.</h2>
                <p>Travel giúp bạn xem tour, lịch trình, hình ảnh, giá và số chỗ còn lại trong một trải nghiệm trực
                    quan. Mục tiêu là để bạn dành ít thời gian hơn cho việc tìm kiếm và nhiều thời gian hơn cho việc tận
                    hưởng.</p>
                <div class="tv-about__facts">
                    <div><b>01</b><span>Chọn hành trình</span></div>
                    <div><b>02</b><span>Đặt chỗ rõ ràng</span></div>
                    <div><b>03</b><span>Theo dõi dễ dàng</span></div>
                </div>
            </div>
            <figure class="tv-about__hero-media">
                <img src="{{ asset('clients/assets/images/about/about-page.jpg') }}"
                    alt="Khung cảnh thiên nhiên trong chuyến du lịch">
                <figcaption><i class="fal fa-map-marker-alt"></i> Đi xa hơn, nhưng theo nhịp của bạn.</figcaption>
            </figure>
        </div>

        <div class="tv-about__intro">
            <h2 class="td-h2">Đặt tour rõ ràng, theo dõi hành trình dễ dàng</h2>
            <p class="tv-lead">Bạn có thể lọc theo điểm đến, ngân sách và thời lượng; xem lịch trình từng ngày; kiểm tra
                số chỗ còn lại; rồi đặt tour ngay trên website. Sau khi đặt, mọi trạng thái đều được tập trung ở “Tour
                đã đặt”.</p>
        </div>

        <h3 class="td-h3 tv-about__h">Hành trình đặt tour</h3>
        <ol class="tv-steps">
            <li><span>1</span>
                <div><b>Chọn tour</b>
                    <p>Lọc theo miền, ngân sách, số ngày và đánh giá để tìm hành trình hợp với bạn.</p>
                </div>
            </li>
            <li><span>2</span>
                <div><b>Xem chi tiết</b>
                    <p>Đọc lịch trình từng ngày, xem ảnh, điểm đến và nhận xét trước khi quyết định.</p>
                </div>
            </li>
            <li><span>3</span>
                <div><b>Đặt chỗ</b>
                    <p>Điền thông tin, chọn số hành khách và mã giảm giá. Chỗ được giữ theo chính sách của hệ thống.</p>
                </div>
            </li>
            <li><span>4</span>
                <div><b>Theo dõi đơn</b>
                    <p>Xem trạng thái, chi tiết đơn, hủy khi đủ điều kiện và đánh giá sau chuyến đi.</p>
                </div>
            </li>
        </ol>

        <div class="tv-about__cta">
            <a href="{{ route('tours') }}" class="tv-btn-solid">Khám phá tour <i class="fal fa-arrow-right"></i></a>
            <a href="{{ route('contact') }}" class="tv-btn-ghost">Nhắn cho chúng tôi</a>
        </div>
    </div>
</section>

@include('clients.blocks.footer')
