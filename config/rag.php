<?php

/*
|--------------------------------------------------------------------------
| Giai đoạn G: RAG cho cẩm nang (hỏi đáp có trích nguồn)
|--------------------------------------------------------------------------
| Mọi lựa chọn (mô hình nhúng, mô hình sinh câu trả lời, ngưỡng từ chối...) đổi được bằng .env,
| không phải sửa code. Xem docs/HUONG_DAN_G.md.
*/

return [

    // ---- Nhúng (embedding) -------------------------------------------------------------
    'embed' => [
        // local  : băm từ vựng (không cần key, không gọi mạng): chạy thử, kiểm thử và làm mốc so sánh
        // openai : dịch vụ nào theo chuẩn POST {base_url}/embeddings (OpenAI, Gemini bản tương thích, Ollama...)
        // voyage : Voyage AI (Anthropic khuyên dùng cho nhúng)
        'driver' => env('RAG_EMBED_DRIVER', 'local'),
        'batch' => (int) env('RAG_EMBED_BATCH', 32),
        'timeout' => 40,

        'local' => ['dims' => 512],

        'openai' => [
            'base_url' => env('RAG_EMBED_BASE_URL', 'https://api.openai.com/v1'),
            'key' => env('RAG_EMBED_KEY'),
            'model' => env('RAG_EMBED_MODEL', 'text-embedding-3-small'),
            'dimensions' => env('RAG_EMBED_DIMS') ? (int) env('RAG_EMBED_DIMS') : null,
        ],

        'voyage' => [
            'base_url' => env('VOYAGE_BASE_URL', 'https://api.voyageai.com/v1'),
            'key' => env('VOYAGE_API_KEY'),
            'model' => env('VOYAGE_MODEL', 'voyage-3.5'),
        ],
    ],

    // ---- Sinh câu trả lời ----------------------------------------------------------------
    'llm' => [
        // anthropic : Claude qua Messages API
        // openai    : chuẩn POST {base_url}/chat/completions (OpenAI, Gemini bản tương thích, Ollama...)
        // none      : không gọi LLM, trả lời bằng các đoạn trích nguyên văn (phương án chạy không LLM)
        'driver' => env('RAG_LLM_DRIVER', 'none'),
        'timeout' => 45,
        'max_tokens' => 600,
        'temperature' => 0.2,

        'anthropic' => [
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'key' => env('ANTHROPIC_API_KEY'),
            'model' => env('RAG_LLM_MODEL', 'claude-haiku-5-5'),
            'version' => '2023-06-01',
        ],

        'openai' => [
            'base_url' => env('RAG_LLM_BASE_URL', 'https://api.openai.com/v1'),
            'key' => env('RAG_LLM_KEY'),
            'model' => env('RAG_LLM_MODEL', 'gpt-4o-mini'),
        ],

        // Giá mỗi 1 triệu token (USD) để báo cáo chi phí ở rag:eval. Để trống thì chỉ hiện số token.
        'price_in' => env('RAG_PRICE_IN') !== null ? (float) env('RAG_PRICE_IN') : null,
        'price_out' => env('RAG_PRICE_OUT') !== null ? (float) env('RAG_PRICE_OUT') : null,
    ],

    // ---- Chia đoạn ------------------------------------------------------------------------
    'chunk' => [
        'min_words' => 120,   // đoạn ngắn hơn thì gộp với mục kế tiếp
        'max_words' => 380,   // không đoạn nào dài hơn (tài liệu: 200 đến 400 từ)
    ],

    // ---- Truy xuất lai ----------------------------------------------------------------------
    'retrieval' => [
        'top_k' => 6,
        'rrf_k' => 60,          // hằng số của Reciprocal Rank Fusion
        'month_boost' => 1.15,  // đoạn có "tháng" trùng với câu hỏi được nhân điểm này
        'vector_pool' => 40,
        // Cổng "có đáng tin để trả lời không". Qua cổng mới gọi LLM; không qua thì từ chối ngay (không tốn chi phí).
        // Vượt cổng nếu cosine >= ngưỡng HOẶC độ phủ từ khóa >= min_coverage.
        'gate' => [
            'min_cosine' => ['local' => 0.12, 'openai' => 0.30, 'voyage' => 0.30, 'default' => 0.30],
            'min_coverage' => 0.6,
        ],
    ],

    // ---- Giới hạn và bộ nhớ đệm ------------------------------------------------------------
    'limits' => [
        'daily_per_user' => (int) env('RAG_DAILY_LIMIT', 30),
        'cache_ttl' => 21600,       // 6 giờ: câu hỏi lặp lại không tốn lượt và không gọi LLM
        'max_question_chars' => 300,
    ],

    // Câu hỏi/đoạn văn chứa các cụm này thì thêm dòng nhắc "kiểm tra nguồn chính thức" (tài liệu mục 9.4).
    // Viết không dấu, chữ thường (so khớp trên bản đã bỏ dấu).
    'volatile' => [
        'visa', 'thi thuc', 'gia ', ' phi', 'le phi', 'gio mo cua', 'gio hoat dong', 'quy dinh', 'thu tuc',
        'giay phep', 'giay to', 'bang lai', 'thue xe', 'esim', 'sim ', 'ty gia', 'doi tien', 'bao nhieu tien',
    ],
];
