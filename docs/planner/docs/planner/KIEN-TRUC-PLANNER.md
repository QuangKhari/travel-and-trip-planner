# Kiến trúc Trip Planner (Travela 2.0)

Tài liệu chốt (feature freeze) của chức năng lập kế hoạch chuyến đi. Mô tả kiến trúc, dữ liệu, thuật toán, quy tắc nghiệp vụ, giới hạn và hướng phát triển. Dùng làm nguồn cho chương thiết kế của đồ án.

Mục lục:
1. Kiến trúc
2. Mô hình dữ liệu
3. Thuật toán xếp lịch
4. Thuật toán tuyến đường
5. Ràng buộc và sức khỏe chuyến đi
6. Resolution Engine
7. Tối ưu tuyến
8. Cộng tác
9. Giới hạn
10. Hướng phát triển

Phụ lục: A. API · B. Cấu hình · C. Quy ước giao diện · D. Ma trận kiểm thử · E. Vận hành

---

## 1. Kiến trúc

### 1.1 Vai trò trong Travela

Travela là nền tảng đặt tour. Trip Planner là phần **điều phối hành trình**: người dùng gom điểm đến, địa điểm, món ăn, trải nghiệm, lễ hội và tour thành một lịch theo ngày, rồi được hệ thống giúp làm lịch đó hợp lý. Planner không đặt chỗ, không thanh toán, không dẫn đường.

Người dùng đi qua ba bước:

```
KHÁM PHÁ (Explore)       →   LẬP LỊCH (Plan)                    →   TỐI ƯU (Optimize)
tìm theo tên / loại           kéo thả, đổi giờ, đổi thời lượng       Sức khỏe chuyến đi: vấn đề gì
quanh tuyến (+phút đi vòng)   khóa vị trí, chọn buổi                  → Cách xử lý đề xuất
lưu vào “Đã lưu”              timeline: hoạt động → di chuyển         → Xem trước → Áp dụng → Tự lưu
```

### 1.2 Nguyên tắc: một nghiệp vụ, một nguồn

| Khái niệm | Nguồn duy nhất | Nơi dùng |
|---|---|---|
| Giờ trong ngày, sức chứa, đệm, bữa ăn chừa sẵn, ngưỡng | `config/planner.php` → `PlannerRules` | DaySchedule, TripHealth, PlannerEngine, kiểm tra lịch mẫu (admin), trình duyệt (`cfg.rules`) |
| Buổi trong ngày | `slots` → `PlannerRules::slots()` / `slotOf()` | máy chủ và `planner-schedule.js` |
| Nhận diện lưu trú / ăn uống của mục tự thêm | `kind_patterns` | máy chủ và `planner-schedule.js` |
| Quãng đường / thời gian đi | `RouteService` (Haversine / OSRM, theo phương tiện) | read model, TripHealth, tối ưu, quanh tuyến, PlannerEngine; trình duyệt xem trước bằng `planner-geo.js` với cùng tham số |
| Giờ mở cửa | `tbl_opening_hours` → `OpeningHours` | DaySchedule, OpeningHoursConstraint, TripResolver, trình duyệt |

Dữ liệu suy ra (tuyến, độ dày, cảnh báo, điểm) **không lưu vào CSDL**: luôn tính lại từ các mục đã lưu.

### 1.3 Sơ đồ module

