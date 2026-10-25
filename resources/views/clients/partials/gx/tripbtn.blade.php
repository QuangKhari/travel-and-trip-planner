{{-- Nút "Thêm vào chuyến đi" cho trang chi tiết. Biến: $type, $slug, $name, $url. travela-trip.js tự gắn xử lý theo [data-trip-add].
     (Nếu dự án của bạn đã có file này thì giữ file cũ, bỏ qua file trong gói.) --}}
<button type="button" class="gx-btn2" data-trip-add data-type="{{ $type }}" data-slug="{{ $slug }}"
    data-name="{{ $name }}" data-url="{{ $url }}" aria-pressed="false">+ Thêm vào chuyến đi</button>
