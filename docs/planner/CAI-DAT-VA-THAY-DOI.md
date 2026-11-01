# Cài đặt gói nâng cấp Trip Planner và danh sách thay đổi

## Cài đặt

1. Giải nén `trip-planner-tong-hop.zip` đè lên thư mục gốc dự án; đường dẫn trong gói giữ nguyên cấu trúc dự án.
2. Chạy migration. Các migration đều kiểm tra cột hoặc bảng trước khi thêm, nên chạy lại vẫn an toàn:
   ```bash
   php artisan migrate
   ```
   - `2026_11_14_000001_trip_items_start_time`: `startMin`, `durationMin`
   - `2026_11_15_000001_trip_items_cost_type`: `costType`
   - `2026_11_16_000001_trip_items_locked`: `isLocked`
   - `2026_11_17_000001_opening_hours`: bảng `tbl_opening_hours`
   - `2026_11_18_000001_osm_snapshots`: bảng `tbl_osm_snapshots` (bản chụp OpenStreetMap, đợt 9)
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
   PLANNER_EXTERNAL=on                # off = không gọi dịch vụ ngoài nào (demo không mạng)
   PLANNER_EXTERNAL_DOWN_SECONDS=300  # dịch vụ ngoài lỗi thì nghỉ bao lâu trước khi thử lại
   PLANNER_MAX_TRIPS=200              # số chuyến tối đa mỗi người
   ```
   Nếu đã đặt `PLANNER_ROUTE_PROVIDER=osrm` mà máy không vào được máy chủ OSRM, nên đổi lại `haversine`.
4b. Nạp sẵn dữ liệu khách sạn / cà phê / nhà hàng / bar / chợ quanh 6 điểm đến lõi (cần mạng, khoảng 30 lượt, vài phút). Chạy lại thì chỉ nạp mục còn thiếu; mất mạng 3 lần liên tiếp thì tự dừng:
   ```bash
   php artisan planner:warm-osm                 # điểm đến lõi
   php artisan planner:warm-osm --slug=hoi-an   # thêm một điểm đến khác
   php artisan planner:warm-osm --all           # mọi điểm đến (lâu)
   ```
   Sau đó các nút “Cà phê”, “Khách sạn”… trong trang lập kế hoạch đọc thẳng từ CSDL, không cần mạng lúc demo.
5. Xóa cache cấu hình và view:
   ```bash
   php artisan config:clear && php artisan view:clear
   ```
   Các file JS/CSS đã tăng tham số `?v=` nên trình duyệt tự lấy bản mới.
6. Kiểm tra trước khi demo:
   - `php artisan planner:doctor` (phải báo “đủ bảng / cột”, xem số bản chụp OSM đã nạp);
   - `php artisan planner:scenario` (chạy kịch bản 4 ngày / 2 người / 5 triệu / ẩm thực + văn hóa / không trekking trên 6 điểm đến lõi, báo ngày thưa hoặc quá dày).
7. Chạy kiểm thử (toàn bộ đều qua, 466 bài):
   ```bash
   php artisan test
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

### Đợt 9: sửa treo trang, khớp luồng tự đề xuất với trình soạn

