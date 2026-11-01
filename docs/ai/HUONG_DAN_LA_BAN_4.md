# La bàn · gói 4: thực nghiệm và đóng băng (AI-6, AI-7)

Gói này **không thêm tính năng cho người dùng**. Nó cho bạn công cụ để có số liệu chương thực nghiệm (tài liệu v2.2 mục 8) và khóa phiên bản trước khi bảo vệ. Mọi bộ đánh giá chạy trong **hộp cát**: mỗi mẫu là một giao dịch CSDL rồi hoàn tác, không để lại chuyến, nhật ký AI hay cache (có test khóa điều này).

## 1. Các bộ đánh giá

| Lệnh | Đo gì | Bộ mẫu |
|---|---|---|
| `ai:eval --suite=brief --engine=rules\|llm [--file=...]` | F1 (đã có ở gói 1). Thêm: `--file` để chạy bộ câu **người khác viết**, và bảng tách theo nguồn câu (`author` / `peer`) | `eval_brief.json` (26 câu), `eval_brief_peer.json` (khung trống để điền) |
| `ai:eval --suite=plan --mode=engine` | **A2**: PlannerEngine hiện tại | `eval_plan.json` (20 yêu cầu, 8 điểm đến, 1–4 ngày, 7 yêu cầu bắt đầu Thứ Hai để bẫy giờ đóng cửa) |
| `ai:eval --suite=plan --mode=llm_data` | **A1b**: LLM nhận ĐÚNG danh sách ứng viên engine nhận (tên, loại, tọa độ, thời lượng, giờ mở cửa từng ngày của chuyến) và tự xếp lịch + giờ, không có engine | như trên |
| `ai:eval --suite=plan --mode=llm` | **A1**: LLM tự viết lịch, không dữ liệu (tên được đối chiếu với nội dung đã duyệt của điểm đến) | như trên |
| `ai:eval --suite=copilot --mode=verified` | **B2**: La bàn Copilot đầy đủ | `eval_copilot.json` (30 yêu cầu, 10 nhóm: giảm tải, thêm ăn uống, mưa, dời giờ, bẫy, nhiều thao tác, có sẵn lỗi, mâu thuẫn, ngoài phạm vi, injection) |
| `ai:eval --suite=copilot --mode=unchecked` | **B1**: cùng LLM, cùng lời nhắc, đề xuất đầu tiên được nhận luôn (không TripHealth chặn, không vòng sửa) | như trên |
| `ai:eval --suite=explain` | F2: tỷ lệ qua guard, số đoạn phải thay, độ trễ, token; ghi lời kể ra CSV để **người chấm** 1–5 (cột `judgeFaithful`, `judgeUseful`) | `eval_explain.json` (10 chuyến, 5 điểm đến × 2 độ dài) |

Tùy chọn chung: `--out=storage/app/<tên>.csv`, `--limit=N`, `--model=`, `--effort=low`, `--sleep=6` (gói miễn phí Gemini giới hạn số lượt mỗi phút).

**Chuyến trong thí nghiệm B và F2 do chính engine sinh** từ yêu cầu trong bộ mẫu, nên chạy được trên dữ liệu của bạn. Câu yêu cầu dùng chỗ giữ `{d1i2}` = tên mục thứ 2 của ngày 1 trong chuyến vừa sinh. `mutate` làm hỏng chuyến có chủ đích: `overload_day1` (dồn mọi mục vào ngày 1), `fix_first_late` (mục đầu ngày 1 cố định 21:00).

> Ngày trong bộ mẫu là 12/2026 – 01/2027. Chạy sau các ngày đó thì dời ngày (giữ đúng thứ trong tuần) cho còn ở tương lai.

### Chỉ số in ra