```
                         Trình duyệt (trang /len-ke-hoach/{id})
  planner.js (giao diện, trạng thái, hoàn tác / làm lại, tự lưu)
     ├── planner-schedule.js  TpSchedule  xếp giờ / độ dày / buổi / giờ mở cửa   (xem trước tức thì)
     ├── planner-geo.js       TpGeo       đoạn đường, tối ưu cục bộ                (xem trước tức thì)
     ├── planner-api.js       TpApi       mọi yêu cầu tới máy chủ
     ├── planner-save.js      TpSave      hợp đồng lưu (/luu)
     ├── planner-resolve.js   TpResolve   áp dụng phương án của TripResolver (cùng ngữ nghĩa máy chủ)
     └── planner-map.js                   Leaflet / MapLibre / Google
                │  cfg.rules = PlannerRules::client()
                ▼
                              Máy chủ (Laravel)
  PlannerController ─► TripPlanner          CRUD / lưu (rev, 409) / nhân bản / quyền
                   ├─► TripPayloadBuilder   read model: mục + lễ hội + insights + giờ mở cửa + tuyến từng ngày + cảnh báo
                   ├─► TripHealth           chẩn đoán: 6 nhóm ràng buộc trên DaySchedule
                   ├─► TripResolver         giải pháp: phương án + mô phỏng + xem trước (không ghi CSDL)
                   ├─► RouteService         estimate / leg / travelTime / detour / optimize + nguồn số liệu
                   │       └─ HaversineProvider | OsrmProvider (lỗi → Haversine, ghi rõ fallback)
                   ├─► RouteExplorer        quanh tuyến
                   ├─► OpeningHours         quy tắc giờ mở cửa
                   └─► PlannerEngine        tự tạo lịch (heuristic: lọc cứng → chấm điểm → chia ngày → timeline)
```

Trách nhiệm từng lớp:

| Lớp | Trách nhiệm |
|---|---|
| TripPlanner | tạo / lưu / nhân bản / xóa, quyền |
| TripPayloadBuilder | dữ liệu đọc (read model) |
| DaySchedule | tính lịch một ngày |
| RouteService | tuyến đường |
| TripHealth | chẩn đoán |
| TripResolver | giải pháp |
| OpeningHours | quy tắc giờ mở cửa |
| PlannerEngine | tự tạo lịch |

---

## 2. Mô hình dữ liệu

```
tbl_trips 1 ──── n tbl_trip_items ──(refType, refId)──► destination | place | food | experience | event | tour
    │                                                   (note, transport: mục tự thêm, refId = null, có label + tọa độ riêng)
    ├── n tbl_trip_members (userId, role: view | edit)
    └── rainPlan (JSON: phương án mưa theo ngày)

tbl_opening_hours (refType, refId, dayOfWeek, openMin, closeMin, isClosed)   ← place | experience
tbl_content_requests                                                          ← nơi người dùng tìm mà kho chưa có
```

| Bảng | Cột chính | Ghi chú |
|---|---|---|
| `tbl_trips` | `title, startDate, days, groupSize, budget, status, destinationId, shareToken, source, rainPlan, rev, lastEditorId` | `rev` dùng cho khóa lạc quan |
| `tbl_trip_items` | `refType, refId, dayNo, orderNo, note, cost, costType, slot, label, reasons, durationMin, startMin, isLocked, latitude, longitude, source` | `dayNo = null`: nằm trong “Đã lưu” |
| `tbl_trip_members` | `tripId, userId, role, invitedBy` | `role`: view / edit |
| `tbl_opening_hours` | `refType, refId, dayOfWeek (1–7), openMin, closeMin, isClosed` | nhiều dòng một ngày = nhiều ca |

Giải thích các cột của `tbl_trip_items`:
- **`refType` / `refId`**: liên kết đa hình tới nội dung có sẵn của Travela, không có bảng địa điểm riêng cho Planner.
- **`note` / `transport`**: là “mục tự thêm”; giao diện gọi rõ là *Địa điểm của tôi*, *Di chuyển*, *Ghi chú*.
- **`startMin` / `slot` / `isLocked`**: ba ràng buộc khác nhau, **không gộp**:

| Trạng thái | Trường | Ý nghĩa |
|---|---|---|
| FIXED | `startMin` | giờ bắt đầu cố định |
| SLOT | `slot` | không bắt đầu trước đầu buổi |
| LOCKED | `isLocked` | tối ưu / cân bằng không được di chuyển |
| FLEXIBLE | (không có cả ba, có vị trí) | được tối ưu / cân bằng đổi chỗ |

- **`cost` + `costType`**: `planned` (dự kiến, người dùng nhập), `estimate` (ước tính từ bảng giá tham khảo), `actual` (đã chi). Giao diện luôn ghi nguồn bên cạnh số tiền.