**Sửa lỗi người dùng báo**
- **Bấm “Cà phê”, “Khách sạn” không ra kết quả, rồi cả web treo.** Nguyên nhân: nhóm này lấy dữ liệu từ OpenStreetMap (Overpass). Máy chủ cũ chờ mỗi máy chủ Overpass tới 14 giây, thử lần lượt 3 máy chủ, và mỗi lần bấm lại thử lại từ đầu. `php artisan serve` chỉ xử lý một yêu cầu một lúc, nên trong lúc chờ, trang chủ và mọi trang khác phải xếp hàng. Cách sửa:
  - **Hai lượt**: lượt 1 (`osm=0`) trả ngay phần có sẵn trong CSDL; lượt 2 tải thêm từ OpenStreetMap, giao diện hiện “Đang tải thêm từ OpenStreetMap…”.
  - **Cầu dao `ExternalGuard`** cho Overpass, Photon, OSRM: kết nối tối đa 2–3 giây, cả lượt tối đa 7 giây, lỗi một lần thì nghỉ 5 phút (các lần bấm sau trả ngay). OSRM lỗi cũng không còn bị gọi lại ở mỗi lần tải sức khỏe.
  - **Bản chụp trong CSDL** (`tbl_osm_snapshots`): mỗi lần Overpass trả lời được thì lưu lại, không mất khi xóa cache. `planner:warm-osm` nạp sẵn cho mọi điểm đến.
  - **Dự phòng Photon**: Overpass không trả lời thì hỏi Photon (cũng là dữ liệu OpenStreetMap) theo thẻ loại địa điểm.
  - **Giao diện**: hiện “Đang tìm…” ngay khi bấm; bộ lọc thu gọn để kết quả nằm ngay dưới ô tìm; danh sách rỗng giải thích lý do kèm nút “Mở rộng 10 km” và “Tự thêm”; trình duyệt tự bỏ yêu cầu quá 20 giây.
  - `PLANNER_EXTERNAL=off` để demo hoàn toàn không mạng.
- **“-3,8 tr dự kiến”** thật ra là “~3,8 tr” (dấu ~ nhỏ trông như dấu trừ). Đổi sang “≈ 3,8 tr”. Mục ở khu “Đã lưu” không còn được cộng vào ước tính.

**Khớp luồng tự đề xuất với trình soạn (nhận xét P0)**
- **Giờ mở cửa trong engine**: biết ngày đi thì không xếp vào ngày nơi đó đóng cửa; tới sớm thì chờ; không còn khoảng mở đủ dài thì thử dời xuống cuối ngày, vẫn không được thì đưa vào “Đã lưu” kèm lý do. Trước khi lưu, `PlannerService` kiểm tra lại từng ngày bằng chính `DaySchedule` của TripHealth, nên chuyến vừa tạo không có lỗi cứng về giờ mở cửa.
- **Form có ngày đi, nhịp đi, độ khó, người lớn / trẻ em.** Có ngày đi thì tháng tự theo ngày đi, lễ hội được ghim đúng ngày, tour lọc ±14 ngày. `maxDifficulty`, `pace`, số người được kiểm tra hợp lệ.
- **Món ăn không có tọa độ**: dùng vị trí của quán đã gắn (`tbl_foods.placeId`); chưa gắn quán thì món không có vị trí và **không tạo đoạn đi giả** về tâm điểm đến. Áp dụng cho cả engine lẫn trình soạn. Bữa ăn ưu tiên quán có tọa độ.
- **Ngân sách là ràng buộc cứng** trên phần đã biết giá: bỏ dần mục đắt nhất (mục “phải thử” bỏ sau cùng), thay bằng mục kế tiếp còn vừa; mục bị bỏ có lý do trong “Vì sao không có…”.
- **Người lớn / trẻ em**: giá tour = `priceAdult` × người lớn + `priceChild` × trẻ em; trải nghiệm chưa có giá trẻ em nên tính như người lớn và ghi rõ.
- **Xem trước rồi mới lưu**: màn `/len-ke-hoach/de-xuat/xem-truoc` có “Lưu thành chuyến”, “Đổi nhịp” (Thong thả / Vừa phải / Dày), “Tạo lại với lựa chọn khác”, “Vì sao chọn mục này?” (từ `reasons[]`), “Vì sao không có…?” (từ `filterLog`, nhóm theo lý do), chi phí đã biết và danh sách sẽ vào “Đã lưu”. Engine là hàm thuần nên lưu ra đúng lịch đang xem (có test).
- **`unplaced` không bị bỏ**: mục điểm cao chưa xếp được (hết chỗ, đóng cửa ngày đó, ngày đã đủ dày) được lưu vào khu “Đã lưu”.
- **Độ dày theo cùng thang với Sức khỏe chuyến đi**: mỗi nhịp có `target_load` (thong thả 65%, vừa 80%, dày 95%) và `min_load`. Ngày dày hơn thì bớt mục điểm thấp, thưa hơn thì bù từ phần còn lại. Quán ăn, cà phê trong kho nay được nhận là bữa ăn, khách sạn là lưu trú (cả máy chủ và trình duyệt, có contract test).
- **“Không thích di chuyển xa”** có tác dụng: tối đa 90 phút đi lại mỗi ngày, bỏ nơi cách trung tâm quá 25 km.
- **Khay chuyến đi nhận theo `kind = 'tray'`**, không so theo tên nữa. Một chuyến thật trùng tên khay vẫn hiện bình thường.
- **Giới hạn số chuyến** (`PLANNER_MAX_TRIPS`, mặc định 200) áp dụng cho mọi cách tạo: tạo mới, tự đề xuất, nhân bản, từ tour, từ lịch trình mẫu, API.
- **Điểm đến chưa đủ dữ liệu**: trang đề xuất nói rõ còn thiếu gì, gợi ý điểm đến đã sẵn sàng hợp tháng đi (DestinationFinder), có nút tự lập chuyến.
- Lỗi cũ: lễ hội tự xếp bị mất thời lượng (`durationMin = null`) vì ghép mảng bằng `+`.