- **plan**: tỷ lệ mục có thật trong CSDL, số mục lặp, lỗi cứng TripHealth (tổng, TB / chuyến, % chuyến không lỗi cứng), cảnh báo TB, điểm sức khỏe TB, độ dày ngày TB / ngày dày nhất, phủ sở thích, p50 / p95, token. CSV có thêm `codes` (đếm theo mã lỗi cứng: `hours.closed`, `time.overlap`…).
- **copilot**: đúng loại phản hồi, % có đề xuất, **lỗi cứng mới tới người dùng** (mục tiêu B2 = 0), % đề xuất có lỗi cứng mới, giải quyết đúng vấn đề mong đợi, đúng thao tác, đụng đúng mục được nêu, số bước LLM / số lần sửa TB, p50 / p95, token.
- **explain**: % gọi được AI, % qua guard trọn vẹn, số đoạn phải thay.

## 2. Quy trình chạy cho chương thực nghiệm (gợi ý ~1 buổi)

```powershell
php artisan config:clear
php artisan ai:prompts                     # phải "khớp" hết trước khi chạy (xem mục 4)
# Thí nghiệm A
php artisan ai:eval --suite=plan --mode=engine   --out=storage/app/A2.csv
php artisan ai:eval --suite=plan --mode=llm_data --out=storage/app/A1b.csv --sleep=6
php artisan ai:eval --suite=plan --mode=llm      --out=storage/app/A1.csv  --sleep=6
# Thí nghiệm B (chạy mỗi chế độ 3 lần, đặt tên _1 _2 _3)
php artisan ai:eval --suite=copilot --mode=verified  --out=storage/app/B2_1.csv --sleep=6
php artisan ai:eval --suite=copilot --mode=unchecked --out=storage/app/B1_1.csv --sleep=6
# F2 (rồi chấm cột judge* trong Excel)
php artisan ai:eval --suite=explain --out=storage/app/F2.csv --sleep=6
# F1 trên câu người khác viết (sau khi điền eval_brief_peer.json)
php artisan ai:eval --suite=brief --engine=llm   --file=database/ai/eval_brief_peer.json --out=storage/app/F1_peer_1.csv
php artisan ai:eval --suite=brief --engine=rules --file=database/ai/eval_brief_peer.json --out=storage/app/F1_peer_rules.csv
```

Sau khi người dùng thật dùng thử (mục 3), lấy **tỷ lệ áp dụng đề xuất** ở Admin → Nhật ký hỏi đáp & AI → tab AI La bàn (xuất CSV).

### Bảng nên có trong quyển

| Bảng | Nguồn |
|---|---|
| A: A1 / A1b / A2 theo: % mục có thật, lỗi cứng / chuyến, % chuyến không lỗi cứng, cảnh báo, độ dày, phủ sở thích, độ trễ, chi phí | `A1.csv`, `A1b.csv`, `A2.csv` |
| A: lỗi cứng theo mã (đóng cửa, chồng giờ, không kịp đi) | cột `codes` |
| B: B1 / B2 theo: lỗi cứng mới, % yêu cầu đạt (đúng loại + giải quyết đúng), số bước / sửa, độ trễ; tách theo nhóm yêu cầu và theo nguồn (`author` / `peer`) | `B1_*.csv`, `B2_*.csv` (trung bình 3 lần, ghi khoảng min–max) |
| F1: luật vs La bàn, tách tác giả / người khác viết | `F1_*.csv` |
| F2: % qua guard, đoạn bị thay, điểm trung thực / hữu ích do người chấm | `F2.csv` |
| Người dùng: thời gian, số thao tác, SUS, tỷ lệ áp dụng | `docs/ai/THU_NGHIEM_NGUOI_DUNG.md` |

**Cách kết luận đúng mức:** so sánh chính là **A1b với A2** (cùng dữ liệu, khác ở engine) và **B1 với B2** (cùng LLM, khác ở kiểm chứng). Bộ nhỏ (20–30 mẫu) nên chỉ nói "trên bộ mẫu này", kèm khoảng dao động giữa các lần chạy.

## 3. Thử với người dùng

Bộ tài liệu sẵn để in: `docs/ai/THU_NGHIEM_NGUOI_DUNG.md` (kịch bản 3 nhiệm vụ, phiếu SUS 10 câu tiếng Việt, cách tính điểm, phiếu ghi chép, 3 câu hỏi mở). 8–10 người, mỗi người ~25 phút.

