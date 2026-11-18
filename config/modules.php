<?php

/*
|--------------------------------------------------------------------------
| Modular monolith: ranh giới phân hệ (Tầng 2)
|--------------------------------------------------------------------------
| Mỗi phân hệ SỞ HỮU một nhóm bảng (là nguồn sự thật của chúng) và chỉ mở ra giao diện (class trong app/Modules/{Phân hệ}) cho phân hệ khác.
| Phân hệ khác phản ứng bằng sự kiện nghiệp vụ (outbox tbl_domain_events), không ghi chéo bảng của nhau.
| tests/Feature/ModuleBoundaryTest quét mã nguồn và chặn vi phạm MỚI; vi phạm cũ được ghi trong 'baseline' (nợ kỹ thuật đã biết, cần giảm dần).
| Xem bảng truy cập chéo: php artisan arch:modules
*/

return [
    'modules' => [
        'Content' => [
            'paths' => ['app/Services/Content', 'app/Services/Discover', 'app/Http/Controllers/admin/ContentReviewController.php', 'app/Http/Controllers/admin/PlaceDataAdminController.php'],
            'tables' => ['tbl_destinations', 'tbl_external_places', 'tbl_guides', 'tbl_guide_categories', 'tbl_foods', 'tbl_food_destination', 'tbl_experiences', 'tbl_events',
                'tbl_opening_hours', 'tbl_hours_rules', 'tbl_place_reports', 'tbl_itineraries', 'tbl_itinerary_items', 'tbl_inspirations', 'tbl_destination_months',
                'tbl_content_review_log', 'tbl_content_requests', 'tbl_blog_posts', 'tbl_travel_alerts', 'tbl_place_media_candidates'],
        ],
        'TourOps' => [
            'paths' => ['app/Services/DepartureService.php', 'app/Services/DepartureRescueService.php', 'app/Services/DepartureGroupingService.php', 'app/Services/Departures',
                'app/Services/ResourceAllocationService.php', 'app/Services/RescueOperationalService.php', 'app/Services/IncidentService.php', 'app/Services/TourStops.php',
                'app/Services/TourVersionService.php', 'app/Services/SupplierService.php'],
            'tables' => ['tbl_tours', 'tbl_tour_availability', 'tbl_tour_stops', 'tbl_tour_addons', 'tbl_tour_versions', 'tbl_tour_incidents', 'tbl_incident_events',
                'tbl_resource_allocations', 'tbl_rescue_resources', 'tbl_rescue_resource_allocations', 'tbl_rescue_transfers', 'tbl_departure_rescue_plans',
                'tbl_departure_rescue_segments', 'tbl_departure_rescue_responses', 'tbl_departure_rescue_outbox', 'tbl_departure_groups', 'tbl_departure_group_members',
                'tbl_departure_checkins', 'tbl_departure_costs', 'tbl_guide_links', 'tbl_suppliers', 'tbl_supplier_ratings'],
        ],
        'Commerce' => [
            'paths' => ['app/Services/BookingService.php', 'app/Services/BookingAdjustmentService.php', 'app/Services/RefundService.php', 'app/Services/CouponService.php',
                'app/Services/PricingService.php', 'app/Services/PriceRules.php', 'app/Services/Payments', 'app/Services/WaitlistService.php', 'app/Services/NoShowService.php',
                'app/Services/TourQuotes.php', 'app/Modules/Commerce'],
            'tables' => ['tbl_booking', 'tbl_checkout', 'tbl_refunds', 'tbl_payment_logs', 'tbl_payment_attempts', 'tbl_ledger_entries', 'tbl_promotion', 'tbl_promotion_usage',
                'tbl_booking_addons', 'tbl_booking_adjustments', 'tbl_booking_reminders', 'tbl_booking_status_history', 'tbl_price_rules', 'tbl_reconciliation_findings',
                'tbl_tour_quotes', 'tbl_tour_quote_messages', 'tbl_waitlist', 'tbl_invoice'],
        ],
        'Planner' => [
            'paths' => ['app/Services/Planner', 'app/Http/Controllers/clients/PlannerController.php', 'app/Http/Controllers/clients/TripWatchController.php',
                'app/Http/Controllers/clients/TripLifecycleController.php', 'app/Services/Integration'],
            'tables' => ['tbl_trips', 'tbl_trip_items', 'tbl_trip_members', 'tbl_trip_revisions', 'tbl_trip_events', 'tbl_trip_event_seen', 'tbl_trip_watch', 'tbl_trip_external',
                'tbl_trip_expenses', 'tbl_trip_expense_shares', 'tbl_trip_guests', 'tbl_trip_locks', 'tbl_trip_polls', 'tbl_trip_poll_options', 'tbl_trip_poll_votes',
                'tbl_trip_comments', 'tbl_trip_votes', 'tbl_trip_likes', 'tbl_place_signals', 'tbl_place_reviews', 'tbl_user_taste', 'tbl_reco_impressions'],
        ],
        'AI' => [
            'paths' => ['app/Services/Ai', 'app/Services/Rag', 'app/Http/Controllers/clients/PlannerAiController.php'],
            'tables' => ['tbl_ai_runs', 'tbl_ai_quota', 'tbl_ai_feedback', 'tbl_ai_peer_cases', 'tbl_trip_ai_cache', 'tbl_trip_memory', 'tbl_chunks', 'tbl_chat_logs'],
        ],
    ],

    // Luật: [phân hệ gọi => [phân hệ chủ bảng => 'none' (không đọc / ghi) | 'read' (chỉ đọc)]]. Không nêu = được phép (vd đọc nội dung đã duyệt).
    'rules' => [
        'Planner' => ['Commerce' => 'none', 'TourOps' => 'read', 'Content' => 'read'],
        'AI' => ['Commerce' => 'none', 'TourOps' => 'read', 'Content' => 'read', 'Planner' => 'read'],
        'Commerce' => ['Planner' => 'read', 'AI' => 'none'],
        'Content' => ['Commerce' => 'none', 'Planner' => 'read'],
    ],

    // Nợ kỹ thuật đã biết (tệp|bảng|read/write): được giữ tạm, test báo nếu xuất hiện vi phạm MỚI ngoài danh sách này.
    'baseline' => [
        // Planner ghi giờ mở cửa / dữ kiện địa điểm do admin xác minh (lẽ ra là việc của phân hệ Content)
        'app/Services/Planner/PlaceFacts.php|tbl_external_places|write',
        'app/Services/Planner/OpeningHours.php|tbl_opening_hours|write',
        'app/Services/Planner/PlaceReports.php|tbl_place_reports|write',
        'app/Services/Planner/PlaceReports.php|tbl_hours_rules|write',
        'app/Services/Planner/ContentRequests.php|tbl_content_requests|write',
        'app/Services/Planner/PlaceFacts.php|tbl_place_reports|write',
        'app/Services/Planner/PlaceReports.php|tbl_hours_rules|write',
        // Nội dung đọc đơn để trao huy hiệu / nhật ký hành trình (nên chuyển sang CommerceReader)
        'app/Services/Content/Badges.php|tbl_booking|read',
        'app/Services/Content/TravelJournal.php|tbl_booking|read',
        'app/Services/Content/TravelJournal.php|tbl_checkout|read',
        // Khay "Thêm vào chuyến" ở trang nội dung tạo chuyến / mục trực tiếp (nên gọi TripPlanner)
        'app/Services/Content/TripTray.php|tbl_trips|write',
        'app/Services/Content/TripTray.php|tbl_trip_items|write',
        // Trợ lý tạo chuyến ghi chuyến từ bản nháp (nên gọi TripPlanner::create)
        'app/Http/Controllers/clients/PlannerAiController.php|tbl_trips|write',
        // Hộp cát của bộ đánh giá AI: tạo chuyến tạm trong transaction rồi hoàn tác (chủ đích, không phải đường chạy thật)
        'app/Services/Ai/Eval/CopilotExperiment.php|tbl_trip_items|write',
        'app/Services/Ai/Eval/CopilotExperiment.php|tbl_trips|write',
        'app/Services/Ai/Eval/EvalKit.php|tbl_trips|write',
        'app/Services/Ai/Eval/EvalKit.php|tbl_trip_items|write',
    ],
];
