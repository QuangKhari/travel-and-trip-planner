<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Content\Badges;
use App\Services\Content\TravelJournal;

/** E1/E2: Sổ tay du hành. Route nằm trong nhóm checkLoginClient nên luôn có người dùng đăng nhập. */
class JournalController extends Controller
{
    public function __construct()
    {
        parent::__construct(); // khởi tạo $this->user cho getUserId()
    }

    public function index(TravelJournal $journal, Badges $badgeService)
    {
        $userId = (int) $this->getUserId();
        $metrics = $badgeService->metrics($userId);
        $badges = Badges::evaluate($metrics);   // một lần đếm dùng cho cả bộ đếm lẫn huy hiệu
        $trips = $journal->trips($userId);

        return view('clients.journal', [
            'title' => 'Sổ tay du hành',
            'metaDescription' => 'Sổ tay du hành của bạn: nơi đã đến, món đã thử, lễ hội đã dự và các tour đã đặt.',
            'noindex' => true,   // trang riêng của từng người, không đưa vào chỉ mục
            'metrics' => $metrics,
            'badges' => $badges,
            'earned' => count(array_filter($badges, fn($b) => $b['earned'])),
            'provinces' => $journal->provinces($userId),
            'upcoming' => $trips['upcoming'],
            'done' => $trips['done'],
        ]);
    }
}
