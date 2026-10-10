<!-- Hero (Travel theme v2) -->
<section class="tv-hero">
    <div class="tv-hero__bg" style="background-image:url('{{ asset('clients/assets/images/hero/hero.jpg') }}');"></div>
    <div class="tv-hero__shade"></div>

    <div class="container">
        <span class="tv-eyebrow tv-rise">Khám phá thế giới</span>
        <h1 class="tv-rise tv-d1">
            Những hành trình
            <em>đáng nhớ
                <svg viewBox="0 0 200 14" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M2 9 Q50 1 100 8 T198 6" stroke="#FF9F1C" stroke-width="4" fill="none"
                        stroke-linecap="round" />
                </svg>
            </em>
        </h1>
        <p class="tv-sub tv-rise tv-d2">Cùng Travel khám phá những điểm đến tuyệt đẹp, kiến tạo những kỷ niệm khó quên!
        </p>

        {{-- GIỮ NGUYÊN id: search_form, destination, start_date, end_date (custom-js.js đang dùng) --}}
        <form action="{{ route('search') }}" method="GET" id="search_form" class="tv-rise tv-d3">
            <div class="tv-search">
                <div class="tv-field" style="flex:1.5">
                    <i class="fal fa-map-marker-alt"></i>
                    <div class="tv-box">
                        <span class="tv-label">Bạn muốn đi đâu?</span>
                        <select name="destination" id="destination">
                            <option value="">Chọn điểm đến</option>
                            <option value="dn">Đà Nẵng</option>
                            <option value="cd">Côn Đảo</option>
                            <option value="hn">Hà Nội</option>
                            <option value="hcm">TP. Hồ Chí Minh</option>
                            <option value="hl">Hạ Long</option>
                            <option value="nb">Ninh Bình</option>
                            <option value="pq">Phú Quốc</option>
                            <option value="dl">Đà Lạt</option>
                            <option value="qt">Quảng Trị</option>
                            <option value="kh">Khánh Hòa (Nha Trang)</option>
                            <option value="ct">Cần Thơ</option>
                            <option value="vt">Vũng Tàu</option>
                            <option value="qn">Quảng Ninh</option>
                            <option value="la">Lào Cai (Sa Pa)</option>
                            <option value="bd">Bình Định (Quy Nhơn)</option>
                        </select>
                    </div>
                </div>

                <div class="tv-field">
                    <i class="fal fa-calendar-alt"></i>
                    <div class="tv-box">
                        <span class="tv-label">Ngày khởi hành</span>
                        <input type="text" id="start_date" name="start_date"
                            class="datetimepicker datetimepicker-custom" placeholder="Chọn ngày đi" readonly>
                    </div>
                </div>

                <div class="tv-field" style="border-right:0">
                    <i class="fal fa-calendar-check"></i>
                    <div class="tv-box">
                        <span class="tv-label">Ngày kết thúc</span>
                        <input type="text" id="end_date" name="end_date"
                            class="datetimepicker datetimepicker-custom" placeholder="Chọn ngày về" readonly>
                    </div>
                </div>

                <button class="theme-btn" type="submit">
                    <span data-hover="Tìm kiếm">Tìm kiếm</span>
                    <i class="far fa-search"></i>
                </button>
            </div>
        </form>
    </div>

    <div class="tv-scroll">Cuộn xuống<br>↓</div>

    <svg class="tv-wave" viewBox="0 0 1440 100" preserveAspectRatio="none" aria-hidden="true">
        <path d="M0 45 Q180 100 380 58 T760 62 T1130 40 T1440 68 V100 H0Z" />
    </svg>
</section>
<!-- Hero end -->
