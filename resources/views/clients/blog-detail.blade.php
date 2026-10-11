@include('clients.blocks.header')
@include('clients.blocks.banner', [
    'title' => 'Cẩm nang du lịch',
    'subtitle' => 'Một bài viết nhỏ cho một hành trình lớn.',
])

<main class="blog-detaisl-page">
    <div class="container">
        <article class="tv-blog-detail">
            <header class="tv-blog-detail__head">
                <span class="tv-tag"><i class="fal fa-leaf"></i> Kinh nghiệm du lịch</span>
                <h1>5 cách chọn một hành trình thật sự hợp với bạn</h1>
                <div class="tv-blog-detail__meta">
                    <span><i class="fal fa-calendar-alt"></i> 12/09/2026</span>
                    <span><i class="fal fa-clock"></i> 5 phút đọc</span>
                    <span><i class="fal fa-user"></i> Travela Journal</span>
                </div>
            </header>

            <figure class="tv-blog-detail__hero">
                <img src="{{ asset('clients/assets/images/blog/blog-details.jpg') }}"
                    alt="Cảnh biển và núi trong chuyến du lịch">
                <figcaption>Một hành trình đẹp bắt đầu từ việc chọn đúng nhịp điệu cho chính mình.</figcaption>
            </figure>

            <div class="tv-blog-detail__content">
                <p class="lead">Một tour phù hợp không nhất thiết là tour nổi tiếng nhất. Đó là hành trình có nhịp độ,
                    điểm đến và khoảng thời gian nghỉ ngơi phù hợp với người đi.</p>
                <h2>1. Xác định bạn muốn cảm nhận điều gì</h2>
                <p>Nếu bạn muốn thư giãn, hãy ưu tiên những lịch trình có thời gian tự do và ít phải di chuyển liên tục.
                    Nếu muốn khám phá, hãy tìm những hành trình có nhiều điểm dừng và hoạt động trải nghiệm.</p>
                <h2>2. Đọc lịch trình theo từng ngày</h2>
                <p>Đừng chỉ nhìn tổng số ngày. Hãy chú ý giờ khởi hành, thời gian di chuyển, thời gian tự do và những
                    hoạt động bắt buộc. Đây là cách nhanh nhất để biết tour có “đúng nhịp” với mình hay không.</p>
                <div class="tv-blog-detail__quote"><i class="fal fa-quote-left"></i><span>Mỗi chuyến đi đều có một nhịp
                        riêng. Hãy chọn nhịp khiến bạn muốn nhớ lại sau khi trở về.</span></div>
                <h2>3. Kiểm tra ngân sách và những gì đã bao gồm</h2>
                <p>So sánh giá là cần thiết, nhưng hãy nhìn cả những dịch vụ đã bao gồm, số bữa ăn, phương tiện và các
                    khoản có thể phát sinh. Một lựa chọn rõ ràng ngay từ đầu sẽ giúp bạn tận hưởng chuyến đi thoải mái
                    hơn.</p>
                <h2>4. Chừa lại một khoảng trống cho bất ngờ</h2>
                <p>Lịch trình tốt không phải lịch trình kín mọi phút. Một buổi chiều thong thả bên biển, một quán cà phê
                    nhỏ hay một góc ngắm hoàng hôn đôi khi lại trở thành ký ức đẹp nhất.</p>
            </div>

            <footer class="tv-blog-detail__foot">
                <a href="{{ route('blogs') }}" class="tv-btn-ghost"><i class="fal fa-arrow-left"></i> Về cẩm nang</a>
                <a href="{{ route('tours') }}" class="tv-btn-solid">Khám phá tour <i class="fal fa-arrow-right"></i></a>
            </footer>
        </article>
    </div>
</main>

@include('clients.blocks.footer')