Kiểm tra CSDL trước khi demo: `php artisan planner:doctor` (xem phụ lục E).

---

## 3. Thuật toán xếp lịch (DaySchedule ≡ TpSchedule)

Hai bản cài đặt (PHP ở máy chủ, JS ở trình duyệt) phải ra cùng kết quả. `PlannerContractTest` chạy JS bằng Node và so từng dòng.

```
clock = day.start (08:00)
for mục trong ngày (theo thứ tự):
    if FIXED:  start = startMin
               start < kết thúc mục trước − 5'                → trùng giờ (overlap)
               start < kết thúc mục trước + đi lại − 5'       → không kịp di chuyển (travel)
    else:      start = làm tròn 5' (clock)
               SLOT: start = max(start, đầu buổi)
               biết giờ mở cửa: start = khoảng mở đầu tiên còn đủ thời lượng (chờ tới giờ mở)
    dur  = durationMin | lưu trú tự thêm 30' | mặc định theo loại (ƯỚC TÍNH)
    hours = closed | early | late | null  (chưa biết giờ ≠ đang mở: không kết luận)
    chờ ≥ 15' trước mục → thời gian trống
    clock = start + dur + đi lại tới mục có vị trí kế tiếp (RouteService)

đã dùng = hoạt động + di chuyển + bữa ăn chừa sẵn (60' nếu ngày chưa có mục ăn uống) + đệm 10%
độ dày  = đã dùng / sức chứa (600' = 08:00–18:00)
mức     = Nhẹ < 50% · Vừa phải < 85% · Bận < 100% · Quá tải
```

Vì sao sức chứa là 08:00–18:00 chứ không phải 08:00–20:00: bữa tối và dạo phố buổi tối vẫn được xếp sau 18:00 và vẫn được tính vào độ dày. Nếu nới khung lên 12 tiếng, một ngày đã quá tải 112% sẽ bị chấm là bình thường.

---

## 4. Thuật toán tuyến đường (RouteService)

### 4.1 Nguồn số liệu

| Nguồn | Cách tính | Khi nào |
|---|---|---|
| **Haversine** (mặc định) | đường chim bay × 1,3; tốc độ theo phương tiện (ô tô 40, xe máy 30, đi bộ 4,5 km/h) | không cần mạng; kết quả ổn định |
| **OSRM** | bảng khoảng cách / thời gian theo đường bộ thật, cache 1 ngày | `PLANNER_ROUTE_PROVIDER=osrm` + `PLANNER_OSRM_URL` |

Quy tắc chung:
- Hai điểm gần hơn `min_leg_km` (0,5 km chim bay) coi như cùng khu, không tính đoạn đi.
- OSRM lỗi thì dùng Haversine, và kết quả trả kèm `route = {requested, actual, fallback, reason, mode}`. Kết quả lỗi **không** được cache.

Trên giao diện, số liệu tuyến phân biệt rõ hai loại:
- **“đường thực tế”**: chỉ khi máy chủ xác nhận đã dùng OSRM cho bản đã lưu;
- **“ước tính”**: mọi trường hợp khác, có dấu ≈ trên đoạn nối và chữ “(ước tính)” trong tóm tắt ngày.

Khi OSRM phải dùng ước tính thay, mục Sức khỏe có thêm một dòng INFO nói rõ.

### 4.2 Quanh tuyến (RouteExplorer)

- Lọc ứng viên trong khung bao của tuyến ngày.
- Với mỗi ứng viên, tính:
  - `offKm`: cách tuyến bao xa;
  - `detourKm = d(A,P) + d(P,B) − d(A,B)` × hệ số;
  - `detourMin`: phút đi thêm, theo tốc độ phương tiện;
  - `detourLevel`:

| Mức | Phút đi thêm |
|---|---|
| gần như không đi vòng | ≤ 5′ |
| đáng ghé | ≤ 15′ |
| cân nhắc | ≤ 30′ |
| vòng xa | > 30′ |

- Phần này **luôn là ước tính**. Giao diện ghi “Cách tuyến … · ước tính đi vòng”. Lý do: với hàng trăm ứng viên, mỗi ứng viên cần 3 lần gọi OSRM, quá tốn (xem mục 10).