**Kiểm thử và vận hành**
- **Toàn bộ `php artisan test` xanh (466 bài).** Sửa các bài vốn hỏng:
  - `PlaceBrowseTest`: đổi tên hàm `get()` thành `browseGet()`;
  - `Week3QualityTest`, `Week10AdminContentFormTest`: PHP Flasher tự đổi thông báo `success` thành thông báo nổi nên test không thấy; khi chạy test tắt bằng `FLASHER_FLASH_BAG=false`, còn môi trường thật giữ nguyên;
  - `HeroPostcardsTest`, `HomeStripsTest`: viết lại theo trang chủ bản v3 (hero “Kể một câu”, tab “Ăn gì”, khối giới thiệu Planner).
- Test mới: cầu dao và bản chụp OSM, Photon dự phòng, xem trước rồi lưu đúng lịch, ngày đi ghim lễ hội, kiểm tra tham số, trẻ em, giờ mở cửa → “Đã lưu” và sức khỏe sạch, điểm đến chưa sẵn sàng, giới hạn chuyến, khay theo `kind`, ngân sách cứng, đi xa, món không tọa độ, độ dày.
- Lệnh mới `planner:scenario` (kịch bản trên mọi điểm đến lõi). `planner:doctor` báo thêm dịch vụ ngoài, bản chụp OSM, dịch vụ đang bị ngắt.
- README có đủ hướng dẫn migrate, seed giờ mở cửa, `planner:hours`, `planner:warm-osm`, biến `.env`.

### Đợt 9b: gõ là ra địa điểm, không phụ thuộc Overpass

- Khi gõ hoặc bấm nhóm, nguồn trực tiếp mặc định là **Photon** `/reverse` (các nơi gần nhất đúng loại, lọc `osm_tag`, khoảng 1 giây). Không có kết quả thì tìm theo từ khóa trong khung quanh tâm; vẫn không có mới thử Overpass. Kết quả lưu vào `tbl_osm_snapshots` nên lần sau mất mạng vẫn có.
- Overpass (chậm, hay quá tải, có lượt trên 50 giây) chỉ còn là nguồn của `planner:warm-osm`. Lệnh này in rõ lý do lỗi (429 / 502 / 504 / quá thời gian), biết chờ khi bị giới hạn, và có thêm `--photon` để nạp nhanh.
- `PLANNER_OSM_LIVE=overpass` để quay lại cách cũ.

### Đợt 10: đề xuất mở rộng cho mọi nơi, thiết kế lại các trang luồng

