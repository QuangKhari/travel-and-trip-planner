<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Content\DestinationMonthBrief;
use App\Services\Content\SearchCatalog;
use App\Services\Content\SearchIntent;
use Illuminate\Http\Request;

/** C3: /tim-kiem?q= — kết quả nhóm theo loại. Không thay /search (cửa vào tìm tour). */
class SiteSearchController extends Controller
{
    public function __construct(private SearchCatalog $search, private SearchIntent $intent, private DestinationMonthBrief $brief)
    {
        parent::__construct();
    }

    public function index(Request $r)
    {
        $raw = $r->query('q', $r->query('keyword', ''));
        $q = is_string($raw) ? trim(mb_substr($raw, 0, 60)) : '';
        $intent = $q === '' ? null : $this->intent->parse($q);
        $groups = $q === '' ? [] : $this->search->search($q, 6);
        // Câu tự nhiên dài ("Hà Nội 3 ngày văn hóa") hiếm khi khớp nguyên văn: thử lại bằng từ khóa đã làm sạch, rồi tên điểm đến.
        if ($intent && !array_sum(array_column($groups, 'total')) && $intent['searchQuery'] !== '') {
            $groups = $this->search->search($intent['searchQuery'], 6);
            if (!array_sum(array_column($groups, 'total')) && $intent['destination']) {
                $groups = $this->search->search($intent['destination']['name'], 6);
            }
        }
        // Thẻ trả lời nhanh khi nhận ra điểm đến + tháng (dữ liệu đã duyệt, từ DestinationMonthBrief)
        $quick = null;
        if ($intent && $intent['destination'] && $intent['month']) {
            $b = $this->brief->for($intent['destination']['id'], $intent['month']);
            $quick = $b ? [
                'title' => $intent['destination']['name'] . ' tháng ' . $intent['month'],
                'text' => DestinationMonthBrief::summaryText($b),
                'url' => route('destination.month', ['slug' => $intent['destination']['slug'], 'n' => $intent['month']]),
            ] : null;
        }

        return view('clients.guide.search', [
            'title' => $q === '' ? 'Tìm kiếm' : 'Tìm "' . $q . '"',
            'q' => $q,
            'groups' => $groups,
            'total' => array_sum(array_column($groups, 'total')),
            'intent' => $intent,
            'quick' => $quick,
            'plannerUrl' => $intent && SearchIntent::plannable($intent) ? route('planner', $intent['plannerQuery']) : null,
            'noindex' => true,   // trang kết quả không đưa vào chỉ mục
        ]);
    }
}
