# La bàn · gói 3: Hỏi về chuyến (F5) + nhật ký AI cho admin (AI-5)

## 1. F5 "Hỏi về chuyến này"

Cùng ô nhập của ngăn La bàn. Người dùng không phải chọn chế độ: câu **hỏi** ("Ngày 2 có nơi nào cho trẻ nhỏ không?", "Cần mang áo mưa không?", "Món nên thử ở đây?") được trả lời có nguồn; câu **nhờ sửa** ("ngày 1 nhẹ hơn", "thêm quán cà phê được không?") vẫn đi Copilot như gói 2.

| Thành phần | Việc |
|---|---|
| `Ai/Ask/QuestionRouter` | Chia luồng bằng luật, không gọi LLM. Có động từ sửa lịch (chuyển, thêm, đổi, dời, bỏ, xếp lại, nhẹ hơn…) thì là SỬA kể cả khi có dấu "?". Kiểm bản có dấu (để "chuyến" khác "chuyển", "thấy" khác "thay") và cụm không dấu không nhập nhằng ("chuyen sang", "nhe hon"). |
| `Ai/Ask/TripFacts` | "Dữ kiện chuyến": mỗi ngày một nguồn (giờ từng mục, giờ mở cửa đúng thứ của ngày đó, trong nhà / ngoài trời, quãng đi lại), cộng sức khỏe chuyến, mùa theo tháng, ghi chú, "Đã lưu". Câu nêu "ngày N" chỉ nhận lịch của ngày đó. |
| `Ai/Ask/TripAskService` | Gọi **nguyên** `AskService` của Hỏi đáp: phạm vi = điểm đến của chuyến; dữ kiện chuyến đứng trước dữ kiện SQL (mùa, lễ hội, tour) và đoạn cẩm nang. Giữ cổng tin cậy, từ chối khi thiếu căn cứ, kiểm tra trích dẫn, hạn mức `rag.limits.daily_per_user`, nhật ký `tbl_chat_logs` với `mode = trip`. |
| `Rag/AskService` | Thêm tùy chọn `facts` (dữ kiện do nơi gọi dựng sẵn) và `logMode`. Chưa dựng chỉ mục cẩm nang mà có dữ kiện chuyến thì vẫn trả lời từ dữ kiện. Nguồn trả thêm `key`. Hỏi đáp cũ không đổi hành vi. |
| Giao diện | Câu trả lời có số nguồn [1] bấm được; nguồn "Ngày 2 trong lịch của bạn" cuộn tới đúng ngày; nguồn cẩm nang mở trang bài. Dòng cuối: "Chỉ trả lời từ lịch đã lưu và cẩm nang đã duyệt. Lịch không đổi." Tiến trình kể đúng việc làm ("Tìm trong lịch và cẩm nang", không phải "Kiểm chứng bằng Sức khỏe"). |

**Không cần AI:** tắt LLM (hoặc hết lượt AI của Copilot) F5 vẫn chạy, AskService trả lời bằng trích đoạn nguyên văn của chính các nguồn. Tắt riêng F5: `AI_FEATURE_ASK=false` (mọi câu về Copilot).

## 2. Tab "AI La bàn" ở trang Nhật ký (admin)

`/admin/chat-logs/ai` (tab cạnh "Hỏi đáp"; menu "Nhật ký hỏi đáp & AI"). Đọc `tbl_ai_runs`, lọc theo tính năng, kết quả, engine, ngày.

- Theo từng tính năng: số lượt, % có gọi AI, % chế độ cơ bản, % lỗi / quá giờ, số lần guard phải thay câu (F2), **tỷ lệ áp dụng đề xuất** (applied / (applied + skipped), kèm số đề xuất người dùng chưa bấm), số lần "không đề xuất được", độ trễ TB / P95, token vào / ra, chi phí ước tính (khi đặt `AI_PRICE_IN` / `AI_PRICE_OUT`).
- Bảng kết quả theo loại, danh sách từng lượt (model, phiên bản lời nhắc, cờ `steps / repairs / searches`).
- Xuất CSV (UTF-8 BOM, chống CSV injection) cho chương thực nghiệm.
- Tab Hỏi đáp có thêm bộ lọc "Hỏi về chuyến (La bàn)" và nhãn "chuyến" cho các câu F5.

## 3. Tệp

Mới: `app/Services/Ai/Ask/{QuestionRouter,TripFacts,TripAskService}.php`, `resources/views/admin/chat/{ai,tabs}.blade.php`, `tests/Unit/Ai/{QuestionRouterTest,TripFactsTest}.php`, `tests/Feature/AiAskAndLogTest.php`.
Sửa: `Rag/AskService.php`, `PlannerAiController.php`, `admin/ChatLogController.php`, `routes/web.php` (+2 route admin), `config/ai.php` (`features.ask`), `edit.blade.php`, `admin/chat/index.blade.php`, `admin/blocks/sidebar.blade.php`, `planner-ai-copilot.js`, `planner-ai.css`, `tests/Feature/AiCopilotTest.php` (một test Copilot tắt F5 để vẫn kiểm nhánh reply).

Không có migration. Toàn bộ bộ test: **547 test qua** (PHP 8.4, MySQL/MariaDB).

## 4. Kiểm tra tay

1. Mở chuyến, Hỏi La bàn: "Ngày 2 có gì?" → câu trả lời có [1], nguồn "Ngày 2 trong lịch của bạn", bấm thì cuộn tới ngày 2. Lịch không đổi, không hiện thẻ đề xuất.
2. "Cần mang áo mưa không?" (đã `php artisan rag:build`) → có nguồn cẩm nang và nguồn "Mùa và thời tiết tháng đi".
3. "Thêm quán cà phê được không?" → đi Copilot, ra thẻ đề xuất như gói 2.
4. Admin → Nhật ký hỏi đáp & AI → tab AI La bàn: thấy tỷ lệ áp dụng của Copilot sau vài lần Áp dụng / Bỏ qua; xuất CSV mở được bằng Excel.