---

## 5. Ràng buộc và sức khỏe chuyến đi (TripHealth)

| Nhóm | Mã | Loại | Mức |
|---|---|---|---|
| Thời gian | `time.overlap`, `time.travel` | **cứng** | LỖI |
| Giờ mở cửa | `hours.closed`, `hours.early`, `hours.late` | **cứng** | LỖI |
| Thời gian | `time.late` (kết thúc sau 22:00) | mềm | CẢNH BÁO |
| Sức chứa | `capacity.overload`, `capacity.tight`, `capacity.many` | mềm | CẢNH BÁO |
| Tuyến | `route.long_day`, `route.long_leg` | mềm | CẢNH BÁO |
| Ngân sách | `budget.over` | mềm | CẢNH BÁO |
| Bối cảnh | `context.rain`, `context.season` | mềm | CẢNH BÁO / INFO |
| Thông tin | `hours.unknown`, `capacity.estimated_duration`, `capacity.unassigned`, `route.no_position`, `route.fallback`, `budget.day_high` | — | INFO |

- **Ràng buộc cứng**: lịch không thực hiện được như đã đặt.
- **Ràng buộc mềm**: vẫn đi được, nên xem lại.
- Không loại nào chặn việc lưu, vì tự lưu không được làm mất dữ liệu đang sửa.

Kết quả trả về ưu tiên **chẩn đoán + hành động**:
- `summary.label`: ví dụ “Cần sửa 1 lỗi lịch, 3 điểm nên xem lại”;
- `recommended`: tối đa 3 nút, ví dụ “Cân bằng ngày 1”, “Sửa trùng giờ ngày 2”;
- `days[].loadLabel`;
- `score` chỉ là thông tin phụ: 100 − 15 × lỗi − 5 × cảnh báo.

---

## 6. Resolution Engine (TripResolver)

```
vấn đề (code, ngày, vị trí)
   → các phương án ứng viên
   → mô phỏng trên bản sao CẢ chuyến
   → chạy lại TripHealth
   → effects / after / resolves / newIssues
   → trình duyệt: Xem trước → Áp dụng (planner-resolve.js) → tự lưu → chấm lại
```

| Thao tác (`changes`) | Ngữ nghĩa (giống hệt ở máy chủ và trình duyệt) |
|---|---|
| `move` | bỏ giờ cố định, đưa xuống cuối ngày nhận (`toDay = null` → “Đã lưu”) |
| `time` | đặt / bỏ giờ cố định |
| `reorder` | sắp lại thứ tự trong ngày |
| `replace` | thay bằng nơi tương tự, giữ giờ + buổi; mục cũ về “Đã lưu” |
| `rainplan` | lưu phương án dự phòng khi mưa cho ngày (lịch không đổi) |

| Vấn đề | Phương án |
|---|---|
| quá tải / khá kín / kết thúc muộn | cân bằng nhiều bước tới dưới 90%; chuyển mục dài nhất sang ngày hợp (còn chỗ, mở cửa, gần); đưa về “Đã lưu” |
| trùng giờ / không kịp đi | dời sang giờ sớm nhất kịp tới; bỏ giờ cố định |
| giờ mở cửa | dời vào giờ mở; bỏ giờ cố định (**không** áp dụng khi đóng cả ngày); chuyển sang ngày mở cửa; thay bằng nơi tương tự (cùng nhóm, ≤ 5 km, không đóng cửa hôm đó); đưa về “Đã lưu” |
| đi lại nhiều | sắp lại thứ tự |
| mưa | giữ lịch và lưu 3 nơi trong nhà làm **dự phòng**; hoặc đổi hẳn sang trong nhà (người dùng quyết định) |

Bất biến (khóa bằng test):
- **`resolves = true`** ⇔ vấn đề gốc biến mất **và** không sinh lỗi cứng mới.
  - Phát sinh vấn đề mềm thì vẫn ghi là giải quyết được, nhưng vấn đề đó được liệt kê trong `newIssues`.
