# La bàn · gói 2: Copilot sửa lịch bằng lời (F3, AI-3 + AI-4)

Người dùng nói "ngày 1 nhẹ hơn", "thêm quán cà phê gần Văn Miếu", "ngày 2 mưa thì sao". La bàn soạn đề xuất, **TripHealth kiểm chứng trên bản sao**, người dùng xem bản nháp ngay trên lịch rồi mới bấm **Áp dụng**. Không route AI nào ghi vào chuyến: trình duyệt áp dụng bằng chính `planner-resolve.js` của nút "Xem cách xử lý", rồi tự lưu như mọi thao tác khác (có `rev`, 409, hoàn tác).

## 1. Trải nghiệm

| Chỗ | Làm gì |
|---|---|
| Nút **Hỏi La bàn** | Trên thanh công cụ và góc bản đồ (desktop). Trên điện thoại là nút nổi ngay trên thanh thao tác dưới. |
| Ngăn La bàn | Desktop: nằm đúng trên cột bản đồ, lịch bên trái vẫn thấy để xem bản nháp. Điện thoại: toàn màn hình. |
| Dải bối cảnh | "La bàn đọc bản đã lưu: 3 ngày · 12 mục · 2 điều cần xem". Có thay đổi chưa lưu thì báo và **tự lưu trước khi hỏi**. |
| Gợi ý bắt đầu | Lấy từ **Sức khỏe chuyến đi** thật (ngày quá dày, giờ mở cửa, chồng giờ, đi lại nhiều, mưa), không phải câu chung chung. |
| **@** | Bấm @ rồi chạm một mục trên timeline để nhắc tới nó (gửi tên + ngày). Mục cố định / đã khóa chỉ được đụng khi người dùng nêu đích danh như vậy. |
| Đang xử lý | Ba bước thật: đọc lịch đã lưu → tìm và soạn → kiểm chứng. Đồng hồ giây, nút Hủy. Lịch tạm khóa để đề xuất không lệch. |
| Thẻ đề xuất | Câu La bàn (tên nơi là chip do máy chủ chèn), từng thao tác, thanh độ dày từng ngày trước → sau, **phiếu kiểm chứng**: vấn đề được giải quyết, "không tạo lỗi cứng mới", cảnh báo mềm còn lại, điểm sức khỏe trước → sau. |
| Bản nháp trên lịch | Mục bị chuyển / thay: gạch ngang + nhãn "→ Ngày 2". Mục thêm: ô viền vàng "MỚI" đúng vị trí. Mục chuyển đến: "CHUYỂN ĐẾN". Thanh "Đang xem bản nháp" dính trên lịch có Áp dụng / Bỏ qua. |
| Lịch đổi trong lúc chờ | Thẻ chuyển sang "Lịch đã đổi" với nút **Kiểm tra lại**: máy chủ ánh xạ đề xuất cũ sang bản mới theo danh tính mục và mô phỏng lại, **không gọi LLM**. Mục đã bị xóa thì nói rõ, không đoán. |

Màu giữ quy ước gói 1: vàng cát nét đứt = AI đề xuất chưa áp dụng, xanh ngọc = đã kiểm chứng / đã áp dụng.

## 2. Luồng kỹ thuật

```
câu + @mục ─▶ CopilotService (tối đa 4 bước LLM, ngân sách 30 s)
               │  mỗi bước LLM trả MỘT hành động JSON:
               ├─ search   → PlaceFinder: tbl_external_places đã duyệt, theo nhóm / tên / quanh mục mốc ≤ 4 km,
               │             kèm giờ mở cửa đúng thứ của ngày → khóa ứng viên c:place:ID đưa vào bước sau
               ├─ propose  → KeyedChangeCompiler (khóa iN / c:… → (ngày, vị trí), luật cấm)
               │             → TripResolver::simulate (TripHealth trước / sau, CÙNG ChangeSimulator)
               │             → có vấn đề CỨNG mới? trả lời lỗi cho LLM sửa (tối đa 2 lần) : phiếu kiểm chứng
               └─ reply    → trả lời / hỏi lại; câu có số không có trong dữ kiện bị bỏ (NumberGuard)
trình duyệt ─▶ TpPlanner.applyAi → planner-resolve.js → commit → tự lưu (rev, 409) → hoàn tác được
```

Quyết định thiết kế:

