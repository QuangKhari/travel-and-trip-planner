<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Carbon\Carbon;

/**
 * /search và /search-voice-text chỉ là "cửa vào": làm sạch dữ liệu rồi chuyển sang /tours
 * (nơi có bộ lọc, sắp xếp, phân trang). Nhờ vậy chỉ có MỘT trang kết quả để duy trì.
 *
 * UX: dữ liệu sai KHÔNG còn redirect()->back() (dễ đưa người dùng về trang trống hoặc trang ngoài site)
 * mà luôn đưa tới /tours kèm thông báo rõ ràng.
 */
class SearchController extends Controller
{
    /** Mã điểm đến của ô tìm kiếm ở trang chủ -> tên hiển thị. */
    private const DESTINATIONS = [
        'dn' => 'Đà Nẵng',
        'cd' => 'Côn Đảo',
        'hn' => 'Hà Nội',
        'hcm' => 'TP. Hồ Chí Minh',
        'hl' => 'Hạ Long',
        'nb' => 'Ninh Bình',
        'pq' => 'Phú Quốc',
        'dl' => 'Đà Lạt',
        'qt' => 'Quảng Trị',
        'kh' => 'Khánh Hòa',
        'ct' => 'Cần Thơ',
        'vt' => 'Vũng Tàu',
        'qn' => 'Quảng Ninh',
        'la' => 'Lào Cai',
        'bd' => 'Bình Định',
    ];

    public function index(Request $request)
    {
        $destination = (string) $request->input('destination', '');

        if ($destination !== '' && !isset(self::DESTINATIONS[$destination])) {
            toastr()->warning('Điểm đến không hợp lệ, mình đã hiện tất cả tour để bạn chọn lại.');
            return redirect()->route('tours');
        }

        $from = $this->readDate($request->input('start_date'));
        $to = $this->readDate($request->input('end_date'));

        if ($from === false || $to === false) {
            toastr()->warning('Ngày tìm kiếm chưa đúng định dạng (dd/mm/yyyy). Bạn có thể chọn lại ngày ở bộ lọc.');
            return redirect()->route('tours', array_filter([
                'keyword' => $destination !== '' ? self::DESTINATIONS[$destination] : null,
            ]));
        }

        // Ngày về trước ngày đi: tự đổi chỗ thay vì trả về 0 kết quả khó hiểu
        if ($from && $to && $to < $from) {
            [$from, $to] = [$to, $from];
        }

        return redirect()->route('tours', array_filter([
            'keyword' => $destination !== '' ? self::DESTINATIONS[$destination] : null,
            'from' => $from ?: null,
            'to' => $to ?: null,
        ]));
    }

    public function searchTours(Request $request)
    {
        $keyword = trim((string) $request->input('keyword'));

        // Không có từ khóa: vẫn vào trang tour (thay vì về trang chủ kèm thông báo lỗi)
        if ($keyword === '') {
            return redirect()->route('tours');
        }

        $keyword = $this->normalizeVoiceKeyword($keyword);

        if ($keyword === '') {
            toastr()->info('Mình chưa nghe rõ điểm đến. Bạn thử gõ tên nơi muốn đến nhé.');
            return redirect()->route('tours');
        }

        if (mb_strlen($keyword) > 100) {
            $keyword = mb_substr($keyword, 0, 100);
        }

        return redirect()->route('tours', ['keyword' => $keyword]);
    }

    /** @return string|null|false  Y-m-d, null nếu để trống, false nếu sai định dạng */
    private function readDate($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        foreach (['d/m/Y', 'Y-m-d'] as $format) {
            try {
                $d = Carbon::createFromFormat($format, $value);
                if ($d && $d->format($format) === $value) {
                    return $d->format('Y-m-d');
                }
            } catch (\Throwable $e) {
            }
        }

        return false;
    }

    private function normalizeVoiceKeyword(string $keyword): string
    {
        $keyword = trim($keyword);
        $keyword = preg_replace('/\s+/u', ' ', $keyword);
        $keyword = trim($keyword, " \t\n\r\0\x0B.,!?;:");

        if ($keyword === '') {
            return '';
        }

        $destinations = [
            'Đà Nẵng',
            'Hà Nội',
            'Hồ Chí Minh',
            'Nha Trang',
            'Phú Quốc',
            'Đà Lạt',
            'Hạ Long',
            'Côn Đảo',
            'Huế',
            'Quy Nhơn',
            'Vũng Tàu',
            'Sa Pa',
            'Mũi Né',
            'Phan Thiết',
        ];

        foreach ($destinations as $destination) {
            if (mb_stripos($keyword, $destination, 0, 'UTF-8') !== false) {
                return $destination;
            }
        }

        $removePhrases = [
            'cho tôi xem',
            'cho tôi tìm',
            'tôi muốn tìm',
            'tôi muốn xem',
            'tôi muốn đi',
            'tìm kiếm',
            'tìm tour',
            'tìm',
            'tour',
            'có tour',
            'cho tôi',
            'hãy tìm',
            'hãy cho tôi',
        ];

        foreach ($removePhrases as $phrase) {
            $keyword = preg_replace('/\b' . preg_quote($phrase, '/') . '\b/iu', '', $keyword);
        }

        $keyword = preg_replace('/\s+/u', ' ', $keyword);

        return trim($keyword, " \t\n\r\0\x0B.,!?;:");
    }
}