- **Xem trước = áp dụng**:
  - `PlannerInvariantTest` chạy chính `planner-resolve.js` + `planner-save.js` bằng Node, lưu thật, rồi so sức khỏe máy chủ với `after` / `effects`.
  - Kiểm tra cho cả 6 loại thao tác: cân bằng, chuyển, giờ, sắp lại, thay thế, phương án mưa.
- **Không bao giờ di chuyển** mục FIXED / LOCKED / SLOT / lưu trú / ăn uống / mục đầu ngày khi cân bằng hay sắp tuyến.
- Đề xuất **không ghi CSDL**. Người chỉ có quyền xem không gọi được (404).

---

## 7. Tối ưu tuyến

- **Thuật toán**:
  1. Láng giềng gần nhất trên các chỗ “tự do”, điểm neo giữ nguyên chỗ.
  2. Cải thiện bằng đổi chỗ / đảo đoạn (2-opt) cho tới khi không còn tốt hơn.
  3. Điểm không có tọa độ giữ nguyên vị trí.
- **Không tự áp dụng**:

```
Tối ưu → Hiện tại: A → B → C → D   |   Đề xuất: A → C → B → D
         ~8,7 km → ~7,7 km, tiết kiệm ~1 km · 2 phút
         [Áp dụng]  [Giữ như cũ]
```

- `POST /len-ke-hoach/{id}/toi-uu` chỉ trả đề xuất kèm `route` (nguồn số liệu); không ghi gì vào chuyến.

---

## 8. Cộng tác

- **Quyền**:
  - Chủ chuyến có toàn quyền.
  - Người được mời có quyền `edit` (sửa, lưu, xem đề xuất) hoặc `view` (chỉ xem).
  - Người không liên quan nhận **404** cho mọi API, kể cả lưu, để không lộ chuyến có tồn tại; người chỉ xem cố lưu thì nhận 403.
- **Khóa lạc quan**:
  - Mỗi lần lưu tăng `rev`.
  - Lưu kèm `rev` cũ hơn bản trên máy chủ → **409** `{conflict, rev, by}`, không ghi đè.
  - Áp dụng cho cả trang web (`/luu`) lẫn API (`PUT /api/v1/trips/{id}`).
- **Tự lưu**:
  - Trạng thái hiển thị: Chưa lưu → Đang lưu… → Đã lưu HH:MM; lỗi thì có nút **Thử lại**.
  - Hoàn tác / làm lại tới 60 bước.
- **Lưu cả chuyến** (xóa rồi chèn lại các mục trong một giao dịch): đủ cho tối đa 80 mục.

---

## 9. Giới hạn (có chủ đích)

- **Giờ mở cửa**: chỉ có lịch theo tuần.
  - Chưa xử lý giờ qua nửa đêm (18:00–02:00 chỉ tính tới 24:00), ngày lễ, giờ theo mùa, đóng cửa tạm thời.
  - Thiếu dữ liệu thì **không** coi là đang mở, chỉ báo INFO “chưa kiểm tra được”.
- **Quanh tuyến** luôn là ước tính. Khi bật OSRM, trình duyệt vẫn xem trước bằng ước tính; con số chính thức là của máy chủ.
- **Thời lượng / giá chưa nhập** là ước tính và được ghi rõ: “≈”, “ước tính”.
- **Revision tính cho cả chuyến**: hai người sửa hai mục khác nhau vẫn có thể bị 409.
- **`planner.js`** vẫn là lớp giao diện lớn (khoảng 3.700 dòng). Các phần thuần đã tách: xếp giờ, tuyến, API, lưu, áp dụng phương án.
- **“Thay bằng nơi tương tự”** chỉ tìm trong kho địa điểm đã duyệt.

## 10. Hướng phát triển (Future work)

- **Tuyến đường**:
  - Quanh tuyến chính xác: Haversine lọc → top 20 → OSRM `A → P → B`.
  - Giao thông thời gian thực, nhiều phương tiện trong một ngày, chọn phương tiện cho từng chuyến.