- **Hành động JSON thay vì function calling riêng từng hãng.** Dùng lại `generateJson` đã ổn định ở gói 1, chạy giống nhau trên Claude, Gemini (cổng OpenAI) và Ollama, và test được bằng `FakeLlm` theo kịch bản. Kết quả an toàn không phụ thuộc cách gọi công cụ, vì mọi thứ LLM trả về đều qua compiler và TripHealth.
- **Một nguồn ngữ nghĩa thao tác.** `ChangeSimulator` (PHP) được tách khỏi `TripResolver` và dùng chung cho nút "Sửa" của Sức khỏe chuyến đi và cho La bàn. Đã so tương đương với bản cũ trên 2.864 trường hợp ngẫu nhiên, và so PHP ↔ `planner-resolve.js` cho thao tác mới `add` trên 1.381 trường hợp: 0 khác nhau.
- **Khóa mục dùng lại quy ước của F2** (`i1…iN` theo ngày, vị trí, "Đã lưu" cuối). LLM không bao giờ thấy hay viết chỉ số vị trí.
- **LLM chỉ thêm / thay bằng nơi do `search_places` trả về trong chính lượt đó.** Khóa bịa bị từ chối kèm lời nhắc "hãy gọi search_places trước".

Luật của `KeyedChangeCompiler` (mỗi luật có test):

- Khóa lạ, ứng viên không có trong kết quả tìm, ứng viên đã có trong chuyến: từ chối.
- Mục giờ cố định / đã khóa: chỉ đụng khi người dùng nêu tên (gõ đúng tên hoặc chọn bằng @).
- Một mục không bị chuyển / thay hai lần. Xếp lại phải liệt kê đủ mục của ngày, giữ nguyên mục đầu ngày và mục cố định, và không xếp lại ngày vừa có mục bị chuyển đi.
- Mục trong "Đã lưu" không thao tác được. Giờ đặt trong 05:00–23:00, làm tròn 5 phút. Tối đa 8 thao tác.

## 3. Tệp trong gói

| Tệp | Vai trò |
|---|---|
| `app/Services/Planner/Health/ChangeSimulator.php` | **Mới.** Ngữ nghĩa `changes[]` phía máy chủ, thêm thao tác `add`. |
| `app/Services/Planner/Health/TripResolver.php` | Dùng `ChangeSimulator`; thêm `simulate()` (trước / sau, vấn đề mới / đã hết) và `candidate()`. |
| `public/clients/assets/js/planner-resolve.js` | Thêm `add`, cùng ngữ nghĩa PHP. |
| `app/Services/Ai/Copilot/KeyedChangeCompiler.php` | Khóa → vị trí + luật cấm (hàm thuần). |
| `app/Services/Ai/Copilot/CopilotContext.php` | Bảng dữ kiện + bảng khóa + gợi ý bắt đầu từ TripHealth (hàm thuần). |
| `app/Services/Ai/Copilot/PlaceFinder.php` | Công cụ `search_places`. |
| `app/Services/Ai/Copilot/CopilotPrompt.php` | Lời nhắc + schema hành động (`prompt_version` = `copilot-1`). |
| `app/Services/Ai/Copilot/CopilotService.php` | Vòng làm việc, kiểm chứng, Kiểm tra lại, ghi Áp dụng / Bỏ qua. |
| `app/Http/Controllers/clients/PlannerAiController.php`, `routes/web.php` | `POST /len-ke-hoach/{id}/la-ban`, `/la-ban/kiem-tra-lai`, `/la-ban/ket-qua`. Chỉ người sửa được chuyến; người chỉ xem nhận 404. |
| `public/clients/assets/js/planner.js` | Thêm cửa `window.TpPlanner` (state, item, saveNow, applyAi) và sự kiện `tp:render`, `tp:dirty`, `tp:saved`. Không đổi hành vi cũ. |
| `public/clients/assets/js/planner-ai-copilot.js`, `css/planner-ai.css` | Giao diện La bàn. |
| `resources/views/clients/planner/edit.blade.php`, `PlannerController.php` | Nút, ngăn, thanh bản nháp; `aiCopilotOn`. |
| `config/ai.php` | `features.copilot` (mặc định bật khi `AI_PLANNER_ENABLED=true`), `copilot.budget_ms`, `prompt_version.copilot`. |
| `tests/Unit/Ai/KeyedChangeCompilerTest.php`, `CopilotContextTest.php`, `tests/Feature/AiCopilotTest.php` | 9 + 4 + 8 test. |

## 4. Cài đặt

Không có migration mới. Thêm vào `.env` (tùy chọn, giá trị mặc định đã hợp lý):

```env
AI_FEATURE_COPILOT=true
AI_MODEL_COPILOT=gemini-3.5-flash-lite
AI_MAX_TOKENS_COPILOT=2500      # model có suy luận tính cả token suy luận
AI_TIMEOUT_COPILOT=20           # giây mỗi bước
AI_COPILOT_BUDGET_MS=30000      # tổng thời gian một lượt
```