## 4. Đóng băng lời nhắc

`database/ai/prompt_lock.json` lưu "vân tay" (sha256) của lời nhắc + schema mỗi tính năng kèm `prompt_version`. Test `AiEvalSuitesTest::test_prompts_are_frozen_by_version` **báo đỏ** nếu lời nhắc đổi mà `prompt_version` không đổi. Như vậy mọi số liệu trong quyển gắn đúng một phiên bản lời nhắc.

Khi cần sửa lời nhắc: sửa → tăng version trong `config/ai.php` (vd `copilot-2`) → `php artisan ai:prompts --lock` → chạy lại đánh giá của tính năng đó.

## 5. Danh sách kiểm tra đóng băng (AI-7)

- [ ] `php artisan test`: toàn bộ qua.
- [ ] `php artisan ai:prompts`: mọi dòng "khớp".
- [ ] Rút mạng hoặc đặt `AI_PLANNER_ENABLED=false`: tạo chuyến từ một câu (luật), tóm tắt (câu mẫu), Sức khỏe chuyến đi + nút "Sửa" vẫn chạy; Hỏi đáp trả lời bằng trích đoạn. Đây là kịch bản demo khi mất mạng.
- [ ] `.env` thật: `AI_LOG_TEXT=false` (không lưu nguyên văn câu người dùng), `AI_DAILY_LIMIT` hợp lý, khóa API không nằm trong mã nguồn / zip nộp.
- [ ] Trang chính sách riêng tư nêu: câu mô tả và lịch (tên nơi, giờ) được gửi tới nhà cung cấp AI; không gửi email, số điện thoại, tên người trong chuyến.
- [ ] Xóa dữ liệu thử: chuyến test, `tbl_ai_runs` của buổi chạy thử (nếu muốn bắt đầu đo sạch).

## 6. Kịch bản demo 7 phút

1. **F1** (1'): `/len-ke-hoach/de-xuat`, câu mẫu có "bạn mình ăn chay" → vé hành trình 3 màu, cuống vé ghi điều chưa lọc được → Tạo lịch.
2. **Engine** (1'): lịch sinh ra, Sức khỏe chuyến đi 0 lỗi cứng; mở "vì sao có mục này".
3. **F2** (1'): Tóm tắt bằng AI, bấm chip tên → cuộn tới mục.
4. **F3** (2'): kéo dồn vài mục vào ngày 1 → Hỏi La bàn "ngày 1 nhẹ hơn" → bản nháp viền vàng + phiếu kiểm chứng → Áp dụng → Hoàn tác. Thêm "dời <bảo tàng> sang thứ Hai" để thấy La bàn từ chối đề xuất gây lỗi cứng.
5. **F5** (1'): "Ngày 2 có cần mang áo mưa không?" → trả lời có nguồn, bấm nguồn "Ngày 2 trong lịch".
6. **Admin** (1'): tab AI La bàn: tỷ lệ áp dụng, độ trễ, chi phí. Nếu hội đồng hỏi "AI có bịa không": mở bảng thí nghiệm A và B.

## 7. Tệp

Mới: `app/Services/Ai/Eval/{EvalKit,PlanExperiment,CopilotExperiment,ExplainExperiment}.php`, `app/Services/Ai/PromptLock.php`, `app/Console/Commands/AiPrompts.php`, `database/ai/{eval_plan,eval_copilot,eval_explain,eval_brief_peer,prompt_lock}.json`, `tests/Feature/AiEvalSuitesTest.php`, `docs/ai/THU_NGHIEM_NGUOI_DUNG.md`.
Sửa: `app/Console/Commands/AiEval.php` (thêm suite, `--mode`, `--file`, bảng theo nguồn câu), `CopilotService.php` (tùy chọn `verify` chỉ cho B1; phiếu kiểm chứng có thêm `newHard` và mã vấn đề), `PlannerService.php` (`candidatesFor()` cho A1b).

Toàn bộ bộ test: **551 test qua**.
