<?php

/*
|--------------------------------------------------------------------------
| Modular monolith: ranh giới phân hệ (Tầng 2)
|--------------------------------------------------------------------------
| Mỗi phân hệ SỞ HỮU một nhóm bảng (là nguồn sự thật của chúng) và chỉ mở ra giao diện (class trong app/Modules/{Phân hệ}) cho phân hệ khác:
|   Content  -> App\Modules\Content\TourCatalog        (danh mục tour: còn bán, số ngày, lịch trình ghé đâu)
|               App\Modules\Content\TourListing        (Tour Ops / Commerce ghi ngày khởi hành đang hiển thị, số chỗ cũ trên tin tour)
|   TourOps  -> App\Modules\TourOps\Departures         (ngày khởi hành còn bán; đổi chỗ; đóng phương án cứu chuyến)
|   Commerce -> App\Modules\Commerce\CommerceReader    (đọc đơn tối thiểu)  +  App\Modules\Commerce\BookingChanges (Tour Ops đổi / hủy đơn)
|   Planner  -> App\Modules\Planner\PlannerApi         (quyền với chuyến, lịch chuyến, dòng thời gian, ghi chú, hộp cát đánh giá)
| Phân hệ khác phản ứng bằng sự kiện nghiệp vụ (outbox tbl_domain_events: App\Support\DomainEvents), không ghi chéo bảng của nhau.
| tests/Feature/ArchitectureTest quét mã nguồn: truy cập bảng (rules, deny_tables) và gọi lớp nội bộ của phân hệ khác (class_rules).
| Xem bảng truy cập chéo: php artisan arch:modules
*/

