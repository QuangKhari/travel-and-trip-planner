# Dữ liệu điểm đến lõi (Đợt D mục D3)

Sáu tệp CSV cho các điểm đến lõi còn thiếu dữ liệu: Đà Nẵng, Huế, Sa Pa, Phú Quốc, Nha Trang, Cần Thơ.
Mỗi tệp: 20–21 nơi tham quan (≥ 5 trong nhà cho ngày mưa) và 10 nơi ăn uống, đủ giờ mở cửa, thời lượng, giá, nguồn.
Hà Nội, Hội An, Đà Lạt đã đủ từ gói `demo_city_pack.json`.

## Cách nạp (đúng quy trình duyệt)
1. Admin › **Nhập địa điểm** › chọn tệp, ghi chú lô (ví dụ "Dữ liệu lõi Đà Nẵng") › Nạp và kiểm tra.
   Hoặc dòng lệnh: `php artisan places:import database/seeders/data/imports/da-nang.csv --note="Dữ liệu lõi Đà Nẵng"`
2. Mở lô, **kiểm tra từng dòng** (bấm tọa độ để mở bản đồ), sửa tệp nếu cần rồi nạp lại.
3. Chọn các dòng đã kiểm tra › tick "Hiển thị ngay cho khách" › Duyệt.
4. Chạy `php artisan places:audit --core` và `php artisan planner:coverage --all`: mọi điểm đến lõi phải "đạt" / "đủ".

## Phải kiểm tra trước khi duyệt
Tên, tọa độ, giờ mở cửa và giá vé được tổng hợp tham khảo (cuối 2026), cột nguồn ghi "cần đối chiếu".
- **Quán ăn**: kiểm tra trên Google Maps còn hoạt động, đúng địa chỉ; quán không chắc thì bỏ dòng đó và thay quán bạn biết.
- **Giờ mở cửa / giá vé** của khu du lịch lớn (Bà Nà, VinWonders, Hòn Thơm, Fansipan): đối chiếu trang chính thức.
- Sau khi đối chiếu, có thể sửa cột `source` thành nguồn cụ thể (ví dụ "Trang chính thức Sun World, 11/2026") và `source_url`.

Nạp lại cùng một tệp không tạo bản sao: dòng trùng được nhận ra và chỉ bổ sung ô còn trống.
