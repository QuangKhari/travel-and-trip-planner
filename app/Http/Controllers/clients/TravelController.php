<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Planner\TripSharing;
use App\Services\Travel\FlightSearchService;
use App\Services\Travel\FlightStatusService;
use App\Services\Travel\HotelSearchService;
use App\Services\Travel\TravelApi;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Chuyến bay và khách sạn trong trang sửa chuyến đi (T-36…T-38).
 * Chỉ để tham khảo và tính ngân sách: Travela không bán vé / đặt phòng. Mục thêm vào lịch trình là "Di chuyển" / "Ghi chú" có chi phí.
 */
class TravelController extends Controller
{
    private function uid(): int
    {
        return (int) session('userId');
    }

    private function trip(int $id, bool $edit = false): object
    {
        $sh = app(TripSharing::class);
        $acc = ($edit ? $sh->editable($this->uid(), $id) : $sh->access($this->uid(), $id)) ?? abort($edit && $sh->access($this->uid(), $id) ? 403 : 404);

        return $acc['trip'];
    }

    /** Điền sẵn ô tìm kiếm: sân bay gần điểm đến chính. */
    public static function defaults(object $trip): array
    {
        $slug = $trip->destinationId ? DB::table('tbl_destinations')->where('destinationId', $trip->destinationId)->value('slug') : null;

        return [
            'to' => $slug ? (config('travel_apis.airports.' . $slug) ?? '') : '',
            'from' => 'SGN',
            'date' => $trip->startDate ? Carbon::parse($trip->startDate)->toDateString() : now()->addDays(14)->toDateString(),
            'adults' => (int) ($trip->groupSize ?: 1),
            'nights' => max(1, (int) ($trip->days ?: 2) - 1),
            'airports' => config('travel_apis.airport_names'),
            'simulateOnly' => TravelApi::simulateOnly(),
        ];
    }

    private function flightQuery(Request $r): array
    {
        return $r->validate([
            'from' => ['required', 'regex:/^[A-Za-z]{3}$/'],
            'to' => ['required', 'regex:/^[A-Za-z]{3}$/', 'different:from'],
            'date' => ['required', 'date', 'after_or_equal:today'],
            'adults' => ['required', 'integer', 'between:1,9'],
        ], ['to.different' => 'Điểm đi và điểm đến phải khác nhau.', 'date.after_or_equal' => 'Ngày bay phải từ hôm nay trở đi.']);
    }

    public function flights(Request $r, int $id, FlightSearchService $svc): JsonResponse
    {
        $this->trip($id);
        $q = $this->flightQuery($r);
        $res = $svc->search($q['from'], $q['to'], Carbon::parse($q['date'])->toDateString(), (int) $q['adults']);
        $res['offers'] = array_map(fn($o) => $o + ['fromName' => TravelApi::airportName($o['from']), 'toName' => TravelApi::airportName($o['to'])], $res['offers']);

        return response()->json($res);
    }

    public function addFlight(Request $r, int $id, FlightSearchService $svc): JsonResponse
    {
        $trip = $this->trip($id, true);
        $q = $this->flightQuery($r) + $r->validate(['offerId' => ['required', 'string', 'max:80']]);
        // lấy lại offer từ kết quả đã cache trên máy chủ: không tin giá / giờ do trình duyệt gửi lên
        $res = $svc->search($q['from'], $q['to'], Carbon::parse($q['date'])->toDateString(), (int) $q['adults']);
        $o = collect($res['offers'])->firstWhere('id', $q['offerId']);
        if (!$o) {
            return response()->json(['message' => 'Giá vé này đã hết hạn, hãy tìm lại.'], 422);
        }
        $dep = Carbon::parse($o['departAt']);
        $title = $o['flightNo'] . ' ' . $o['from'] . '→' . $o['to'] . ' ' . $dep->format('H:i');
        $this->attach($trip, 'flight', $res['provider'], (bool) $o['simulated'], $title, $o, $dep, (int) $o['price'],
            'transport', 'Bay ' . $title, $o['carrier'] . ' · ' . TravelApi::airportName($o['from']) . ' → ' . TravelApi::airportName($o['to'])
            . ($o['simulated'] ? ' · ' . TravelApi::SIM_LABEL : ''), (int) $dep->format('H') * 60 + (int) $dep->format('i'), (int) $o['durationMin']);

        return response()->json(['ok' => true, 'message' => 'Đã thêm chuyến bay ' . $o['flightNo'] . ' vào lịch trình.']);
    }

    public function status(int $id, FlightStatusService $svc): JsonResponse
    {
        $this->trip($id);
        $rows = DB::table('tbl_trip_external')->where(['tripId' => $id, 'kind' => 'flight'])->orderBy('startAt')->get();
        $out = [];
        foreach ($rows as $f) {
            $p = json_decode($f->payload, true);
            $date = Carbon::parse($f->startAt);
            // chỉ hỏi trạng thái cho chuyến trong khoảng hôm qua … 2 ngày tới (xa hơn chưa có dữ liệu, tốn lượt)
            $near = $date->between(now()->subDay()->startOfDay(), now()->addDays(2)->endOfDay());
            $out[] = ['externalId' => $f->externalId, 'flightNo' => $p['flightNo'] ?? '', 'title' => $f->title,
                'status' => $near ? $svc->get((string) ($p['flightNo'] ?? ''), $date->toDateString()) : null];
        }

        return response()->json(['flights' => $out]);
    }

