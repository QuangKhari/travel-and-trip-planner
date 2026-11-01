> Bản mẫu tương tác (artifact): https://claude.ai/artifact/RMvcdY79Km6Hne3ggPEttQ — 01/11/2026

# La bàn · gói 1: nền AI + Vé hành trình (F1) + Tóm tắt có căn cứ (F2)

Gói này hiện thực AI-0, AI-1, AI-2 của tài liệu *Travela AI Trip Planner v2.2*. Copilot (F3) nằm ở gói 2 (AI-3 + AI-4).

## 1. Concept trải nghiệm "La bàn"

AI của Travela tên là **La bàn**. Nó chỉ hướng, còn người dùng tự cầm lái. Ba ý thiết kế riêng:

| Ý | Ở đâu | Người dùng thấy gì |
|---|---|---|
| **Vé hành trình** | `/len-ke-hoach/de-xuat` (F1) | Câu đã gõ được tô màu theo từng trường. Bên cạnh là vé có 3 trạng thái: *nêu rõ* (xanh), *AI đoán* (vàng nét đứt), *còn thiếu* (đỏ, kèm nút chọn nhanh). Rê chuột vào một ô vé thì cụm chữ sinh ra nó sáng lên. **Cuống vé** ghi rõ điều Travela chưa lọc được (ăn chay, xe lăn…). Form bên dưới được điền sẵn, ô nào AI đoán thì có dòng nhắc. |
| **Lời kể có căn cứ** | Trình soạn `/len-ke-hoach/{id}` (F2) | Khối thu gọn phía trên lịch. Tên địa điểm là chip do máy chủ chèn, bấm vào thì cuộn tới mục trong timeline. Mỗi lưu ý có nhãn căn cứ (sức khỏe chuyến đi, mùa theo tháng, ghi chú của bạn). Lịch được lưu bản mới thì hiện "Lịch vừa thay đổi · Tóm tắt lại". |
| **Bản nháp trên lịch + phiếu kiểm chứng** | Trình soạn (F3, gói 2) | Đề xuất hiện ngay trên timeline (mục bị gạch, mục mới viền vàng, thanh độ dày trước → sau) kèm phiếu liệt kê đúng những gì TripHealth đã kiểm. Có trạng thái bị chặn và "Lịch đã thay đổi → Kiểm tra lại". Xem bản mẫu tương tác. |

Quy ước màu dùng chung: **xanh ngọc** là dữ kiện đã kiểm chứng, **vàng cát** là AI đề xuất hoặc đoán, chưa áp dụng. **Đỏ đất** là còn thiếu hoặc chưa lọc được. AI không có hiệu ứng phát sáng hay gradient riêng, chỉ có biểu tượng la bàn.

## 2. Tệp trong gói

**Mới**
```
config/ai.php
app/Services/Ai/AiGateway.php                cờ · hạn mức · ngắt mạch · timeout · log · lý do lùi
app/Services/Ai/SchemaViolation.php
app/Services/Ai/Llm/StructuredLlm.php        interface mở rộng Llm của RAG (generateJson)
app/Services/Ai/Llm/AnthropicJsonLlm.php     JSON qua tool_use bắt buộc
app/Services/Ai/Llm/OpenAiJsonLlm.php        JSON qua response_format
app/Services/Ai/Llm/FakeLlm.php              cho test và ai:eval, không tốn tiền
app/Services/Ai/Llm/AiLlmFactory.php         model theo tác vụ
app/Services/Ai/Brief/{BriefPrompt,BriefValidator,BriefService}.php
app/Services/Ai/Explain/{ExplainFacts,NumberGuard,ExplainComposer,ExplainPrompt,ExplainService}.php
app/Http/Controllers/clients/PlannerAiController.php
app/Console/Commands/AiEval.php              php artisan ai:eval
database/migrations/2026_11_20_000001_ai_planner_foundation.php   tbl_ai_runs, tbl_trip_ai_cache, tbl_trips.notes
database/ai/eval_brief.json                  26 câu khởi đầu (bổ sung tới 40, ≥15 câu người khác viết)
public/clients/assets/css/planner-ai.css
public/clients/assets/js/planner-ai-brief.js
public/clients/assets/js/planner-ai-explain.js
tests/Unit/Ai/BriefValidatorTest.php         11 test
tests/Unit/Ai/ExplainGuardsTest.php          8 test
tests/Feature/AiPlannerTest.php              12 test (FakeLlm)
```