- **Giờ mở cửa**: ngày lễ, theo mùa, qua nửa đêm, đóng tạm thời; nhập giờ từ OSM / Google Places.
- **Cộng tác**: lưu theo từng mục (PATCH) thay vì cả chuyến; cộng tác thời gian thực (CRDT / OT, WebSocket).
- **Thời tiết**: dự báo theo giờ → tự gợi ý phương án mưa.
- **Trợ lý AI**: nhận yêu cầu bằng lời (“ngày 2 nhẹ hơn, bỏ biển, thêm bảo tàng”) → đề xuất `changes` đúng hợp đồng ở mục 6 → TripResolver / TripHealth kiểm tra → người dùng xem trước → áp dụng. AI **không** ghi thẳng CSDL.
- **Ngoài phạm vi Planner**: đặt phòng, vé máy bay, thanh toán, chỉ đường từng bước, chia sẻ công khai / mạng xã hội.

---

## Phụ lục A. API của trình soạn

| Phương thức | Đường dẫn | Mô tả |
|---|---|---|
| POST | `/len-ke-hoach/{id}/luu` | lưu cả chuyến; `rev` chống ghi đè (409) |
| GET | `/len-ke-hoach/{id}/suc-khoe` | chẩn đoán: `summary`, `recommended`, `checks` (`severity`), `days`, `route`, `score` |
| GET | `/len-ke-hoach/{id}/suc-khoe/xu-ly?code=&day=&index=` | phương án xử lý (xem trước, không ghi; cần quyền sửa) |
| POST | `/len-ke-hoach/{id}/toi-uu` | thứ tự đề xuất cho một ngày + `route` |
| GET | `/len-ke-hoach/{id}/quanh-tuyen` | quanh tuyến: `offKm`, `detourKm`, `detourMin`, `detourLevel` |
| GET | `/len-ke-hoach/{id}/tim?q=&type=` | tìm trong kho Travela, lọc theo loại |
| POST | `/len-ke-hoach/{id}/nhan-ban` | nhân bản chuyến |
| GET/PUT | `/api/v1/trips/{id}` | API (JWT): trả `rev`; PUT có `rev` → 409 khi xung đột |

## Phụ lục B. Cấu hình (`config/planner.php`)

```php
'day'   => ['start_min' => 480, 'soft_end_min' => 1080, 'end_min' => 1320],   // 08:00 / 18:00 / 22:00
'slots' => ['morning' => ['start' => 480, 'end' => 690], ...],               // buổi liền nhau, không hở
'meal_allowance_min' => 60, 'buffer_ratio' => 0.1, 'travel_reserve_min' => 60, 'load_warn' => 0.85,
'travel_warn_min' => 180, 'day_items_warn' => 7,
'route' => ['provider' => env('PLANNER_ROUTE_PROVIDER', 'haversine'), 'osrm_url' => env('PLANNER_OSRM_URL'),
            'mode' => env('PLANNER_TRAVEL_MODE', 'driving'), 'factor' => 1.3, 'min_leg_km' => 0.5,
            'modes' => ['driving' => 40 km/h, 'motorbike' => 30 km/h, 'walking' => 4.5 km/h]],
'detour_levels' => [≤5' gần như không đi vòng, ≤15' đáng ghé, ≤30' cân nhắc, >30' vòng xa],
```

Các số suy ra:
- Sức chứa = 600 phút.
- Quỹ thời lượng các mục = floor((600 / 1,1 − 60) / 10) × 10 = 480 phút.
- Quỹ hoạt động của PlannerEngine = 480 − 2 bữa × 60 = 360 phút, nhân hệ số nhịp (0,8 / 1,0 / 1,2).

## Phụ lục C. Quy ước giao diện

- **Ba mức thông báo**:
  - **LỖI** (đỏ): trùng giờ, không kịp đi, đóng cửa / sai giờ mở cửa.
  - **CẢNH BÁO** (vàng): ngày dày, đi lại nhiều, vượt ngân sách, mưa.
  - **THÔNG TIN** (xám): thời lượng ước tính, chưa có giờ mở cửa, OSRM đang dùng ước tính, mục trong “Đã lưu”.
  - Không biến mọi thứ thành màu đỏ.
