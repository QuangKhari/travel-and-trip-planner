# Cài đặt gói nâng cấp Trip Planner và danh sách thay đổi

## Cài đặt

1. Giải nén `trip-planner-tong-hop.zip` đè lên thư mục gốc dự án; đường dẫn trong gói giữ nguyên cấu trúc dự án.
2. Chạy migration. Cả 4 migration đều kiểm tra cột hoặc bảng trước khi thêm, nên chạy lại vẫn an toàn:
   ```bash
   php artisan migrate
   ```
   - `2026_11_14_000001_trip_items_start_time`: `startMin`, `durationMin`
   - `2026_11_15_000001_trip_items_cost_type`: `costType`
   - `2026_11_16_000001_trip_items_locked`: `isLocked`
   - `2026_11_17_000001_opening_hours`: bảng `tbl_opening_hours`
3. Nạp giờ mở cửa mẫu (không bắt buộc, dùng khi demo). Seeder tìm địa điểm theo TÊN và in ra đã nạp cho mục nào:
   ```bash
   php artisan db:seed --class=OpeningHoursDemoSeeder
   ```
   Nhập tay cho một địa điểm: mã `placeId` khác nhau giữa các CSDL, nên tìm mã theo tên trước rồi mới ghi.
   PowerShell không hiểu dấu ``` nên chỉ chép từng dòng lệnh.
   ```bash
   php artisan planner:hours place "Dân tộc học"                      # tìm theo tên -> bảng Mã / Tên
   php artisan planner:hours place <mã> "Tu-Su 08:30-17:30; Mo off"   # ghi giờ
   php artisan planner:hours place <mã> --show                         # xem
   php artisan planner:hours place <mã> --clear                        # xóa (ghi nhầm mục)
   ```
4. Tùy chọn trong `.env`:
   ```
   PLANNER_ROUTE_PROVIDER=haversine   # hoặc osrm
   PLANNER_OSRM_URL=                  # ví dụ https://router.project-osrm.org
   PLANNER_TRAVEL_MODE=driving        # driving | motorbike | walking
   ```
5. Xóa cache cấu hình và view:
   ```bash
   php artisan config:clear && php artisan view:clear
   ```
   Các file JS/CSS đã tăng tham số `?v=` nên trình duyệt tự lấy bản mới.
6. Kiểm tra CSDL trước khi demo: `php artisan planner:doctor` (phải báo “đủ bảng / cột”).
7. Chạy kiểm thử của Planner:
   ```bash
   php artisan test --filter "Planner|Week"
   ```
   Phần contract test cần Node.js.

## Thay đổi theo đợt

### Đợt 1: Timeline + bản đồ
- Trang sửa gồm cột timeline và cột bản đồ.
- Mỗi mục có giờ, có đoạn đi giữa hai mục, mỗi ngày có tóm tắt và thanh độ dày.
- Cảnh báo hiện ngay trong từng ngày.
- Đầu trang mới, có trạng thái tự lưu.

### Đợt 2: bố cục kiểu Wanderlog, quanh tuyến, danh sách chuyến, ngân sách, nhân bản
- Thanh bên gồm Tổng quan, “Đã lưu”, từng ngày, Ngân sách, Thông tin.
- Ngăn “Thêm vào chuyến đi” có tab Quanh tuyến (kiểu Roadtrippers).
- Trang “Chuyến đi của tôi” dạng thẻ, có bộ lọc theo giai đoạn.
- Tạo chuyến qua 3 bước.
- Ngân sách tách dự kiến và thực chi; nhân bản chuyến.

### Đợt 3: lộ trình 10 giai đoạn
- Mục có 3 trạng thái FIXED / LOCKED / FLEXIBLE; hiện thời gian trống; kéo thả có kiểm tra ràng buộc.
- `RouteService` với Haversine và OSRM; tối ưu tuyến ở máy chủ, có xem trước.
- Tự cân bằng có xem trước; TripHealth với 5 nhóm ràng buộc và điểm số.
- Luồng Khám phá → Lưu → “Đã lưu” → kéo vào ngày; bản đồ có chế độ Khám phá.
- Ngân sách so với trung bình các ngày; phương án mưa ngay tại từng hoạt động.

### Đợt 4: hợp nhất Planner Domain (P0)
- `PlannerRules` là nguồn duy nhất cho giờ trong ngày, buổi, ngưỡng, mẫu nhận diện loại mục và tham số tuyến. Đã xoá các khóa cấu hình trùng nghĩa.
- Mọi phép tính tuyến (payload, PlannerEngine, quanh tuyến) đều qua `RouteService`.
- Sửa lỗi `osrmUrl` đọc sai khóa cấu hình. Thêm phương tiện (ô tô / xe máy / đi bộ).
- `planner-schedule.js` có cùng hợp đồng với `DaySchedule`; thêm `PlannerContractTest` để so hai bên.
- Quanh tuyến hiện “+X phút” kèm mức đáng ghé.

### Đợt 5: hoàn thiện
- **Giờ mở cửa**:
  - bảng dữ liệu, bộ đọc cú pháp OSM, lệnh `planner:hours`, dữ liệu mẫu;
  - xếp giờ tự chờ tới giờ mở cửa; cảnh báo đóng cửa / tới sớm / quá giờ, có nút xử lý;
  - `OpeningHoursConstraint` trong TripHealth.
- **Resolution Engine**: `TripResolver` và API `/suc-khoe/xu-ly`; giao diện “Cách xử lý đề xuất” cho xem trước độ dày trước/sau, áp dụng rồi hoàn tác được.
- **Read model riêng**: `TripPayloadBuilder` tách khỏi `TripPlanner` (từ khoảng 1.150 dòng xuống 964).
- **Lớp gọi máy chủ riêng**: `planner-api.js`.
- Tài liệu kiến trúc: `docs/planner/KIEN-TRUC-PLANNER.md`.

### Đợt 6: ổn định logic và chẩn đoán
- **Đề xuất xử lý:**
  - Mỗi phương án được thử trên bản sao của cả chuyến bằng chính TripHealth, có điểm và tóm tắt sau khi áp dụng.
  - Chỉ báo “giải quyết được” khi vấn đề hết và không sinh lỗi cứng mới; có liệt kê vấn đề mới phát sinh.
  - Lý do ghi cụ thể (ngày nhận còn bao nhiêu phút trống, cách bao xa); ảnh hưởng ghi theo phút và km.
  - Nơi đóng cửa không còn phương án “bỏ giờ cố định”, thay bằng chuyển ngày hoặc đưa về “Đã lưu”.
- **Ràng buộc cứng và mềm:**
  - Trùng giờ, không kịp đi và giờ mở cửa là **lỗi**.
  - Ngày quá tải, vượt ngân sách, đi lại nhiều là **cảnh báo**.
  - Mục còn trong “Đã lưu” là **thông tin**.
- **Sức khỏe chuyến đi:**
  - Đưa câu chẩn đoán lên đầu và có các nút “Nên làm” mở thẳng cách xử lý; điểm số chỉ còn là phụ.
  - Mỗi ngày có nhãn Nhẹ / Vừa phải / Bận / Quá tải.
- **OSRM:**
  - Ghi rõ nguồn số liệu tuyến (`requested` / `actual` / `fallback`); khi OSRM lỗi, giao diện nói rõ đang dùng ước tính.
  - Kết quả lỗi không còn bị lưu cache cả ngày.
  - Tối ưu tuyến trả về `route`.
- **Tự lưu:** khi lưu lỗi, nút đổi thành “Thử lại”.
- **Kiểm thử:** thêm `PlannerInvariantTest` gồm 8 bất biến nghiệp vụ.

### Đợt 7: hoàn thiện phần còn lại
- **Thay bằng nơi tương tự** cho địa điểm đóng cửa / sai giờ: cùng nhóm loại, trong 5 km, không đóng cửa hôm đó, chưa có trong chuyến. Mục cũ về “Đã lưu”.
- **Phương án mưa có xem trước:**
  - giữ lịch và lưu 3 nơi trong nhà làm dự phòng;
  - hoặc đổi hẳn từng hoạt động ngoài trời sang nơi trong nhà gần nhất.
  - Hai cách đều xem trước rồi mới áp dụng, và hoàn tác được.
- **Tìm kiếm:** lọc theo loại, kèm lối tắt “Gần tuyến”.
- **Thanh công cụ:** thao tác ít dùng vào menu ⋮; thêm “Xóa chuyến” cho chủ chuyến.
- **Điện thoại:** bảng trượt lịch ngày dưới bản đồ toàn màn hình; chạm ghim ở timeline thì mở bản đồ.
- **Tách `planner.js`:**
  - thêm `planner-save.js` (hợp đồng lưu) và `planner-resolve.js` (áp dụng phương án);
  - test “xem trước = áp dụng” chạy chính mã JS này bằng Node.
- **Sửa lỗi:** trạng thái của khung đề xuất xử lý dùng chung biến với kết quả tìm kiếm (`ui.res`), có thể làm mất kết quả tìm. Đã tách riêng thành `ui.rsv`.

### Đợt 8: chốt dự án (feature freeze)

**A. Đúng đắn**
- Lệnh `php artisan planner:doctor`: kiểm tra đủ bảng / cột / migration của Planner, in nguồn tuyến đang dùng; `--osrm` để thử gọi OSRM.
- `PlannerAcceptanceTest`:
  - giải quyết A nhưng phát sinh B mềm → vẫn “giải quyết được” và liệt kê B;
  - giải quyết A nhưng tạo lỗi cứng mới → **không** giải quyết được;
  - mục có buổi không bị sắp tuyến đổi chỗ;
  - người chỉ xem không gọi được đề xuất;
  - API có `rev` và trả 409;
  - CSDL đủ.
- API `PUT /api/v1/trips/{id}` nhận `rev`, trả 409 khi xung đột (giống trang web); GET / PUT trả `rev`.
- **Sửa lỗi “Lên kế hoạch quanh tour”** (lỗi 500): chèn nhiều mục một lần với số cột khác nhau.
- Người lạ lưu chuyến của người khác nay nhận **404**, không còn 403, để không lộ chuyến có tồn tại; người được mời chỉ xem vẫn nhận 403.
- **5 bài test Planner vốn hỏng từ trước nay đều qua.** Ba bài dựa trên giả định đã cũ, nên mình sửa test:
  - chữ “Khám phá” có trong menu chung của website, nên kiểm tra không còn tab riêng thay vì không có chữ;
  - `Http::fake` gọi hai lần thì stub đầu vẫn thắng, nên dùng một stub đổi được trạng thái;
  - người lạ lưu nhận 404.

**B. Trải nghiệm**
- **Đoạn nối di chuyển**: “🚗 ≈ 12 phút · 4,2 km · *ước tính*”. Chỉ ghi “đường thực tế” khi máy chủ xác nhận đã dùng OSRM cho bản đã lưu.
- **Tóm tắt ngày dạng chip**: giờ · số điểm · hoạt động · di chuyển (ước tính) · km · chi phí · mức độ.
- **Thời lượng chưa nhập** hiện “≈ 1 giờ”, in nghiêng, kèm giải thích.
- **Chi phí** ghi nguồn ngay cạnh số tiền: *dự kiến* / *ước tính* / *đã chi*.
- **INFO mới**:
  - N địa điểm chưa có giờ mở cửa;
  - N mục dùng thời lượng ước tính;
  - OSRM đang dùng ước tính.
- **Quanh tuyến**: “Cách tuyến 0,4 km · ước tính đi vòng” cạnh “+6 phút · +1,2 km · Đáng ghé”.
- **Tối ưu tuyến**: so sánh hai cột *Hiện tại* / *Đề xuất* trước khi áp dụng.
- **Tự thêm**: ba nút “+ Địa điểm của tôi”, “+ Di chuyển”, “+ Ghi chú”, không gọi chung là mục tùy chỉnh.
- **Phương án mưa** đổi tên thành “☂ Dự phòng khi mưa”, nói rõ lịch không đổi và người dùng quyết định.
- **“Đã lưu”** luôn hiện, kể cả khi rỗng, có hướng dẫn và chỗ để kéo mục ra khỏi ngày.

**C. Hoàn thiện**
- Trạng thái “đang kiểm tra…” khi tải sức khỏe; lỗi tải thì vẫn dùng kiểm tra ở trình duyệt.
- Bản in / PDF giữ tiêu đề và tóm tắt ngày, bỏ khung cảnh báo tương tác và nút nổi.
- Thẻ mục không còn bị bóp tên khi có nhiều nút.
- Kiểm tra tự động trên luồng chính: không lỗi JS, không yêu cầu HTTP ≥ 400, không nút thiếu nhãn, không ảnh thiếu `alt`.

**D. Tài liệu**: `KIEN-TRUC-PLANNER.md` viết lại theo 10 mục kèm 5 phụ lục, có ma trận kiểm thử.

## Thay đổi hành vi cần biết

- **Mức của từng vấn đề (đợt 6):**
  - “Ngày quá dày” và “vượt ngân sách” trước là đỏ (`bad`), nay là cảnh báo (`warn`).
  - “Không kịp di chuyển” và “chưa mở / quá giờ mở cửa” trước là cảnh báo, nay là lỗi.
  - Vì vậy điểm số của cùng một chuyến có thể khác trước.

- **Tự tạo lịch (PlannerEngine)**:
  - Quỹ hoạt động mỗi ngày nay là 360 phút ở nhịp vừa (trước là 480 phút, chưa trừ bữa ăn), nên lịch tự tạo không còn bị chấm là quá dày.
  - Hệ số nhịp “thong thả” đổi 0,7 → 0,8.
  - `startMin` trong kết quả tính theo phút trong ngày (480 = 08:00), cùng thang với trang sửa.
- **Ngưỡng cảnh báo khi lưu**: thống nhất với trang sửa, gồm hơn 7 mục một ngày và đi lại quá 180 phút một ngày. Trước đây khi lưu dùng 6 mục và 300 phút.
- **Phân buổi**: 11:00–11:30 nay là buổi sáng ở mọi nơi. Trước đây trình duyệt tính là buổi trưa.

## Kiểm thử còn hỏng có sẵn từ trước

Toàn bộ test của Planner đều qua (đợt 8 đã sửa 5 bài vốn hỏng). Các bài dưới đây hỏng cả trên mã gốc và nằm **ngoài** Planner:
- `Week3QualityTest`, `Week10AdminContentFormTest`: trang quản trị nội dung.
- `HeroPostcardsTest`, `HomeStripsTest`: trang chủ.
- `PlaceBrowseTest` không nạp được vì phương thức `get()` không khai báo public.