    public function hotels(Request $r, int $id, HotelSearchService $svc): JsonResponse
    {
        $trip = $this->trip($id);
        $q = $r->validate(['checkIn' => ['required', 'date'], 'nights' => ['required', 'integer', 'between:1,14'], 'adults' => ['required', 'integer', 'between:1,20']]);
        $d = $trip->destinationId ? DB::table('tbl_destinations')->where('destinationId', $trip->destinationId)->first(['latitude', 'longitude', 'name']) : null;
        if (!$d || $d->latitude === null) {
            return response()->json(['message' => 'Hãy chọn điểm đến chính cho chuyến đi để tìm khách sạn gần đó.'], 422);
        }

        return response()->json($svc->search((float) $d->latitude, (float) $d->longitude, Carbon::parse($q['checkIn'])->toDateString(), (int) $q['nights'], (int) $q['adults'])
            + ['near' => $d->name]);
    }

    public function addHotel(Request $r, int $id, HotelSearchService $svc): JsonResponse
    {
        $trip = $this->trip($id, true);
        $q = $r->validate(['checkIn' => ['required', 'date'], 'nights' => ['required', 'integer', 'between:1,14'], 'adults' => ['required', 'integer', 'between:1,20'],
            'hotelId' => ['required', 'string', 'max:80']]);
        $d = DB::table('tbl_destinations')->where('destinationId', $trip->destinationId)->first(['latitude', 'longitude']) ?? abort(422);
        $res = $svc->search((float) $d->latitude, (float) $d->longitude, Carbon::parse($q['checkIn'])->toDateString(), (int) $q['nights'], (int) $q['adults']);
        $h = collect($res['hotels'])->firstWhere('id', $q['hotelId']);
        if (!$h) {
            return response()->json(['message' => 'Không còn khách sạn này trong kết quả, hãy tìm lại.'], 422);
        }
        $in = Carbon::parse($q['checkIn'])->setTime(14, 0);
        $title = $h['name'] . ' (' . $q['nights'] . ' đêm)';
        $this->attach($trip, 'hotel', $res['provider'], (bool) $h['simulated'], $title, $h + ['nights' => (int) $q['nights']], $in, (int) $h['price'],
            'note', 'Nhận phòng ' . $title, $h['stars'] . ' sao · giá ước tính' . ($h['simulated'] ? ' · ' . TravelApi::SIM_LABEL : ''), 14 * 60, 30, $h['lat'], $h['lng']);

        return response()->json(['ok' => true, 'message' => 'Đã thêm ' . $h['name'] . ' vào lịch trình.']);
    }

    public function remove(int $id, int $externalId): JsonResponse
    {
        $this->trip($id, true);
        $x = DB::table('tbl_trip_external')->where(['tripId' => $id, 'externalId' => $externalId])->first();
        if (!$x) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }
        DB::transaction(function () use ($x, $id) {
            DB::table('tbl_trip_external')->where('externalId', $x->externalId)->delete();
            // bỏ luôn mục lịch trình đã tạo kèm (nếu người dùng chưa đổi tên)
            $label = mb_substr(($x->kind === 'flight' ? 'Bay ' : 'Nhận phòng ') . $x->title, 0, 120);
            DB::table('tbl_trip_items')->where('tripId', $id)->where('label', $label)->limit(1)->delete();
            DB::table('tbl_trips')->where('tripId', $id)->update(['rev' => DB::raw('rev + 1'), 'lastEditorId' => $this->uid(), 'updatedAt' => now()]);
            app(\App\Services\Planner\TripRevisions::class)->recordCurrent($id, $this->uid(), 'external');
        });

        return response()->json(['ok' => true]);
    }

    /** Ghi tbl_trip_external và một mục lịch trình có chi phí (ngày tính theo ngày đi của chuyến; ngoài khoảng thì để "chưa xếp ngày"). */
    private function attach(object $trip, string $kind, string $provider, bool $sim, string $title, array $payload, Carbon $at, int $price,
        string $itemType, string $label, string $note, int $startMin, int $duration, ?float $lat = null, ?float $lng = null): void
    {
        DB::transaction(function () use ($trip, $kind, $provider, $sim, $title, $payload, $at, $price, $itemType, $label, $note, $startMin, $duration, $lat, $lng) {
            DB::table('tbl_trip_external')->insert([
                'tripId' => $trip->tripId, 'kind' => $kind, 'provider' => $provider, 'simulated' => $sim, 'title' => mb_substr($title, 0, 160),
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'startAt' => $at, 'price' => $price, 'createdAt' => now(),
            ]);
            $day = null;
            if ($trip->startDate) {
                $n = (int) Carbon::parse($trip->startDate)->startOfDay()->diffInDays($at->copy()->startOfDay(), false) + 1;
                $day = ($n >= 1 && $n <= (int) $trip->days) ? $n : null;
            }
            $order = (int) DB::table('tbl_trip_items')->where('tripId', $trip->tripId)->where('dayNo', $day)->max('orderNo') + 1;
            DB::table('tbl_trip_items')->insert([
                'tripId' => $trip->tripId, 'refType' => $itemType, 'refId' => null, 'label' => mb_substr($label, 0, 120), 'note' => mb_substr($note, 0, 255),
                'dayNo' => $day, 'orderNo' => $order, 'cost' => $price, 'costType' => 'estimate',
                'startMin' => $day ? $startMin : null, 'durationMin' => max(5, min(720, $duration)),
                'latitude' => $lat, 'longitude' => $lng, 'source' => $lat !== null ? 'search' : null,
            ]);
            // báo cho người đang mở chuyến ở tab khác: có thay đổi (cơ chế rev sẵn có)
            DB::table('tbl_trips')->where('tripId', $trip->tripId)->update(['rev' => DB::raw('rev + 1'), 'lastEditorId' => $this->uid(), 'updatedAt' => now()]);
            app(\App\Services\Planner\TripRevisions::class)->recordCurrent((int) $trip->tripId, $this->uid(), 'external');
        });
    }
}