**Đề xuất mở rộng (mọi điểm đến, mọi nơi trên bản đồ)**
- Điểm đến chưa đủ nội dung đã duyệt **không còn bị khóa**. Travela dùng nội dung đã duyệt (nếu có), rồi bổ sung điểm tham quan, nhà hàng, quán cà phê từ OpenStreetMap quanh trung tâm: tham quan trong 8 km, ăn uống trong 4 km, lấy qua cache, bản chụp CSDL, Photon rồi Overpass. Lịch vẫn đi qua đủ các bước: ràng buộc, độ dày, giờ mở cửa (đọc `opening_hours` nếu nguồn có), kiểm tra bằng DaySchedule.
- Gõ **nơi bất kỳ** (ví dụ "Hà Giang") vào ô "Bạn muốn đi đâu?": hệ thống tìm trên bản đồ và xếp lịch quanh tọa độ đó, không cần có trong `tbl_destinations`.
- Mọi mục từ bản đồ được **gắn nhãn "Bản đồ"** ở màn xem trước. Khi lưu, chúng thành mục "từ bản đồ" (`note` + tọa độ, `source = search`), trang sửa ghi "Ngoài dữ liệu Travela".
- `mode = curated` (dòng lệnh `planner:generate` mặc định) vẫn giữ quy tắc cũ: chỉ nội dung đã duyệt. Tắt hẳn đề xuất mở rộng: `PLANNER_OPEN_MODE=false`.
- API mới `GET /len-ke-hoach/tim-noi?q=`: điểm đến Travela khớp tên (gõ không dấu vẫn khớp) và nơi trên bản đồ.

**Thiết kế lại (`planner-flow.css`, `planner-suggest.js`, `planner-preview.js`)**
- **Trang đề xuất**: bốn bước có đánh số (đi đâu, khi nào, ai đi, phong cách), cùng tóm tắt "Chuyến của bạn" dính bên phải.
  - Ô chọn nơi đến: gợi ý tức thì, phím mũi tên / Enter, nhãn "Đủ dữ liệu" / "Mở rộng".
  - Thẻ điểm đến nổi bật có ảnh.
  - Nút +/− cho số ngày, người lớn, trẻ em.
  - Chọn tháng bằng nút; ngân sách chọn nhanh và hiện "khoảng … mỗi người mỗi ngày".
  - Sở thích / không thích dạng nút có biểu tượng.
  - Báo lỗi ngay trên trang; điện thoại có thanh "Xem trước" cố định ở cuối.
- **Màn xem trước**:
  - Khung cảnh báo khi đề xuất mở rộng; nhãn nguồn từng mục (Bản đồ / Travela).
  - Thanh độ dày từng ngày (cùng công thức Sức khỏe chuyến đi).
  - **Bản đồ** đánh số theo ngày, bật/tắt từng ngày; rê chuột vào mục thì sáng ghim.
- **Chọn điểm đến (Chưa biết đi đâu?)**: chọn tháng và cảm hứng bằng nút có biểu tượng; kết quả là thẻ có ảnh, hạng, lý do, nhãn sẵn sàng và nút "Đề xuất mở rộng".
- **Trang sửa**:
  - Khu "Đã lưu" có hơn 3 mục thì thu gọn còn 2 mục, kèm nút "Hiện tất cả N mục", để các ngày không bị đẩy xuống quá xa (vẫn kéo thả vào được).
  - Mục từ bản đồ ghi "Từ bản đồ".
  - Thông báo sau khi tạo không còn bị thẻ đầu trang che.

## Thay đổi hành vi cần biết

- **Đợt 10:** trang "Để Travela đề xuất" không còn khóa điểm đến chưa sẵn sàng. Nhóm địa điểm `kind_groups` thêm `monument`, `memorial`, `ruins`, `temple`, `park`, `waterfall`, `fast_food`...


- **Đợt 9:**
  - Tự đề xuất không lưu ngay nữa mà qua màn xem trước. Route `POST /len-ke-hoach/tao-tu-dong` vẫn giữ để lưu.
  - Số hoạt động mỗi ngày nay do độ dày quyết định. Ví dụ hai mục dài ba giờ đã đủ một ngày “vừa phải”.
  - Món ăn chưa gắn quán không còn lấy tâm điểm đến làm vị trí.
  - Gọi dịch vụ ngoài bị lỗi thì nghỉ 5 phút mới thử lại.


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

## Kiểm thử

Toàn bộ `php artisan test` đều qua (483 bài, gồm cả các bài ngoài Planner vốn hỏng trước đó).