**Sửa**
```
routes/web.php                                      +2 route (trong nhóm checkLoginClient)
app/Http/Controllers/clients/PlannerController.php  aiOn / aiExplainOn cho view; rule 'notes'; lưu notes sau khi tạo chuyến
resources/views/clients/planner/suggest.blade.php   ô "Kể chuyến đi bằng một câu" thay ô ?q= cũ; input ẩn notes; aiData
resources/views/clients/planner/edit.blade.php      khối "Tóm tắt chuyến đi" phía trên Sức khỏe chuyến đi; aiData
public/clients/assets/js/planner-suggest.js         thêm window.TpSuggest (API điền form), không đổi hành vi cũ
.env.example                                        biến AI_*
```

## 3. Cài đặt

```bash
php artisan migrate                       # tạo tbl_ai_runs, tbl_trip_ai_cache, cột tbl_trips.notes (có guard)
php artisan test --filter=Ai              # 31 test, không gọi API thật
```

Bật AI trong `.env`. Cấu hình đã chốt sau đánh giá (Gemini qua cổng tương thích OpenAI, dùng chung khóa với RAG):

```env
AI_PLANNER_ENABLED=true
RAG_LLM_DRIVER=openai
RAG_LLM_BASE_URL=https://generativelanguage.googleapis.com/v1beta/openai
RAG_LLM_KEY=...
RAG_LLM_MODEL=gemini-3.5-flash-lite
AI_MODEL_BRIEF=gemini-3.5-flash-lite
AI_MODEL_EXPLAIN=gemini-3.5-flash-lite
AI_REASONING_EFFORT=low
AI_MAX_TOKENS_BRIEF=2500
AI_MAX_TOKENS_EXPLAIN=4000
AI_TIMEOUT_BRIEF=20
AI_TIMEOUT_EXPLAIN=30
AI_DAILY_LIMIT=40
```

Rồi chạy `php artisan config:clear`. Để `AI_PLANNER_ENABLED=false` thì cả hai trang vẫn chạy: F1 đọc câu bằng SearchIntent (nhãn "Chế độ cơ bản"), F2 ghép câu mẫu từ `reasons`.

## 4. Đánh giá (số liệu cho chương thực nghiệm)

```bash
php artisan ai:eval --suite=brief --engine=rules --out=storage/app/brief-rules.csv   # mốc SearchIntent
php artisan ai:eval --suite=brief --engine=llm   --out=storage/app/brief-llm.csv
# tùy chọn: --model= --effort=low --timeout= --sleep=4 --retry=2
```

In tỷ lệ đúng từng trường. Trường suy đoán chỉ tính đúng khi đúng giá trị **và** được đánh dấu "AI đoán". Lệnh cũng in tỷ lệ hỏi lại đúng khi thiếu, tỷ lệ chặn injection, p50/p95 độ trễ, token và chi phí (khi có `AI_PRICE_IN/OUT`). Slug trong `eval_brief.json` phải khớp `tbl_destinations` của bạn.

## 5. Kiểm tra tay (5 phút)

1. `/len-ke-hoach/de-xuat`, gõ câu mẫu có "bạn mình ăn chay", bấm **Hiểu giúp mình**. Kết quả đúng: câu được tô màu, vé có ô vàng "AI đoán", cuống vé đỏ ghi ăn chay, form bên dưới đã điền, trong form có dòng "La bàn đoán …".
2. Bấm "Mỗi người" ở ô ngân sách thì ngân sách trong form nhân theo số người.
3. Gõ "mình muốn đi biển vài ngày": vé hỏi lại điểm đến, số ngày, tháng bằng nút chọn nhanh, không tự đoán.
4. Xem trước → Lưu thành chuyến → mở chuyến, bấm **Tóm tắt chuyến đi bằng AI**. Bấm chip tên thì cuộn tới mục. Kéo đổi một mục: sau khi tự lưu, hiện "Lịch vừa thay đổi".
5. Đặt `AI_PLANNER_ENABLED=false`, lặp lại bước 1 và 4: vẫn chạy, có nhãn chế độ cơ bản.
6. Admin xem `tbl_ai_runs`: mỗi lượt một dòng (engine, outcome, latencyMs, guardFlags). Mặc định không lưu nguyên văn câu.

## 6. Bất biến đã khóa bằng test

- F1 **không bao giờ** đoán điểm đến / số ngày / thời gian. LLM khai `inferred` cho các trường này thì validator xóa và hỏi lại.
- Slug lạ, enum lạ, ngày đã qua, số ngày quá trần đều bị loại hoặc cắt **và báo**, không im lặng. Ngân sách theo người được nhân ở PHP.
- Cụm tô màu phải có thật trong câu người dùng.
- F2: tên riêng chỉ do máy chủ chèn (`{{iN}}`). Tên viết thẳng, khóa lạ, khóa sai ngày, số không có trong dữ kiện, HTML/URL đều bị thay bằng câu mẫu. Lưu ý không có căn cứ thì bị bỏ.
- Tên địa điểm (nhất là từ OSM) được làm sạch trước khi vào lời nhắc (P7).
- AI lỗi 3 lần liên tiếp thì ngắt mạch 90 giây. Hết hạn mức thì lùi về luật. Không route AI nào ghi vào chuyến đi.

