{{--
  Hero trang chủ: ảnh nền phong cảnh, cụm 3 ảnh nghiêng bên phải; bên trái là hai lối vào:
    - "Chưa biết đi đâu": gõ một câu mô tả chuyến đi -> /len-ke-hoach?q=... (Trip Planner đọc câu này)
    - "Đã biết muốn đi đâu": ô tìm tour cũ.
  Chuyển tab: tv-home.js.
  CSS: tv3-home.css (.hh3-*). Ảnh nền + cụm ảnh trôi nhẹ bằng CSS, không cần JS.
  GIỮ NGUYÊN id: search_form, destination, start_date, end_date (custom-js.js đang dùng).
--}}
@php
    $img = fn($f) => asset('clients/assets/images/hero/' . $f);
@endphp
<section class="hh3" aria-labelledby="hh3-title">
    {{-- Ảnh nền phong cảnh (trôi rất chậm) + lớp sáng bên trái để chữ luôn đọc rõ --}}
    <div class="hh3-bg" aria-hidden="true">
        <img src="{{ $img('hero-bg.webp') }}" alt="" width="1800" height="900" fetchpriority="high">
    </div>
    <div class="hh3-veil" aria-hidden="true"></div>
    <div class="v3-wrap hh3-in">
        <div class="hh3-text">
            <p class="v3-eyebrow hh3-rise">Khám phá Việt Nam</p>
            <h1 id="hh3-title" class="hh3-rise hh3-d1">Bắt đầu từ <span>cảm hứng</span>,<br>đi trọn hành trình</h1>
            <p class="hh3-sub hh3-rise hh3-d2">Kể cho chúng tôi bạn muốn một chuyến đi thế nào, {{ config('site.name') }} gợi ý nơi đến, lên lịch trình và tìm tour phù hợp.</p>

            <div class="hx-entry hh3-rise hh3-d3">
                <div class="hx-tabs" role="tablist" aria-label="Cách bắt đầu">
                    <button type="button" role="tab" aria-selected="true" aria-controls="hxAi" id="hxTabAi"><i class="fas fa-magic" aria-hidden="true"></i> Chưa biết đi đâu</button>
                    <button type="button" role="tab" aria-selected="false" aria-controls="hxSearch" id="hxTabSearch" tabindex="-1"><i class="far fa-search" aria-hidden="true"></i> Đã biết muốn đi đâu</button>
                </div>
                <form class="hx-pane hx-ai" id="hxAi" role="tabpanel" aria-labelledby="hxTabAi" action="{{ route('planner') }}" method="GET">
                    <label class="sr-only" for="hxPrompt">Mô tả chuyến đi bạn muốn</label>
                    <div class="hx-ai__box">
                        <i class="fas fa-magic hx-ai__ico" aria-hidden="true"></i>
                        <input id="hxPrompt" name="q" type="text" autocomplete="off" placeholder="Ví dụ: 3 ngày biển, 2 người, khoảng 5 triệu">
                        <button class="hh3-go" type="submit">Lên lịch trình <i class="far fa-arrow-right" aria-hidden="true"></i></button>
                    </div>
                    <div class="hx-quick" aria-label="Gợi ý nhanh">
                        <span>Thử:</span>
                        <button type="button" data-q="Cuối tuần 2 ngày gần Hà Nội, thích núi">Cuối tuần gần Hà Nội</button>
                        <button type="button" data-q="4 ngày miền Trung, ăn ngon, đi cùng gia đình">Miền Trung cùng gia đình</button>
                        <button type="button" data-q="3 ngày biển cho hai người, yên tĩnh">Biển cho hai người</button>
                    </div>
                </form>
                <div class="hx-pane" id="hxSearch" role="tabpanel" aria-labelledby="hxTabSearch" hidden>
                    <form action="{{ route('search') }}" method="GET" id="search_form">
                <div class="tv-search hh3-search">
                    <div class="tv-field" style="flex:1.35">
                        <i class="fal fa-map-marker-alt" aria-hidden="true"></i>
                        <div class="tv-box">
                            <label class="tv-label" for="destination">Bạn muốn đi đâu?</label>
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
                        <i class="fal fa-calendar-alt" aria-hidden="true"></i>
                        <div class="tv-box">
                            <label class="tv-label" for="start_date">Ngày khởi hành</label>
                            <input type="text" id="start_date" name="start_date"
                                class="datetimepicker datetimepicker-custom" placeholder="Chọn ngày đi" readonly>
                        </div>
                    </div>
                    <div class="tv-field">
                        <i class="fal fa-calendar-check" aria-hidden="true"></i>
                        <div class="tv-box">
                            <label class="tv-label" for="end_date">Ngày kết thúc</label>
                            <input type="text" id="end_date" name="end_date"
                                class="datetimepicker datetimepicker-custom" placeholder="Chọn ngày về" readonly>
                        </div>
                    </div>
                    <button class="hh3-go" type="submit"><i class="far fa-search" aria-hidden="true"></i>
                        Tìm kiếm</button>
                </div>
            </form>
                </div>
            </div>

        </div>

        {{-- Cụm ảnh nghiêng: ảnh chính dùng hero.jpg (Lý Sơn) --}}
        <div class="hh3-photos" aria-hidden="true">
            <svg class="hh3-plane" viewBox="0 0 220 90" fill="none">
                <path d="M4 80c40 6 70-30 60-44S30 30 44 52s70 10 120-30" stroke="#0b9488" stroke-width="1.6"
                    stroke-dasharray="3 7" stroke-linecap="round" />
                <path d="M172 18l26-14-8 26-6-7-9 4z" fill="#0b9488" />
            </svg>
            <figure class="hh3-ph hh3-ph--main">
                <picture>
                    <source srcset="{{ $img('hero-card.webp') }}" type="image/webp">
                    <img src="{{ $img('hero.jpg') }}" alt="" width="1100" height="489" fetchpriority="high">
                </picture>
            </figure>
            <figure class="hh3-ph hh3-ph--b"><img src="{{ $img('hero-hoian.webp') }}" alt="" width="760"
                    height="380" loading="eager"></figure>
            <figure class="hh3-ph hh3-ph--c"><img src="{{ $img('hero-ruong.webp') }}" alt="" width="760"
                    height="380" loading="eager"></figure>
                    </div>
    </div>

    <svg class="hh3-wave" viewBox="0 0 1440 90" preserveAspectRatio="none" aria-hidden="true">
        <path d="M0 52 C240 92 470 18 760 44 S1220 86 1440 36 V90 H0Z" />
    </svg>

</section>