```bash
php artisan config:clear
php artisan test --filter=Ai     # gói 1 + gói 2, không gọi API thật
php artisan test                 # cả bộ (PlannerInvariantTest dùng Node như trước)
```

Mỗi lượt hỏi La bàn tính **một** lượt trong `AI_DAILY_LIMIT`, dù bên trong có thể gọi LLM 2–4 lần.

## 5. Kiểm tra tay (10 phút)

1. Mở một chuyến có ngày quá dày. Bấm **Hỏi La bàn**: gợi ý đầu tiên là "Ngày N quá dày". Bấm nó. Đúng: thẻ đề xuất có thanh độ dày giảm, phiếu kiểm chứng ghi vấn đề đã hết; lịch bên trái gạch mục bị chuyển, có ô "CHUYỂN ĐẾN" ở ngày nhận.
2. Bấm **Áp dụng**: lịch đổi đúng như bản nháp, trạng thái "Đang lưu… → Đã lưu", nút ↶ hoàn tác được. Mở Sức khỏe chuyến đi: điểm khớp số trong phiếu.
3. "Thêm một quán cà phê gần <mục đầu ngày 1>": ô "MỚI" nằm ngay sau mục đó, tên quán có trong kho Travela.
4. Bấm **@**, chạm một mục có giờ cố định, gõ "chuyển sang ngày 2": được phép vì đã nêu tên. Gõ "ngày 1 nhẹ hơn" không @: La bàn không đụng mục cố định.
5. Khi đang có thẻ đề xuất, kéo đổi một mục khác trên lịch: thẻ chuyển "Lịch đã đổi". Bấm **Kiểm tra lại**: thẻ cập nhật trên bản mới, không tốn lượt AI.
6. Hỏi "đưa <bảo tàng đóng cửa thứ Hai> sang ngày thứ Hai": La bàn không đưa ra đề xuất đó (bị chặn vì đóng cửa) mà trả lời không tìm được cách sửa an toàn, hoặc đề xuất khác.
7. Điện thoại: nút nổi "Hỏi La bàn", ngăn toàn màn hình; trong thẻ có "Xem trên lịch" để đóng ngăn và cuộn tới bản nháp. Chọn @ thì ngăn tạm ẩn, có thanh "Chạm vào một mục…".
8. `AI_PLANNER_ENABLED=false`: không có nút La bàn, trang vẫn chạy như trước.

## 6. Số liệu cho chương thực nghiệm

`tbl_ai_runs` (feature = `copilot`), mỗi lượt một dòng:

- `outcome`: `proposed` → sau đó thành `applied` hoặc `skipped` khi người dùng bấm; `reply`, `refused` (không tìm được đề xuất an toàn), `timeout`, `error`, `invalid`.
- `guardFlags`: `steps`, `repairs`, `searches`.

Gợi ý chỉ số: tỷ lệ chấp nhận = applied / (applied + skipped); tỷ lệ đề xuất có lỗi cứng tới tay người dùng (thiết kế bảo đảm 0, có test); số bước và số lần sửa trung bình; p50 / p95 `latencyMs`. Bộ đánh giá tự động `ai:eval --suite=copilot` (kịch bản + đáp án) để ở gói 4.

## 7. Bất biến đã khóa bằng test

- Copilot không ghi vào chuyến (`rev` không đổi). Áp dụng qua **chính mã trình duyệt** cho ra đúng lịch, điểm sức khỏe và độ dày như phiếu kiểm chứng (`test_preview_equals_apply_through_browser_js`).
- Ứng viên bịa bị từ chối, LLM nhận lời báo và sửa được ở bước sau.
- Đề xuất tạo lỗi cứng mới (ví dụ đưa bảo tàng vào ngày đóng cửa) không bao giờ tới người dùng.
- Câu trả lời có số không có căn cứ bị bỏ câu đó.
- Kiểm tra lại ánh xạ theo danh tính mục sau khi lịch đổi, không gọi LLM; mục đã xóa thì báo rõ.
- AI tắt: không có nút, không tải script; API trả chế độ cơ bản, không gọi LLM. Người chỉ xem: 404.

## 8. Còn lại

- **Gói 3:** F5 "hỏi về chuyến" (hỏi đáp có căn cứ trên chuyến + kho nội dung) và tab nhật ký AI cho admin (đọc `tbl_ai_runs`: tỷ lệ chấp nhận, lỗi, chi phí).
- **Gói 4:** thực nghiệm và chốt: bộ đánh giá `explain` và `copilot`, câu do người khác viết (held-out), thử nghiệm người dùng, đóng băng phiên bản lời nhắc.