return [
    'modules' => [
        'Content' => [
            'paths' => [
                'app/Services/Content',
                'app/Services/Discover',
                'app/Services/TourStops.php',
                'app/Modules/Content',
                'app/Http/Controllers/admin/ContentReviewController.php',
                'app/Http/Controllers/admin/PlaceDataAdminController.php'
            ],
            // nội dung tour (mô tả, giá niêm yết, lịch trình, điểm ghé, ảnh) là nội dung đã duyệt như điểm đến / món ăn
            'tables' => [
                'tbl_destinations',
                'tbl_external_places',
                'tbl_guides',
                'tbl_guide_categories',
                'tbl_foods',
                'tbl_food_destination',
                'tbl_experiences',
                'tbl_events',
                'tbl_opening_hours',
                'tbl_hours_rules',
                'tbl_place_reports',
                'tbl_itineraries',
                'tbl_itinerary_items',
                'tbl_inspirations',
                'tbl_destination_months',
                'tbl_content_review_log',
                'tbl_content_requests',
                'tbl_blog_posts',
                'tbl_travel_alerts',
                'tbl_place_media_candidates',
                'tbl_tours',
                'tbl_tour_stops',
                'tbl_timeline',
                'tbl_images'
            ],
        ],
        'TourOps' => [
            'paths' => [
                'app/Services/DepartureService.php',
                'app/Services/DepartureRescueService.php',
                'app/Services/DepartureGroupingService.php',
                'app/Services/Departures',
                'app/Services/ResourceAllocationService.php',
                'app/Services/RescueOperationalService.php',
                'app/Services/IncidentService.php',
                'app/Services/TourVersionService.php',
                'app/Services/SupplierService.php',
                'app/Modules/TourOps'
            ],
            'tables' => [
                'tbl_tour_availability',
                'tbl_tour_addons',
                'tbl_tour_versions',
                'tbl_tour_incidents',
                'tbl_incident_events',
                'tbl_resource_allocations',
                'tbl_rescue_resources',
                'tbl_rescue_resource_allocations',
                'tbl_rescue_transfers',
                'tbl_departure_rescue_plans',
                'tbl_departure_rescue_segments',
                'tbl_departure_rescue_responses',
                'tbl_departure_rescue_outbox',
                'tbl_departure_groups',
                'tbl_departure_group_members',
                'tbl_departure_checkins',
                'tbl_departure_costs',
                'tbl_guide_links',
                'tbl_suppliers',
                'tbl_supplier_ratings'
            ],
        ],
        'Commerce' => [
            'paths' => [
                'app/Services/BookingService.php',
                'app/Services/BookingAdjustmentService.php',
                'app/Services/RefundService.php',
                'app/Services/CouponService.php',
                'app/Services/PricingService.php',
                'app/Services/PriceRules.php',
                'app/Services/Payments',
                'app/Services/WaitlistService.php',
                'app/Services/NoShowService.php',
                'app/Services/TourQuotes.php',
                'app/Modules/Commerce'
            ],
            'tables' => [
                'tbl_booking',
                'tbl_checkout',
                'tbl_refunds',
                'tbl_payment_logs',
                'tbl_payment_attempts',
                'tbl_ledger_entries',
                'tbl_promotion',
                'tbl_promotion_usage',
                'tbl_booking_addons',
                'tbl_booking_adjustments',
                'tbl_booking_reminders',
                'tbl_booking_status_history',
                'tbl_price_rules',
                'tbl_reconciliation_findings',
                'tbl_tour_quotes',
                'tbl_tour_quote_messages',
                'tbl_waitlist',
                'tbl_invoice'
            ],
        ],
        'Planner' => [
            'paths' => [
                'app/Services/Planner',
                'app/Http/Controllers/clients/PlannerController.php',
                'app/Http/Controllers/clients/TripWatchController.php',
                'app/Http/Controllers/clients/TripLifecycleController.php',
                'app/Services/Integration',
                'app/Modules/Planner'
            ],
            'tables' => [
                'tbl_trips',
                'tbl_trip_items',
                'tbl_trip_members',
                'tbl_trip_revisions',
                'tbl_trip_events',
                'tbl_trip_event_seen',
                'tbl_trip_watch',
                'tbl_trip_external',
                'tbl_trip_expenses',
                'tbl_trip_expense_shares',
                'tbl_trip_guests',
                'tbl_trip_locks',
                'tbl_trip_polls',
                'tbl_trip_poll_options',
                'tbl_trip_poll_votes',
                'tbl_trip_comments',
                'tbl_trip_votes',
                'tbl_trip_likes',
                'tbl_place_signals',
                'tbl_place_reviews',
                'tbl_user_taste',
                'tbl_reco_impressions'
            ],
        ],
        'AI' => [
            'paths' => ['app/Services/Ai', 'app/Services/Rag', 'app/Http/Controllers/clients/PlannerAiController.php'],
            'tables' => ['tbl_ai_runs', 'tbl_ai_quota', 'tbl_ai_feedback', 'tbl_ai_peer_cases', 'tbl_trip_ai_cache', 'tbl_trip_memory', 'tbl_chunks', 'tbl_chat_logs'],
        ],
    ],

    // Truy cập BẢNG: [phân hệ gọi => [phân hệ chủ bảng => 'none' (không đọc / ghi) | 'read' (chỉ đọc)]]. Không nêu = được phép.
    // Ghi chéo bảng không còn chỗ nào được phép: mọi thay đổi trên bảng của phân hệ khác đi qua giao diện app/Modules hoặc sự kiện.
    'rules' => [
        'Planner' => ['Commerce' => 'none', 'TourOps' => 'none', 'Content' => 'read', 'AI' => 'read'],
        'AI' => ['Commerce' => 'none', 'TourOps' => 'none', 'Content' => 'read', 'Planner' => 'read'],
        'Commerce' => ['Planner' => 'read', 'AI' => 'none', 'TourOps' => 'read', 'Content' => 'read'],
        'TourOps' => ['Commerce' => 'read', 'Planner' => 'none', 'AI' => 'none', 'Content' => 'read'],
        'Content' => ['Commerce' => 'none', 'Planner' => 'read', 'TourOps' => 'read', 'AI' => 'none'],
    ],

    // Bảng cụ thể phân hệ không được chạm kể cả khi luật cho đọc: Planner / AI hỏi tour qua App\Modules\Content\TourCatalog.
    'deny_tables' => [
        'Planner' => ['tbl_tours', 'tbl_tour_stops', 'tbl_timeline', 'tbl_images'],
        'AI' => ['tbl_tours', 'tbl_tour_stops', 'tbl_timeline', 'tbl_images'],
    ],

    // Gọi LỚP: [phân hệ gọi => phân hệ chủ] chỉ được dùng lớp trong app/Modules/{chủ} (giao diện), không dùng lớp nội bộ của chủ.
    // Cặp không nêu (AI -> Planner: La bàn là lớp trợ lý của chính Planner; Commerce <-> TourOps dùng chung cách tính chỗ trong một giao dịch đặt)
    // vẫn bị luật bảng ở trên giới hạn (chỉ đọc).
    'class_rules' => [
        'Planner' => ['TourOps', 'Commerce'],
        'AI' => ['TourOps', 'Commerce'],
        'Commerce' => ['Planner', 'AI'],
        'TourOps' => ['Commerce', 'Planner', 'AI'],
        'Content' => ['Commerce', 'TourOps', 'AI'],
    ],

    // Nợ kỹ thuật đã biết (tệp|bảng|read/write hoặc tệp|class|Lớp): rỗng. Test báo lỗi khi xuất hiện vi phạm mới, hoặc khi dòng nợ đã trả mà còn trong danh sách.
    'baseline' => [],
];
