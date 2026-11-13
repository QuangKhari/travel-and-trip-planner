<?php

/**
 * Cấu hình Planner (mục 0.1-B/E/K của kế hoạch v6). Một nguồn duy nhất: không rải số "ma thuật" trong code.
 * Mọi giá trị ở đây là ước lượng; mục dùng giá trị mặc định phải được ghi "ước lượng" trong lý do chọn.
 * Tên cột khớp migration 2026_10_26_000001_link_content_and_planner: durationMinutes, difficulty (easy|medium|hard), indoor.
 */
return [
    // Mọi lần lưu chuyến phải gửi rev (bản đang sửa). Thiếu rev -> 422, rev cũ -> 409 REVISION_CONFLICT.
    // Chỉ đặt PLANNER_REQUIRE_REVISION=false tạm thời khi còn client cũ chưa gửi rev.
    'require_revision' => env('PLANNER_REQUIRE_REVISION', true),
    // Số phiên bản lịch sử giữ lại cho mỗi chuyến (tbl_trip_revisions), để xem lại / khôi phục.
    'revision_history_keep' => (int) env('PLANNER_REVISION_HISTORY_KEEP', 30),
    /*
     * ===== Một ngày của Planner (MỘT nguồn, đọc qua App\Services\Planner\PlannerRules; trình duyệt nhận bản sao qua cfg.rules) =====
     *   start_min    08:00  giờ bắt đầu tự xếp lịch
     *   soft_end_min 18:00  hết khung hoạt động chính. Sức chứa một ngày = soft_end - start = 600 phút.
     *                       Độ dày = (hoạt động + di chuyển + bữa ăn chừa sẵn + đệm) / sức chứa; vẫn được xếp mục buổi tối sau 18:00.
     *   end_min      22:00  kết thúc sau giờ này là "muộn"
     * Các con số suy ra (không khai báo lại ở nơi khác):
     *   - availableMin = soft_end - start
     *   - itemBudgetMin = tổng thời lượng các mục (kể cả bữa ăn) vừa sức chứa khi còn đệm và ~travel_reserve_min đi lại
     *     = floor((availableMin / (1 + buffer_ratio) - travel_reserve_min) / 10) * 10 = 480
     *     Dùng cho kiểm tra lịch trình mẫu (admin) và quỹ hoạt động khi tự tạo lịch (PlannerEngine trừ bữa ăn).
     */
    'day' => [
        'start_min' => 480,
        'soft_end_min' => 1080,
        'end_min' => 1320,
    ],
    'meal_allowance_min' => 60,         // ngày chưa có mục ăn uống nào: chừa 60 phút ăn trưa
    'buffer_ratio' => 0.1,              // đệm 10% (nghỉ, chờ, chụp ảnh)
    'travel_reserve_min' => 60,         // thời gian đi lại chừa sẵn khi tính quỹ thời lượng các mục
    'load_warn' => 0.85,                // độ dày từ 85% là "hơi dày", từ 100% là "quá dày"
    'travel_warn_min' => 180,           // đi lại quá 3 giờ một ngày
    'day_items_warn' => 7,              // hơn 7 mục một ngày là hơi nhiều (trang sửa, TripHealth, cảnh báo khi lưu)
    'day_cost_over_avg' => 0.4,         // chi phí một ngày cao hơn trung bình các ngày 40% thì nhắc

    // Buổi trong ngày (phút kể từ 00:00, [start, end)). Mục chọn buổi không bắt đầu trước `start`; giờ thuộc buổi nào tra theo khoảng.
    'slots' => [
        'morning' => ['label' => 'Sáng', 'start' => 480, 'end' => 690],      // 08:00 - 11:30
        'noon' => ['label' => 'Trưa', 'start' => 690, 'end' => 810],         // 11:30 - 13:30
        'afternoon' => ['label' => 'Chiều', 'start' => 810, 'end' => 1080],  // 13:30 - 18:00
        'evening' => ['label' => 'Tối', 'start' => 1080, 'end' => 1440],     // 18:00 trở đi
    ],

    // Mục tự thêm (note) đoán loại theo tên. Viết theo cú pháp chung của PCRE và JavaScript (không dấu phân cách, không cờ).
    'kind_patterns' => [
        'lodging' => 'khách sạn|hotel|homestay|resort|nhà nghỉ|hostel|villa|lưu trú|căn hộ|bungalow',
        'meal' => 'nhà hàng|cà phê|cafe|coffee|phở|bún|buffet|cơm|lẩu|ăn uống|ăn sáng|ăn trưa|ăn tối|restaurant|(^|\\s)quán(\\s|$)',
    ],
    // Địa điểm trong kho (type = place) nhận diện theo kind: quán ăn là bữa ăn, khách sạn là lưu trú (cùng ở máy chủ và trình duyệt)
    'kind_places' => [
        'meal' => ['restaurant', 'cafe', 'fast_food', 'food_court'],
        'lodging' => ['hotel', 'guest_house', 'hostel', 'motel', 'apartment', 'resort'],
    ],

    // ---- Tuyến đường: MỌI phép tính đi qua RouteService (máy chủ) và planner-geo.js (xem trước ở trình duyệt, cùng tham số) ----
    'route' => [
        'provider' => env('PLANNER_ROUTE_PROVIDER', 'haversine'),   // haversine | osrm
        'osrm_url' => env('PLANNER_OSRM_URL', ''),                   // ví dụ https://router.project-osrm.org
        'mode' => env('PLANNER_TRAVEL_MODE', 'driving'),             // phương tiện mặc định
        'factor' => 1.3,                                              // hệ số đường vòng so với đường chim bay
        'min_leg_km' => 0.5,                                          // hai điểm gần hơn mức này (chim bay) coi là cùng một khu, không tính đoạn đi
        'modes' => [                                                  // tốc độ trung bình và hồ sơ OSRM theo phương tiện
            'driving' => ['label' => 'Ô tô', 'speed_kmh' => 40, 'osrm' => 'driving'],
            'motorbike' => ['label' => 'Xe máy', 'speed_kmh' => 30, 'osrm' => 'driving'],
            'walking' => ['label' => 'Đi bộ', 'speed_kmh' => 4.5, 'osrm' => 'foot'],
        ],
        // T-05…T-08: phương tiện cho TỪNG chặng (người dùng chọn trên đoạn di chuyển). Giá là THAM KHẢO do nhóm đặt, giao diện ghi "ước tính".
        //   wait_min: thời gian chờ / gửi xe cộng thêm mỗi chặng; cost_km: đồng / km cho cả nhóm (taxi, xăng xe máy); cost_fixed: đồng / người / lượt (xe buýt)
        //   co2_km: gam CO2 / km; co2_per: 'vehicle' (mỗi xe: ô tô 4 người, xe máy 2 người) | 'person' (xe buýt: mỗi khách). Hệ số tham khảo, giao diện ghi "ước tính".
        'leg_modes' => [
            'driving' => ['label' => 'Ô tô / taxi', 'speed_kmh' => 40, 'wait_min' => 0, 'cost_km' => 15000, 'cost_fixed' => 0, 'gmaps' => 'driving', 'icon' => 'fa-car-side', 'co2_km' => 170, 'co2_per' => 'vehicle'],
            'motorbike' => ['label' => 'Xe máy', 'speed_kmh' => 30, 'wait_min' => 0, 'cost_km' => 1500, 'cost_fixed' => 0, 'gmaps' => 'driving', 'icon' => 'fa-motorcycle', 'co2_km' => 70, 'co2_per' => 'vehicle'],
            'bus' => ['label' => 'Xe buýt', 'speed_kmh' => 20, 'wait_min' => 10, 'cost_km' => 0, 'cost_fixed' => 7000, 'gmaps' => 'transit', 'icon' => 'fa-bus', 'co2_km' => 30, 'co2_per' => 'person'],
            'walking' => ['label' => 'Đi bộ', 'speed_kmh' => 4.5, 'wait_min' => 0, 'cost_km' => 0, 'cost_fixed' => 0, 'gmaps' => 'walking', 'icon' => 'fa-walking', 'co2_km' => 0, 'co2_per' => 'person'],
        ],
    ],

    // Quanh tuyến: ngưỡng thời gian đi vòng thêm (phút) để gắn nhãn đáng ghé hay không
    'detour_levels' => [
        ['max' => 5, 'key' => 'great', 'label' => 'Gần như không đi vòng'],
        ['max' => 15, 'key' => 'good', 'label' => 'Đáng ghé'],
        ['max' => 30, 'key' => 'consider', 'label' => 'Cân nhắc'],
        ['max' => null, 'key' => 'far', 'label' => 'Vòng xa'],
    ],

    // Thời lượng mặc định (phút) khi mục chưa có durationMinutes. Địa điểm tra theo nhóm kind (xem kind_groups).
    'default_duration_min' => [
        'place' => ['eat' => 60, 'nature' => 120, 'culture' => 90, 'see' => 60, 'fun' => 150, 'default' => 60],
        'food' => 60,
        'experience' => 120,
        'event' => 180,
    ],

    'difficulty_rank' => ['easy' => 1, 'medium' => 2, 'hard' => 3],

    // `tbl_external_places.kind` thật trong dữ liệu (đếm ngày 25/10/2026) gom thành 5 nhóm.
    // Kind chưa có trong danh sách (OSM thêm kind mới) rơi vào nhóm 'see'. Cập nhật khi SyncPlaces mang về kind mới.
    'kind_groups' => [
        'eat' => ['restaurant', 'cafe', 'fast_food', 'food_court'],
        'nature' => ['peak', 'hot_spring', 'cave_entrance', 'viewpoint', 'beach', 'waterfall', 'park'],
        'culture' => ['historic', 'place_of_worship', 'museum', 'gallery', 'monument', 'memorial', 'castle', 'ruins', 'temple', 'archaeological_site', 'fort'],
        'fun' => ['theme_park', 'zoo', 'aquarium'],
        'see' => ['attraction'],
    ],

    // "Không thích" => điều kiện loại. Địa điểm không có difficulty nên loại theo kind; trải nghiệm loại theo difficulty.
    'dislikes' => [
        'trekking' => ['experience_difficulty' => ['hard'], 'place_kinds' => ['peak', 'cave_entrance']],
        'crowds' => ['place_kinds' => ['theme_park', 'zoo', 'aquarium']],
        'outdoor' => ['experience_indoor' => [false], 'place_kinds' => ['peak', 'beach', 'viewpoint', 'cave_entrance', 'hot_spring']],
        // không cấm loại nơi nào; chỉ siết quãng đi: tối đa 90 phút di chuyển mỗi ngày, mỗi chặng không quá 25 km
        'long_travel' => ['max_travel_min_per_day' => 90, 'max_leg_km' => 25],
    ],

    // ---- Tuần 5: PlannerEngine ----

    // Đề xuất mở rộng (điểm đến chưa đủ nội dung đã duyệt, hoặc nơi bất kỳ trên bản đồ): bổ sung ứng viên từ OpenStreetMap,
    // ghi rõ "dữ liệu bản đồ, chưa được Travela biên tập". Bán kính lấy dữ liệu (m) và số ứng viên tối đa mỗi nhóm.
    'open_mode' => [
        'enabled' => (bool) env('PLANNER_OPEN_MODE', true),
        'radius_sight_m' => 8000,
        'radius_eat_m' => 4000,
        'max_per_cat' => 40,
    ],

    // Số ngày tối đa cho một lần tạo; số mục "hoạt động" mỗi ngày theo nhịp (không tính bữa ăn)
    'max_days' => 7,
    // Số chuyến tối đa của một người (mọi cách tạo: tạo mới, tự đề xuất, nhân bản, từ tour/lịch trình, API)
    'max_trips_per_user' => (int) env('PLANNER_MAX_TRIPS', 200),
    // budget_factor nhân với quỹ hoạt động = PlannerRules::itemBudgetMin() - các bữa ăn (meals_per_day x meal_minutes)
    'pace' => [
        // target_load / min_load: độ dày tối đa / tối thiểu của một ngày vừa tạo (cùng thang với "Sức khỏe chuyến đi":
        // Nhẹ dưới 50%, Bận từ 85%, Quá tải từ 100%). Dày hơn thì bớt mục điểm thấp; thưa hơn thì bù từ phần còn lại.
        'relaxed' => ['min_per_day' => 2, 'max_per_day' => 3, 'budget_factor' => 0.8, 'target_load' => 0.65, 'min_load' => 0.35],
        'normal' => ['min_per_day' => 3, 'max_per_day' => 4, 'budget_factor' => 1.0, 'target_load' => 0.8, 'min_load' => 0.5],
        'packed' => ['min_per_day' => 4, 'max_per_day' => 6, 'budget_factor' => 1.2, 'target_load' => 0.95, 'min_load' => 0.6],
    ],
    'default_pace' => 'normal',

    // Bữa ăn: mỗi ngày tối đa bao nhiêu mục "ăn uống" (trưa + tối)
    'meals_per_day' => 2,
    'meal_minutes' => 60,

    // Độ khó tối đa theo nhóm (khi người dùng chọn): easy | medium | hard. Mặc định không giới hạn.
    'default_max_difficulty' => 'hard',

    // Điểm (soft constraint). Cộng dồn; điểm chỉ dùng để XẾP HẠNG ứng viên, không phải "độ hợp" tuyệt đối.
    'score' => [
        'must_try' => 30,           // biên tập viên đánh dấu "phải thử/ghé"
        'interest_match' => 18,     // mỗi sở thích khớp (tối đa 2 sở thích được tính)
        'interest_cap' => 2,
        'in_season' => 12,          // trải nghiệm/món/lễ hội có mùa trùng tháng đi
        'event_in_month' => 25,     // lễ hội diễn ra đúng tháng đi
        'rank_max' => 10,           // thang điểm từ trường `rank` của địa điểm (chuẩn hóa theo ứng viên cao nhất)
        'rain_indoor' => 8,         // tháng mưa nhiều: ưu tiên mục trong nhà
        'rain_outdoor' => -8,       // tháng mưa nhiều: trừ mục ngoài trời
        'hot_outdoor_hard' => -6,   // tháng nắng nóng: trừ mục ngoài trời khó
        'avoid_month_outdoor' => -4,
        'meal_has_place' => 3,      // bữa ăn: quán có tọa độ thật được ưu tiên hơn món chưa gắn quán (không tính được đường đi)
    ],
    'hours_max_wait_min' => 90,     // tới sớm hơn giờ mở cửa quá chừng này phút thì thử xếp mục khác trước
    'rainy_share' => 0.4,           // tỷ lệ ngày mưa từ mức này là "mưa nhiều"
    'hot_tmax' => 34,

    // Hai điểm cách nhau hơn mức này (km, đường chim bay) thì không xếp chung một ngày khi còn lựa chọn khác
    'max_leg_km' => 60,
    'max_travel_min_per_day' => 180,

    // Tour (khối riêng, không cộng vào chi phí chuyến)
    'tours_max' => 3,
    'tour_window_days' => 14,       // khi biết ngày đi: lấy tour khởi hành trong ± số ngày này

    // ---- Tìm địa điểm theo tên ở ngoài kho nội dung (ô "Thêm địa điểm" của trang sửa chuyến đi) ----
    // Máy chủ gọi dịch vụ ngoài (trình duyệt không gọi trực tiếp, nên giấu được khóa và giới hạn được lượt gọi).
    // 'photon'   : OpenStreetMap qua photon.komoot.io, KHÔNG cần khóa (dùng công bằng, đủ cho đồ án/demo).
    // 'geoapify' : cần GEOAPIFY_KEY (gói miễn phí không cần thẻ). Mặc định tự chọn geoapify nếu đã có khóa, không thì photon.
    // 'off'      : tắt, trang chỉ tìm trong kho Travela.
    'geocoder' => env('PLANNER_GEOCODER'),
    'photon_url' => env('PHOTON_URL', 'https://photon.komoot.io/api'),
    'geoapify_key' => env('GEOAPIFY_KEY'),
    // Khung Việt Nam: minLng,minLat,maxLng,maxLat. Đặt null để tìm toàn cầu (chuyến đi nước ngoài).
    'geocode_bbox' => env('PLANNER_GEOCODE_BBOX', '102.1,8.4,109.6,23.4'),
    'geocode_cache_minutes' => 360,
    'geocode_timeout' => 5,
    'geocode_limit' => 8,

    /*
     * Giá THAM KHẢO để gợi ý chi phí khi người dùng chưa nhập (đơn vị: đồng). Đây là con số tham khảo do nhóm đặt,
     * không phải giá thị trường chính thức; trên giao diện luôn ghi "ước tính" và người dùng sửa được.
     * 3 mức: low = tiết kiệm, mid = vừa, high = thoải mái. Mức mặc định suy ra từ ngân sách bình quân người/ngày.
     */
    'costs' => [
        'meal'       => ['low' => 40000,  'mid' => 90000,  'high' => 200000],   // một bữa / người
        'experience' => ['low' => 100000, 'mid' => 250000, 'high' => 600000],   // một trải nghiệm / người
        'lodging'    => ['low' => 300000, 'mid' => 700000, 'high' => 1600000],  // một phòng / đêm
        'transport'  => ['low' => 100000, 'mid' => 250000, 'high' => 600000],   // đi lại tại chỗ, cả nhóm / ngày
        'mealsPerDay' => 3,
        'peoplePerRoom' => 2,
        'tierBudgetPerPersonDay' => ['low' => 600000, 'mid' => 1500000],       // dưới mức low => tiết kiệm; dưới mid => vừa; còn lại => thoải mái
    ],

    // ---- Tuần 12: Planner theo thời điểm, dự phòng mưa, tour khớp, địa điểm chưa có dữ liệu ----

    // Địa điểm trong nhà (dùng cho phương án khi mưa). Kind khác nằm ngoài danh sách này thì không đoán là trong nhà.
    'indoor_place_kinds' => ['museum', 'gallery', 'aquarium', 'cafe', 'restaurant'],
    'rain_plan' => [
        'max_per_day' => 4,       // số mục dự phòng tối đa cho mỗi ngày
        'suggest_limit' => 8,     // số gợi ý trả về mỗi lần mở
        'near_km' => 8,           // gần các điểm trong ngày (cộng điểm), xa hơn thì không cộng
    ],

    // Chọn điểm đến theo tháng + cảm hứng (DestinationFinder). Tổng trọng số chỉ để xếp hạng, không phải "độ hợp tuyệt đối".
    'finder' => [
        'limit' => 6,
        'w_inspiration' => 0.45,  // điểm hợp cảm hứng (0-100, từ tbl_destination_inspiration)
        'w_season' => 0.35,       // mức tháng (best 100, ok 65, chưa biết 40, avoid 0)
        'season' => ['best' => 100, 'ok' => 65, 'unknown' => 40, 'avoid' => 0],
        'event_bonus' => 5,       // mỗi lễ hội trùng tháng, tối đa 2 lễ hội
        'tour_bonus' => 5,        // có tour khởi hành trong tháng
        'ready_bonus' => 5,       // Planner tự xếp được lịch
        'neutral_inspiration' => 60,   // khi người dùng không chọn cảm hứng nào
        // cảm hứng của Khám phá (tbl_inspirations.slug) => sở thích của Planner (config/interests.php), dùng để điền sẵn form
        'to_interests' => [
            'bien' => ['nature', 'relax'], 'thien-nhien' => ['nature', 'adventure'], 'chua-lanh' => ['relax'],
            'van-hoa' => ['culture'], 'am-thuc' => ['food'], 'mien-que' => ['nature', 'culture'],
            'san-anh' => ['nature'], 'hai-nguoi' => ['relax', 'food'],
        ],
    ],

    // Tour khớp chuyến đi (TourFit)
    'tour_fit' => ['limit' => 3, 'min_score' => 30, 'max_day_gap' => 14],

    // A-01 / A-05: Tour Match = 0.35 ngân sách + 0.30 sở thích + 0.20 thời gian di chuyển + 0.15 nhóm (TourMatch)
    'tour_match' => [
        'weights' => ['budget' => 0.35, 'interest' => 0.30, 'travel' => 0.20, 'group' => 0.15],
        'banner_min' => (float) env('PLANNER_TOUR_MATCH_MIN', 0.6),   // banner "có tour giống lịch trình này" khi Match ≥ ngưỡng
        'limit' => 3,          // Top-N trên trang sửa chuyến
        'api_max' => 10,       // Top-N tối đa của API
        'scan' => 40,          // số tour tối đa đem ra chấm cho một lần
        'max_day_gap' => 14,   // lệch ngày khởi hành từ mức này trở lên thì điểm ngày = 0
    ],

    // Địa điểm người dùng nhập mà kho chưa có (UnknownPlace)
    'unknown' => [
        'nearest_km' => 150,      // chỉ gợi ý điểm đến gần nhất trong bán kính này
        'nearest_n' => 3,
        'max_requests' => 5000,   // trần số cụm lưu trong tbl_content_requests, chống làm đầy bảng
    ],

    // Tìm theo tiêu chí (PlaceFinder): "nhà hàng chay giá rẻ", "khách sạn 4 sao", "hoạt động trong nhà"...
    // Nguồn: trải nghiệm + tour của Travela (có giá thật), địa điểm đã đồng bộ (tbl_external_places) và OpenStreetMap qua Overpass (có cache).
    'browse' => [
        'overpass_urls' => array_values(array_filter([
            env('PLANNER_OVERPASS_URL'), env('OVERPASS_URL'),
            'https://overpass-api.de/api/interpreter', 'https://overpass.kumi.systems/api/interpreter', 'https://overpass.private.coffee/api/interpreter',
        ])),
        'timeout' => 5,             // giây tối đa cho mỗi máy chủ Overpass
        'budget' => 7,              // tổng giây tối đa cho mọi lần thử (php artisan serve chỉ có 1 luồng, không được chặn lâu)
        'max_servers' => 2,         // thử tối đa chừng này máy chủ Overpass trong một yêu cầu web
        // nguồn trực tiếp khi người dùng bấm / gõ nhóm (cà phê, khách sạn...): photon (mặc định, ~1 giây) | overpass (đủ thẻ hơn nhưng hay quá tải)
        'live' => env('PLANNER_OSM_LIVE', 'photon'),
        'cache_minutes' => 720,
        'osm_limit' => 250,         // số phần tử tối đa lấy từ Overpass mỗi lần
        'limit' => 40,              // số kết quả trả về
        'default_radius_m' => 3000,
        'max_radius_m' => 20000,
        // Ngưỡng đổi giá thật (đồng) sang mức 1..4: giá <= ngưỡng[0] là 1, <= ngưỡng[1] là 2, <= ngưỡng[2] là 3, còn lại là 4.
        // Đây chỉ là cách chia nhóm để lọc; giá hiển thị luôn là giá thật của nguồn.
        'level_bands' => [
            'restaurant' => [100000, 300000, 700000], 'cafe' => [40000, 80000, 150000], 'bar' => [100000, 250000, 500000],
            'hotel' => [500000, 1200000, 3000000], 'experience' => [200000, 600000, 1500000], 'tour' => [2000000, 5000000, 10000000],
            'sight' => [50000, 150000, 400000], 'market' => [50000, 150000, 400000],
        ],
        // Loại địa điểm trong nhà / ngoài trời, dùng khi nguồn không ghi rõ
        'indoor_kinds' => ['museum', 'gallery', 'aquarium', 'mall', 'supermarket', 'cinema'],
        'outdoor_kinds' => ['beach', 'waterfall', 'peak', 'viewpoint', 'park', 'zoo', 'theme_park', 'cave_entrance', 'hot_spring', 'marketplace'],
    ],

    // Dịch vụ ngoài (Overpass, Photon, OSRM): cầu dao chống treo trang (xem ExternalGuard).
    //  PLANNER_EXTERNAL=off : demo không mạng, chỉ dùng dữ liệu có sẵn (CSDL + bản chụp OSM đã nạp bằng planner:warm-osm).
    // T-15…T-22, A-10: dự báo theo giờ Open-Meteo cho chuyến đi (TripWeather), cache theo giờ
    'weather' => [
        'enabled' => env('PLANNER_WEATHER', 'on') !== 'off',
        'endpoint' => env('PLANNER_WEATHER_URL', 'https://api.open-meteo.com/v1/forecast'),
        'timezone' => 'Asia/Ho_Chi_Minh',
        'days' => 16,              // phạm vi dự báo của Open-Meteo; xa hơn thì dùng số liệu theo mùa
        'connect_timeout' => 2,
        'timeout' => 4,
        'rain_warn' => 60,         // % khả năng mưa từ mức này: cảnh báo hoạt động ngoài trời trùng giờ
        'heat_warn' => 35,         // °C trong 11–15h: cảnh báo hoạt động ngoài trời giữa trưa
        'wind_warn' => 40,         // km/h
        'swap_rain' => 60,         // PlannerEngine: ngày dự báo mưa từ mức này thì đổi mục ngoài trời sang ngày khô (T-20)
    ],

    'external' => [
        'enabled' => env('PLANNER_EXTERNAL', 'on') !== 'off',
        'down_seconds' => (int) env('PLANNER_EXTERNAL_DOWN_SECONDS', 300),   // lỗi một lần thì nghỉ 5 phút, không thử lại mỗi lần bấm
    ],
];
