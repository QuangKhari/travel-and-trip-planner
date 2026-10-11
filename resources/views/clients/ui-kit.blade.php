{{-- Trang tham chiếu giao diện (chỉ dùng khi phát triển). Route chỉ đăng ký khi APP_ENV=local. --}}
@include('clients.blocks.header')

<main class="kit">
    <div class="container">
        <h1>Bộ thành phần giao diện</h1>
        <p class="tv-muted">Mọi màn hình mới (Trip Planner, chuyến bay, khách sạn, ticket hỗ trợ…) nên ghép từ các thành
            phần dưới đây để giữ đồng nhất. Màu và khoảng cách nằm ở khối <code>:root</code> đầu file
            <code>travel-theme.css</code>.</p>

        <section>
            <h2>Màu</h2>
            <p class="kit-note">Quy tắc: xanh đậm cho chữ, xanh ngọc cho điểm nhấn, <b>cam chỉ cho một hành động chính
                    trên mỗi màn hình</b>.</p>
            <div class="kit-row">
                @foreach ([['--tv-teal', 'Xanh ngọc'], ['--tv-teal-dark', 'Xanh ngọc đậm'], ['--tv-orange-2', 'Cam (hành động chính)'], ['--tv-navy', 'Navy (nền tối)'], ['--tv-ink', 'Chữ'], ['--tv-mute', 'Chữ phụ'], ['--tv-line', 'Viền'], ['--tv-bg', 'Nền trang']] as $c)
                    <div class="kit-swatch"><i
                            style="background:var({{ $c[0] }})"></i><span>{{ $c[1] }}<br><code>{{ $c[0] }}</code></span>
                    </div>
                @endforeach
            </div>
        </section>

        <section>
            <h2>Chữ</h2>
            <div class="kit-box">
                <h1 style="font-size:34px">Tiêu đề trang (h1)</h1>
                <h2 class="td-h2">Tiêu đề phần (td-h2)</h2>
                <h3 class="td-h3">Tiêu đề nhỏ (td-h3)</h3>
                <p>Đoạn văn thường: dùng cho mô tả, hướng dẫn. Giữ dòng ngắn, ít nhấn mạnh.</p>
                <p class="tv-muted-sm">Chữ phụ (tv-muted-sm): ghi chú, thời gian, chú thích.</p>
            </div>
        </section>

        <section>
            <h2>Nút</h2>
            <p class="kit-note">Một màn hình chỉ nên có một nút cam. Nút phụ dùng viền (ghost).</p>
            <div class="kit-row">
                <button class="td-btn td-btn--cta" style="width:auto">Hành động chính</button>
                <button class="tv-btn-solid">Nút xanh</button>
                <button class="tv-btn-ghost">Nút phụ</button>
                <button class="tv-btn-solid tv-btn-sm">Nhỏ</button>
                <button class="td-btn td-btn--cta" style="width:auto" disabled>Vô hiệu</button>
            </div>
        </section>

        <section>
            <h2>Ô nhập</h2>
            <div class="kit-cols">
                <div class="kit-box">
                    <div class="tv-fieldrow"><label for="k1">Họ và tên <em>*</em></label><input id="k1"
                            placeholder="Nguyễn Văn A"></div>
                    <div class="tv-fieldrow"><label for="k2">Mật khẩu</label>
                        <div class="tv-pass"><input id="k2" type="password" value="matkhau123"><button
                                type="button" class="tv-pass__eye" data-toggle-pass aria-label="Hiện/ẩn"><i
                                    class="fal fa-eye"></i></button></div>
                    </div>
                    <div class="tv-fieldrow"><label for="k3">Lỗi mẫu</label><input id="k3" value="abc"
                            style="border-color:#D92D20">
                        <div class="invalid-feedback" style="display:block">Email không hợp lệ.</div>
                    </div>
                </div>
                <div class="kit-box">
                    <div class="tv-fieldrow"><label for="k4">Nhận xét</label>
                        <textarea id="k4" rows="4" placeholder="Nội dung..."></textarea>
                    </div>
                    <div class="tv-chiprow"><button class="tv-chipbtn is-on">Đang chọn</button><button
                            class="tv-chipbtn">Chưa chọn</button><button class="tv-chipbtn">Miền Trung
                            <span>12</span></button></div>
                </div>
            </div>
        </section>

        <section>
            <h2>Thông báo &amp; trạng thái</h2>
            <div class="kit-box">
                <div class="tv-alert tv-alert--ok">Thành công: đơn của bạn đã được ghi nhận.</div>
                <div class="tv-alert tv-alert--warn">Lưu ý: chỗ được giữ đến 18:00, hôm nay.</div>
                <div class="tv-alert tv-alert--bad">Lỗi: không thể hủy đơn này.</div>
                <div class="kit-row" style="margin-top:12px">
                    <span class="tv-status-pill is-wait">Chờ xác nhận</span><span class="tv-status-pill is-go">Sắp khởi
                        hành</span>
                    <span class="tv-status-pill is-done">Hoàn thành</span><span class="tv-status-pill is-cancel">Đã
                        hủy</span>
                    <button class="tv-btn-ghost tv-btn-sm"
                        onclick="window.toastr && toastr.success('Đã lưu thay đổi')">Thử toast</button>
                </div>
            </div>
        </section>

        <section>
            <h2>Màn hình trống, đang tải, hộp thoại</h2>
            <div class="kit-cols">
                <div class="tv-empty" style="margin:0">
                    <div class="tv-empty__icon"><i class="fal fa-compass"></i></div>
                    <h3>Chưa có dữ liệu</h3>
                    <p>Luôn nói rõ vì sao trống và việc người dùng nên làm tiếp theo.</p>
                    <button class="tv-btn-solid">Việc nên làm</button>
                </div>
                <div class="kit-box">
                    <p class="kit-note">Khung chờ (skeleton) thay cho vòng quay khi tải danh sách:</p>
                    <div class="tv-skeleton" style="height:120px;margin-bottom:12px"></div>
                    <div class="tv-skeleton" style="height:16px;width:70%;margin-bottom:8px"></div>
                    <div class="tv-skeleton" style="height:16px;width:40%;margin-bottom:22px"></div>
                    <button class="tv-btn-ghost" data-modal-open="kitModal">Mở hộp thoại xác nhận</button>
                </div>
            </div>
        </section>

        <section>
            <h2>Thẻ tour</h2>
            <p class="kit-note">Dùng <code>@@include('clients.partials.tour-card', ['tour' =&gt;
                    $tour])</code> — xem trang Tours.</p>
        </section>
    </div>

    <dialog class="tv-modal" id="kitModal">
        <div class="tv-modal__in">
            <h3>Hủy đơn đặt tour?</h3>
            <p>Hộp thoại xác nhận dùng cho hành động khó hoàn tác. Nút nguy hiểm đặt bên phải, nói rõ hậu quả.</p>
            <div class="tv-modal__actions">
                <button class="tv-btn-ghost" data-modal-close>Giữ đơn</button>
                <button class="tv-btn-solid" style="background:#D92D20" data-modal-close>Hủy đơn</button>
            </div>
        </div>
    </dialog>
</main>

@include('clients.blocks.footer')