- **Mọi số liệu ước tính đều ghi rõ**:
  - tuyến: ≈ … (ước tính) / đường thực tế;
  - thời lượng: ≈ 1 giờ, in nghiêng;
  - chi phí: dự kiến / ước tính / đã chi.
- **Timeline là trung tâm**, bản đồ là bối cảnh. Đoạn nối giữa hai hoạt động: “🚗 ≈ 12 phút · 4,2 km · ước tính”.
- **Tóm tắt ngày**: giờ · số điểm · hoạt động · di chuyển · km · chi phí · mức độ.
- **“Đã lưu”** luôn hiện, kể cả khi rỗng: là nơi cất chỗ thích nhưng chưa xếp ngày, và là nơi kéo mục ra khỏi ngày.
- **Thanh công cụ** chỉ có trạng thái lưu, hoàn tác / làm lại, Chia sẻ; thao tác ít dùng nằm trong menu ⋮.
- **Điện thoại**: timeline toàn màn hình; bản đồ toàn màn hình có bảng trượt lịch ngày; thanh dưới gồm Lịch trình · Bản đồ · + · Chi phí · Thông tin.
- **In / PDF**: giữ tiêu đề và tóm tắt ngày, bỏ nút và khung cảnh báo tương tác.

## Phụ lục D. Ma trận kiểm thử

| Nhóm | Phải kiểm | Bộ test |
|---|---|---|
| Lịch | fixed / flexible / slot, chờ giờ mở cửa, client = server | `PlannerContractTest`, `PlannerTimelineTest`, `PlannerAcceptanceTest` |
| Tuyến | Haversine / OSRM / fallback minh bạch, không cache lỗi | `PlannerInvariantTest`, `PlannerEngineTest` |
| Sức khỏe | time / hours / route / capacity / budget / context, cứng – mềm | `PlannerEngineTest`, `PlannerInvariantTest` |
| Giờ mở cửa | mở / đóng / sớm / muộn / chưa biết, đọc cú pháp OSM | `PlannerResolveAndHoursTest`, `PlannerContractTest` |
| Resolver | xem trước = áp dụng (6 loại thao tác, chạy JS thật); A hết + B mềm; A hết + lỗi cứng mới | `PlannerInvariantTest`, `PlannerAcceptanceTest` |
| Tối ưu | fixed / locked / slot / đầu ngày không bị đổi | `PlannerInvariantTest`, `PlannerAcceptanceTest` |
| Lưu | xung đột revision (web + API) | `PlannerInvariantTest`, `PlannerAcceptanceTest`, `Week13SharingTest` |
| Chia sẻ | chủ / sửa / xem / người lạ | `Week13SharingTest`, `PlannerAcceptanceTest` |
| Mưa | xem trước / áp dụng (dự phòng, đổi hẳn) | `PlannerInvariantTest` |
| Nhân bản | bản sao không phá chuyến gốc | `PlannerAroundAndCopyTest` |
| Địa điểm ngoài kho | tọa độ / nguồn, dịch vụ ngoài lỗi không cache | `Week7bCustomPositionTest`, `Week8PlannerSplitTest` |
| Chi phí | planned / estimate / actual | `PlannerAroundAndCopyTest`, `Week9CostReferenceTest` |
| CSDL | đủ bảng / cột | `PlannerAcceptanceTest` (`planner:doctor`) |

Chạy: `php artisan test --filter "Planner|Week"`. Phần JS cần Node.js; không có Node thì contract test bỏ qua phần JS.

## Phụ lục E. Vận hành

```bash
php artisan migrate                                    # 8 migration Planner (2026_11_09 … 2026_11_17)
php artisan planner:doctor                             # kiểm tra bảng / cột / migration / nguồn tuyến (--osrm: thử gọi OSRM)
php artisan db:seed --class=OpeningHoursDemoSeeder     # giờ mở cửa mẫu (tìm theo tên)
php artisan planner:hours place "bao tang"             # tìm mã theo tên (không dấu)
php artisan planner:hours place <mã> "Tu-Su 08:30-17:30; Mo off"
```
