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
    ],

    // anthropic | openai | none. Mặc định dùng chung driver với RAG để chỉ cần một khóa API.
    'driver' => env('AI_DRIVER', env('RAG_LLM_DRIVER', 'none')),

    // Model theo tác vụ: việc nhẹ (đọc câu, viết lời) dùng model nhỏ, nhanh, rẻ.
    'models' => [
        'anthropic' => [
            'brief' => env('AI_MODEL_BRIEF', 'claude-haiku-5-5'),
            'explain' => env('AI_MODEL_EXPLAIN', 'claude-haiku-5-5'),
            'copilot' => env('AI_MODEL_COPILOT', 'claude-sonnet-5-5'),
        ],
        // chuẩn OpenAI (OpenAI, Gemini qua cổng tương thích, Ollama...): không đặt AI_MODEL_* thì dùng chung RAG_LLM_MODEL
        'openai' => [
            'brief' => env('AI_MODEL_BRIEF', env('RAG_LLM_MODEL', 'gpt-4o-mini')),
            'explain' => env('AI_MODEL_EXPLAIN', env('RAG_LLM_MODEL', 'gpt-4o-mini')),
            'copilot' => env('AI_MODEL_COPILOT', env('RAG_LLM_MODEL', 'gpt-4o')),
        ],
    ],

    'timeout' => [   // giây, MỖI lệnh gọi HTTP; quá hạn thì lùi về chế độ cơ bản
        'brief' => (int) env('AI_TIMEOUT_BRIEF', 20),
        'explain' => (int) env('AI_TIMEOUT_EXPLAIN', 25),
        'copilot' => (int) env('AI_TIMEOUT_COPILOT', 20),
    ],
    // Model có "suy nghĩ" (Gemini 3.x, 4) tính cả token suy luận vào đây: thấy JSON bị cắt (outcome = invalid) thì tăng lên.
    'max_tokens' => [
        'brief' => (int) env('AI_MAX_TOKENS_BRIEF', 900),
        'explain' => (int) env('AI_MAX_TOKENS_EXPLAIN', 1400),
        'copilot' => (int) env('AI_MAX_TOKENS_COPILOT', 900),
    ],
    'temperature' => 0.1,

    // Chỉ cho driver openai (kể cả Gemini qua cổng tương thích):
    //   openai_json_mode: schema | object (nhà cung cấp không nhận json_schema thì dùng object)
    //   reasoning_effort: low | medium | high; để trống = không gửi. Model "suy nghĩ" như Gemini 3.x đặt low để trả lời nhanh hơn.
    'openai_json_mode' => env('AI_OPENAI_JSON_MODE', 'schema'),
    'reasoning_effort' => env('AI_REASONING_EFFORT'),

    // Đổi prompt hay schema thì tăng số này: cache và log phân biệt được kết quả của từng phiên bản.
    // brief-3 = lời nhắc brief-2 + ghép luật (SearchIntent) cho 3 trường bắt buộc khi LLM bỏ trống
    'prompt_version' => ['brief' => 'brief-3', 'explain' => 'explain-1', 'copilot' => 'copilot-1'],

    // La bàn Copilot: tối đa 4 bước LLM / lượt (tìm, đề xuất, sửa); quá ngân sách thời gian thì dừng và báo người dùng chia nhỏ yêu cầu.
    'copilot' => ['budget_ms' => (int) env('AI_COPILOT_BUDGET_MS', 30000)],

    'limits' => [
        'daily_per_user' => (int) env('AI_DAILY_LIMIT', 40),   // đếm trong tbl_ai_runs (engine = llm)
        'max_input_chars' => 500,
    ],

    // Ngắt mạch: lỗi liên tiếp quá ngưỡng thì không gọi LLM trong N giây (giống cool-down của Overpass).
    'cooldown' => ['fail_threshold' => 3, 'seconds' => 90],

    // Giá USD / 1 triệu token, chỉ để báo cáo chi phí ở ai:eval. Để trống thì chỉ hiện số token.
    'price_in' => env('AI_PRICE_IN') !== null ? (float) env('AI_PRICE_IN') : null,
    'price_out' => env('AI_PRICE_OUT') !== null ? (float) env('AI_PRICE_OUT') : null,

    // Lưu nguyên văn câu người dùng trong log để phân tích lỗi? Mặc định KHÔNG (chỉ lưu độ dài, kết quả, cờ).
    'log_text' => (bool) env('AI_LOG_TEXT', false),
];