## 7. Gói 2 (AI-3 + AI-4): La bàn Copilot

Đã làm, xem `docs/ai/HUONG_DAN_LA_BAN_2.md`.

## 8. Kết quả đánh giá F1 (01/11/2026, 26 câu, đáp án do tác giả gán)

Cấu hình chốt: Gemini qua cổng tương thích OpenAI, `gemini-3.5-flash-lite`, `AI_REASONING_EFFORT=low`, phiên bản `brief-3` (lời nhắc brief-2 + ghép luật cho 3 trường bắt buộc). Mỗi lượt chấm 107 ô (trường × câu).

| Trường (số ô) | Luật SearchIntent | La bàn brief-3, 3 lần chạy: trung bình (khoảng) |
|---|---|---|
| destination (20) | 100% | 100% |
| month (18) | 100% | 100% |
| days (18) | 94,4% | 100% |
| adults (7) | 57,1% | 100% |
| pace (3) | 0% | 88,9% (66,7–100%) |
| interests (9) | 100% | 100% |
| dislikes (3) | 66,7% | 100% |
| needs: hỏi lại đúng khi thiếu (17) | 82,4% | 100% |
| budget (6) | 50% | 100% |
| children (1) | 0% | 100% |
| unsupported: báo điều chưa lọc được (2) | 0% | 100% |
| injection (3) | 100% | 100% |
| **Tổng ô đúng** | 90/107 (84,1%) | 107, 107, 106 /107 (99,7%) |
| Độ trễ p50 / p95 | 2 / 3 ms | 1,8 s / 2,2–3,0 s |

Luật chạy lại với bộ đáp án hiện tại (sau khi sửa b04). 17 ô luật sai, theo nhóm:
- Cần hiểu ngữ cảnh: "với bố mẹ" → 3 người (b01), "đi một mình" (b03), "2 lớn 2 nhỏ" (b04), "chill" / "không thích đi quá nhiều" → thong thả (b01, b02, b13).
- Cách nói tiền: "mỗi người 3 triệu" (b05), "tầm 5tr" không rõ cả nhóm hay mỗi người (b14), "10tr" (b18); "3n2d" (b18).
- Cách nói phủ định: "tránh chỗ đông" (b06).
- Hiểu cấu trúc câu: nhiều điểm đến (b21, b22), ngân sách chưa rõ phạm vi (b14).
- Ý ngoài danh sách có sẵn: ăn chay, xe lăn (b19, b20).
La bàn sai 1 ô qua 3 lần chạy (b01 nhịp đi, lần 3).

Quá trình (ghi vào báo cáo để minh bạch):
1. **brief-1**: còn sai b13 ("cuối tuần"), b04 (nhãn thiếu), b25 (câu lệnh lạ lọt vào ghi chú, dù AI không đổi điểm đến).
2. **brief-2**: lời nhắc thêm "cuối tuần" = 2 ngày, gợi ý từ khóa → sở thích, câu không dấu, đi cùng bố mẹ → thong thả, phớt lờ câu lệnh lạ; validator chặn cụm trông như câu lệnh không cho thành ghi chú. Ba lần chạy cùng cấu hình mỗi lần sai một câu khác nhau (b02, b13, b17): LLM không ổn định giữa các lần chạy.
3. **brief-3**: không chỉnh lời nhắc nữa mà sửa bằng kiến trúc. Với điểm đến, thời gian, số ngày: LLM bỏ trống mà luật đọc được (luật chỉ bắt điều nói rõ) thì lấy giá trị của luật (`rules_filled:*` trong log). LLM lo phần cần hiểu ngữ cảnh, luật giữ ổn định phần cần chắc chắn.
4. Bật hay tắt `reasoning_effort=low` chênh nhau trong mức dao động ngẫu nhiên; giữ `low`.
5. Sai còn lại: b01 "với bố mẹ" thỉnh thoảng không ra nhịp thong thả. Đây là trường AI đoán, người dùng thấy nhãn "AI đoán" và sửa được.

Ghi chú vận hành:
- gemini-3.8-flash lúc thử (01/11, 18h) báo 503 quá tải và chạy quá 20 giây, nên không dùng cho F1.
- Gói miễn phí giới hạn số lượt mỗi phút (lỗi 429): chạy đánh giá với `--sleep=6`.
- Bộ đánh giá bỏ qua ngắt mạch, thử lại khi lỗi; câu gọi lỗi không tính vào bảng.

Việc tiếp theo:
- Thêm ít nhất 15 câu do người khác viết (`source: peer`, chưa từng dùng để chỉnh lời nhắc) và chạy brief-3 đúng 3 lần trên bộ mới để có số liệu khách quan.
