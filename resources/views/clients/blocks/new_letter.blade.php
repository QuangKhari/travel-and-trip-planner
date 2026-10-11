{{-- Dải kêu gọi cuối trang (thay cho form bản tin cũ vốn chưa có chức năng gửi) --}}
<section class="tv-cta">
    <div class="container">
        <div class="tv-cta__box">
            <div>
                <h2>Chưa chọn được điểm đến?</h2>
                <p>Lọc tour theo miền, ngân sách và số ngày đi, hoặc nhắn cho chúng tôi để được tư vấn.</p>
            </div>
            <div class="tv-cta__actions">
                <a href="{{ route('tours') }}" class="tv-btn-orange">Xem tất cả tour</a>
                <a href="{{ route('contact') }}" class="tv-cta__link">Liên hệ tư vấn <i class="fal fa-arrow-right"></i></a>
            </div>
        </div>
    </div>
</section>
