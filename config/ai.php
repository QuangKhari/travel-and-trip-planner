<?php

/*
|--------------------------------------------------------------------------
| La bàn: trợ lý AI của Trip Planner (tài liệu "Travela AI Trip Planner v2.2", mục 6.2)
|--------------------------------------------------------------------------
| Nguyên tắc: LLM đề xuất, engine kiểm chứng. Mọi lệnh gọi LLM đi qua App\Services\Ai\AiGateway:
| cờ tính năng -> hạn mức -> ngắt mạch -> timeout -> ghi log (tbl_ai_runs) -> đường lùi (luật / câu mẫu).
| Tắt AI (AI_PLANNER_ENABLED=false) thì Planner vẫn dùng được hoàn toàn: F1 lùi về SearchIntent, F2 lùi về câu mẫu từ reasons.
*/

return [
    'enabled' => (bool) env('AI_PLANNER_ENABLED', false),

    'features' => [
        'brief' => (bool) env('AI_FEATURE_BRIEF', true),       // F1: hiểu câu mô tả chuyến đi, điền form
        'explain' => (bool) env('AI_FEATURE_EXPLAIN', true),   // F2: tóm tắt và giải thích lịch
        'copilot' => (bool) env('AI_FEATURE_COPILOT', true),   // F3: La bàn Copilot (sửa lịch bằng lời, engine kiểm chứng)
        'ask' => (bool) env('AI_FEATURE_ASK', true),           // F5: hỏi về chuyến trong ngăn La bàn (dùng AskService + hạn mức của Hỏi đáp)
        'packing' => (bool) env('AI_FEATURE_PACKING', true),   // A-13: danh sách đồ mang theo (lùi về danh sách theo luật)
        // Gợi ý để chọn trong ngăn La bàn (món ăn, điểm tham quan, lễ hội, cảnh báo dông / sự cố): chạy bằng luật, KHÔNG cần khóa LLM,
        // nên ngăn La bàn vẫn mở được khi AI_PLANNER_ENABLED=false (khi đó câu sửa lịch tự do báo chưa dùng được).
        'suggest' => (bool) env('AI_FEATURE_SUGGEST', true),
        // Đợt C mục 20: đọc ẢNH vé / xác nhận đặt chỗ để điền sẵn biểu mẫu (chữ dán vào đọc bằng luật, không cần AI)
        'booking_read' => (bool) env('AI_FEATURE_BOOKING_READ', true),
    ],

    // anthropic | openai | none. Mặc định dùng chung driver với RAG để chỉ cần một khóa API.
    'driver' => env('AI_DRIVER') ?: (env('RAG_LLM_DRIVER') ?: 'none'),

    // Model theo tác vụ: việc nhẹ (đọc câu, viết lời) dùng model nhỏ, nhanh, rẻ.
    'models' => [
        'anthropic' => [
            'brief' => env('AI_MODEL_BRIEF') ?: 'claude-haiku-5-5',
            'explain' => env('AI_MODEL_EXPLAIN') ?: 'claude-haiku-5-5',
            'copilot' => env('AI_MODEL_COPILOT') ?: 'claude-sonnet-5-5',
            'packing' => env('AI_MODEL_PACKING') ?: 'claude-haiku-5-5',
            'booking_read' => env('AI_MODEL_BOOKING_READ') ?: 'claude-haiku-5-5',
        ],
        // chuẩn OpenAI (OpenAI, Gemini qua cổng tương thích, Ollama...): không đặt AI_MODEL_* thì dùng chung RAG_LLM_MODEL
        'openai' => [
            // dòng "AI_MODEL_COPILOT=" để trống trong .env cũng được coi là chưa đặt (trước đây chuỗi rỗng bị gửi đi -> HTTP 400 "model is not specified")
            'brief' => env('AI_MODEL_BRIEF') ?: (env('RAG_LLM_MODEL') ?: 'gpt-4o-mini'),
            'explain' => env('AI_MODEL_EXPLAIN') ?: (env('RAG_LLM_MODEL') ?: 'gpt-4o-mini'),
            'copilot' => env('AI_MODEL_COPILOT') ?: (env('RAG_LLM_MODEL') ?: 'gpt-4o'),
            'packing' => env('AI_MODEL_PACKING') ?: (env('RAG_LLM_MODEL') ?: 'gpt-4o-mini'),
            'booking_read' => env('AI_MODEL_BOOKING_READ') ?: (env('RAG_LLM_MODEL') ?: 'gpt-4o-mini'),   // cần model đọc được ảnh
        ],
    ],

    // Gói dự phòng (FallbackLlm): model chính báo hết hạn mức (429), quá tải (5xx) hoặc quá giờ thì gọi model này.
    // Gemini tính hạn mức theo dự án + model: cùng khóa, model khác (vd gemini-3.1-flash-lite) đã có hạn mức riêng.
    // Khóa của dự án Google khác / nhà cung cấp khác: đặt thêm AI_FALLBACK_KEY, AI_FALLBACK_BASE_URL, AI_FALLBACK_DRIVER.
    // Để trống AI_FALLBACK_MODEL = không dùng dự phòng. ai:eval luôn tắt dự phòng (số liệu phải từ một model).
    'fallback' => [
        'model' => env('AI_FALLBACK_MODEL') ?: null,
        'driver' => env('AI_FALLBACK_DRIVER') ?: null,      // để trống = như AI_DRIVER
        'key' => env('AI_FALLBACK_KEY') ?: null,            // để trống = dùng chung khóa
        'base_url' => env('AI_FALLBACK_BASE_URL') ?: null,  // để trống = như model chính
        'cooldown' => (int) (env('AI_FALLBACK_COOLDOWN') ?: 600),   // giây đi thẳng sang dự phòng sau khi model chính báo 429
        // Dự phòng lớp 2 (vd Cerebras): gọi khi cả model chính lẫn dự phòng 1 đều hết lượt / quá tải. Để trống = không dùng.
        'second' => [
            'model' => env('AI_FALLBACK2_MODEL') ?: null,
            'driver' => env('AI_FALLBACK2_DRIVER') ?: null,
            'key' => env('AI_FALLBACK2_KEY') ?: null,
            'base_url' => env('AI_FALLBACK2_BASE_URL') ?: null,
        ],
        // Hỏi đáp và F5 (cấu hình RAG_LLM_*) dùng chung chuỗi dự phòng này. false = tắt riêng cho Hỏi đáp.
        'rag' => (bool) env('AI_FALLBACK_RAG', true),
    ],

    // A/B thử nghiệm trên người dùng thật (App\Services\Ai\AiExperiments): chia nhóm cố định theo người dùng, A = cấu hình hiện tại.
    // Khóa được đổi ở nhóm B: model, temperature, reasoning_effort, schema_repair, max_tokens. Xem kết quả: trang Nhật ký AI hoặc
    // php artisan ai:experiment copilot-model. Mỗi tính năng chỉ chạy MỘT thử nghiệm đang bật (thử nghiệm đầu tiên khớp).
    'experiments' => [
        'copilot-model' => [
            'feature' => 'copilot',
            'active' => (bool) env('AI_EXP_COPILOT', false),
            'split' => (int) env('AI_EXP_COPILOT_SPLIT', 50),
            'started' => env('AI_EXP_COPILOT_START'),
            'variants' => ['A' => [], 'B' => ['model' => env('AI_EXP_COPILOT_MODEL_B', 'gemini-3.5-flash')]],
        ],
        'brief-repair' => [
            'feature' => 'brief',
            'active' => (bool) env('AI_EXP_BRIEF_REPAIR', false),
            'split' => 50,
            'started' => env('AI_EXP_BRIEF_REPAIR_START'),
            'variants' => ['A' => [], 'B' => ['schema_repair' => false]],
        ],
    ],

    // Hồ sơ model cho thí nghiệm so sánh (php artisan ai:compare / ai:eval --profile=...): mỗi hồ sơ đủ driver + model + khóa + địa chỉ,
    // nên so được nhiều nhà cung cấp trong một lần chạy mà không sửa .env. Khóa để trống thì hồ sơ đó bị bỏ qua (báo rõ).
    'profiles' => [
        'gemini-flash-lite' => ['driver' => 'openai', 'model' => env('AI_PROFILE_GEMINI_MODEL', 'gemini-3.5-flash-lite'), 'key' => env('RAG_LLM_KEY'),
            'base_url' => env('RAG_LLM_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta/openai'), 'json_mode' => 'schema', 'reasoning' => env('AI_REASONING_EFFORT')],
        'haiku' => ['driver' => 'anthropic', 'model' => 'claude-haiku-5-5', 'key' => env('ANTHROPIC_API_KEY')],
        'sonnet' => ['driver' => 'anthropic', 'model' => 'claude-sonnet-5-5', 'key' => env('ANTHROPIC_API_KEY')],
        'gpt-4o-mini' => ['driver' => 'openai', 'model' => 'gpt-4o-mini', 'key' => env('OPENAI_API_KEY'), 'base_url' => 'https://api.openai.com/v1', 'json_mode' => 'schema'],
        'groq-oss' => ['driver' => 'openai', 'model' => 'openai/gpt-oss-120b', 'key' => env('AI_FALLBACK_KEY'), 'base_url' => 'https://api.groq.com/openai/v1', 'json_mode' => 'object'],
    ],

    'timeout' => [   // giây, MỖI lệnh gọi HTTP; quá hạn thì lùi về chế độ cơ bản
        'brief' => (int) env('AI_TIMEOUT_BRIEF', 20),
        'explain' => (int) env('AI_TIMEOUT_EXPLAIN', 25),
        'copilot' => (int) env('AI_TIMEOUT_COPILOT', 20),
        'packing' => (int) env('AI_TIMEOUT_PACKING', 20),
        'booking_read' => (int) env('AI_TIMEOUT_BOOKING_READ', 25),
    ],
    // Model có "suy nghĩ" (Gemini 3.x, 4) tính cả token suy luận vào đây: thấy JSON bị cắt (outcome = invalid) thì tăng lên.
    'max_tokens' => [
        'brief' => (int) env('AI_MAX_TOKENS_BRIEF', 900),
        'explain' => (int) env('AI_MAX_TOKENS_EXPLAIN', 1400),
        'copilot' => (int) env('AI_MAX_TOKENS_COPILOT', 900),
        'packing' => (int) env('AI_MAX_TOKENS_PACKING', 1200),
        'booking_read' => (int) env('AI_MAX_TOKENS_BOOKING_READ', 600),
    ],
    'temperature' => 0.1,

    // Chỉ cho driver openai (kể cả Gemini qua cổng tương thích):
    //   openai_json_mode: schema | object (nhà cung cấp không nhận json_schema thì dùng object)
    //   reasoning_effort: low | medium | high; để trống = không gửi. Model "suy nghĩ" như Gemini 3.x đặt low để trả lời nhanh hơn.
    'openai_json_mode' => env('AI_OPENAI_JSON_MODE', 'schema'),
    'reasoning_effort' => env('AI_REASONING_EFFORT'),

    // Đổi prompt hay schema thì tăng số này: cache và log phân biệt được kết quả của từng phiên bản.
    // brief-3 = lời nhắc brief-2 + ghép luật (SearchIntent) cho 3 trường bắt buộc khi LLM bỏ trống
    'prompt_version' => ['brief' => 'brief-3', 'explain' => 'explain-1', 'copilot' => 'copilot-1', 'packing' => 'packing-1'],

    // La bàn Copilot: tối đa 4 bước LLM / lượt (tìm, đề xuất, sửa); quá ngân sách thời gian thì dừng và báo người dùng chia nhỏ yêu cầu.
    'copilot' => ['budget_ms' => (int) env('AI_COPILOT_BUDGET_MS', 30000)],

    'limits' => [
        'daily_per_user' => (int) env('AI_DAILY_LIMIT', 40),   // đếm trong tbl_ai_runs (engine = llm)
        'max_input_chars' => 500,
    ],

    // Ngắt mạch: lỗi liên tiếp quá ngưỡng thì không gọi LLM trong N giây (giống cool-down của Overpass).
    'cooldown' => ['fail_threshold' => 3, 'seconds' => 90],

    // Tự sửa khi model trả JSON sai khuôn / thiếu trường bắt buộc: gửi lại MỘT lần kèm câu báo lỗi rồi mới lùi về chế độ cơ bản.
    'schema_repair' => (bool) env('AI_SCHEMA_REPAIR', true),

    // Bảng giá USD / 1 triệu token theo model (App\Services\Ai\AiCost). Tra theo tên model đúng nguyên văn, rồi theo tên đã bỏ tiền tố
    // nhà cung cấp ("models/", "openai/"...). "long" = giá khác khi lời nhắc vượt ngưỡng token (vd Claude Haiku 5.5 trên 100K: x5).
    // Giá niêm yết của nhà cung cấp, cập nhật ngày ghi ở pricing_checked — kiểm lại trước khi đưa số liệu vào quyển.
    // Gói miễn phí (Gemini free tier, Groq free) vẫn tính theo giá niêm yết: đó là chi phí khi chạy thật.
    'pricing_checked' => '2026-11-18',
    'pricing' => [
        'gemini-3.5-flash-lite' => ['in' => 0.30, 'out' => 2.50],
        'gemini-3.1-flash-lite' => ['in' => 0.25, 'out' => 1.50],
        'gemini-3.5-flash' => ['in' => 1.50, 'out' => 9.00],
        'gpt-oss-120b' => ['in' => 0.15, 'out' => 0.60],          // Groq; Cerebras có giá riêng: đặt AI_PRICE_* hoặc thêm dòng
        'claude-haiku-5-5' => ['in' => 0.10, 'out' => 0.50, 'long' => ['over' => 100000, 'x' => 5]],
        'claude-sonnet-5-5' => ['in' => 2.00, 'out' => 10.00],
        'gpt-4o-mini' => ['in' => 0.15, 'out' => 0.60],
        'gpt-4o' => ['in' => 2.50, 'out' => 10.00],
    ],
    // Giá dùng cho model KHÔNG có trong bảng trên (để trống = không tính được, báo cáo ghi "chưa có giá").
    'price_in' => is_numeric(env('AI_PRICE_IN')) ? (float) env('AI_PRICE_IN') : null,
    'price_out' => is_numeric(env('AI_PRICE_OUT')) ? (float) env('AI_PRICE_OUT') : null,

    // Lưu nguyên văn câu người dùng trong log để phân tích lỗi? Mặc định KHÔNG (chỉ lưu độ dài, kết quả, cờ).
    'log_text' => (bool) env('AI_LOG_TEXT', false),
];
