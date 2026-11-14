<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Planner\BookingPlan;
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
        $dest = $trip->destinationId ? DB::table('tbl_destinations')->where('destinationId', $trip->destinationId)->first(['slug', 'name']) : null;
        $slug = $dest->slug ?? null;

        return [
            'to' => $slug ? (config('travel_apis.airports.' . $slug) ?? '') : '',
            'from' => 'SGN',
            'date' => $trip->startDate ? Carbon::parse($trip->startDate)->toDateString() : now()->addDays(14)->toDateString(),
            'adults' => (int) ($trip->groupSize ?: 1),
            'nights' => max(1, (int) ($trip->days ?: 2) - 1),
            'airports' => config('travel_apis.airport_names'),
            'simulateOnly' => TravelApi::simulateOnly(),
            'city' => (string) ($dest->name ?? ''),   // deep-link Booking.com / Agoda
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
            if (!empty($x->attachPath)) {
                \Illuminate\Support\Facades\Storage::disk('proofs')->delete($x->attachPath);
            }
            // bỏ luôn mục lịch trình đã tạo kèm (nếu người dùng chưa đổi tên); "cần đặt" chưa có mục lịch
            if (($x->status ?? 'booked') === 'booked') {
                DB::table('tbl_trip_items')->where('tripId', $id)->where('label', self::itemLabel($x))->limit(1)->delete();
            }
            DB::table('tbl_trips')->where('tripId', $id)->update(['rev' => DB::raw('rev + 1'), 'lastEditorId' => $this->uid(), 'updatedAt' => now()]);
            app(\App\Services\Planner\TripRevisions::class)->recordCurrent($id, $this->uid(), 'external');
        });

        return response()->json(['ok' => true]);
    }

    /** Ghi tbl_trip_external và một mục lịch trình có chi phí (ngày tính theo ngày đi của chuyến; ngoài khoảng thì để "chưa xếp ngày"). */
    private function attach(object $trip, string $kind, string $provider, bool $sim, string $title, array $payload, Carbon $at, int $price,
        string $itemType, string $label, string $note, int $startMin, int $duration, ?float $lat = null, ?float $lng = null, array $extra = []): int
    {
        return DB::transaction(function () use ($trip, $kind, $provider, $sim, $title, $payload, $at, $price, $itemType, $label, $note, $startMin, $duration, $lat, $lng, $extra) {
            $xid = (int) DB::table('tbl_trip_external')->insertGetId([
                'tripId' => $trip->tripId, 'kind' => $kind, 'provider' => $provider, 'simulated' => $sim, 'title' => mb_substr($title, 0, 160),
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'startAt' => $at, 'price' => $price, 'createdAt' => now(),
            ] + $extra);
            $this->placeItem($trip, $itemType, $label, $note, $at, $price, $startMin, $duration, $lat, $lng);

            return $xid;
        });
    }

    /** Một mục lịch cho đặt chỗ (ngày tính theo ngày đi của chuyến; ngoài khoảng thì để "chưa xếp ngày"), rồi tăng rev + ghi phiên bản. */
    private function placeItem(object $trip, string $itemType, string $label, string $note, Carbon $at, int $price, int $startMin, int $duration, ?float $lat = null, ?float $lng = null): void
    {
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
        $this->touch($trip);
    }

    /** báo cho người đang mở chuyến ở tab khác: có thay đổi (cơ chế rev sẵn có) */
    private function touch(object $trip): void
    {
        DB::table('tbl_trips')->where('tripId', $trip->tripId)->update(['rev' => DB::raw('rev + 1'), 'lastEditorId' => $this->uid(), 'updatedAt' => now()]);
        app(\App\Services\Planner\TripRevisions::class)->recordCurrent((int) $trip->tripId, $this->uid(), 'external');
    }

    /** Tên mục lịch tạo kèm một đặt chỗ (dùng để tìm lại khi hủy / xóa). */
    public static function itemLabel(object $x): string
    {
        $manual = !empty($x->confirmationCode) || !empty($x->attachPath) || str_contains((string) $x->payload, '"manual":true');
        $prefix = $manual ? (['hotel' => 'Nhận phòng ', 'flight' => 'Khởi hành ', 'train' => 'Tàu ', 'bus' => 'Xe ', 'ticket' => 'Vé '][$x->kind] ?? '')
            : ($x->kind === 'flight' ? 'Bay ' : 'Nhận phòng ');

        return mb_substr($prefix . $x->title, 0, 120);
    }

    // ------------------------------------------------------------------ Kế hoạch đặt chỗ (BookingPlan)

    /** GET /len-ke-hoach/{id}/dat-cho : còn thiếu gì, cần đặt gì (có hạn), đã đặt gì. */
    public function plan(int $id, BookingPlan $bp): JsonResponse
    {
        $trip = $this->trip($id);
        abort_unless(BookingPlan::ready(), 503, 'Cần chạy php artisan migrate.');
        $acc = app(TripSharing::class)->access($this->uid(), $id);

        return response()->json($bp->forTrip($trip) + ['canEdit' => TripSharing::canEdit($acc['role'] ?? null)]);
    }

    /** POST /len-ke-hoach/{id}/dat-cho : thêm việc "cần đặt" (chưa đặt) có hạn đặt, nơi định đặt. */
    public function planAdd(Request $r, int $id, BookingPlan $bp): JsonResponse
    {
        $trip = $this->trip($id, true);
        $v = $r->validate([
            'kind' => ['required', 'in:' . implode(',', array_keys(BookingPlan::KINDS))],
            'title' => ['required', 'string', 'max:120'],
            'startAt' => ['nullable', 'date'],
            'endAt' => ['nullable', 'date', 'after:startAt'],
            'needBy' => ['nullable', 'date'],
            'bookUrl' => ['nullable', 'url:http,https', 'max:500'],
            'note' => ['nullable', 'string', 'max:255'],
        ], ['bookUrl.url' => 'Nơi đặt phải là đường dẫn http(s).', 'endAt.after' => 'Ngày kết thúc phải sau ngày bắt đầu.']);
        if (DB::table('tbl_trip_external')->where('tripId', $id)->count() >= 60) {
            return response()->json(['message' => 'Mỗi chuyến tối đa 60 mục đặt chỗ.'], 422);
        }
        DB::table('tbl_trip_external')->insert([
            'tripId' => $id, 'kind' => $v['kind'], 'provider' => 'manual', 'simulated' => false, 'title' => mb_substr($v['title'], 0, 160),
            'payload' => json_encode(['manual' => true]), 'startAt' => isset($v['startAt']) ? Carbon::parse($v['startAt']) : ($trip->startDate ? Carbon::parse($trip->startDate)->setTime(12, 0) : now()),
            'endAt' => isset($v['endAt']) ? Carbon::parse($v['endAt']) : null, 'price' => 0, 'createdAt' => now(),
            'status' => 'planned', 'needBy' => $v['needBy'] ?? null, 'bookUrl' => $v['bookUrl'] ?? null, 'note' => $v['note'] ?? null,
        ]);

        return response()->json(['ok' => true, 'message' => 'Đã thêm vào danh sách cần đặt.'] + $bp->forTrip($trip) + ['canEdit' => true]);
    }

    /**
     * POST /len-ke-hoach/{id}/dat-cho/{externalId} {status: booked|cancelled|planned, code?, startAt?, price?}
     * planned -> booked: đưa vào lịch (giờ nhận phòng / giờ đi); booked -> cancelled: gỡ khỏi lịch, giữ bản ghi để đối chiếu.
     */
    public function planStatus(Request $r, int $id, int $externalId, BookingPlan $bp): JsonResponse
    {
        $trip = $this->trip($id, true);
        $v = $r->validate([
            'status' => ['required', 'in:planned,booked,cancelled'],
            'code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9\-_. ]+$/'],
            'startAt' => ['nullable', 'date'],
            'price' => ['nullable', 'integer', 'between:0,1000000000'],
        ], ['code.regex' => 'Mã xác nhận chỉ gồm chữ, số, dấu gạch và dấu chấm.']);
        $x = DB::table('tbl_trip_external')->where(['tripId' => $id, 'externalId' => $externalId])->first() ?? abort(404);
        $from = $x->status ?? 'booked';
        DB::transaction(function () use ($x, $v, $trip, $from) {
            $set = ['status' => $v['status']];
            if (isset($v['code'])) {
                $set['confirmationCode'] = trim($v['code']);
            }
            if (isset($v['startAt'])) {
                $set['startAt'] = Carbon::parse($v['startAt']);
            }
            if (isset($v['price'])) {
                $set['price'] = (int) $v['price'];
            }
            DB::table('tbl_trip_external')->where('externalId', $x->externalId)->update($set);
            $x2 = DB::table('tbl_trip_external')->where('externalId', $x->externalId)->first();
            $label = self::itemLabel($x2);
            if ($v['status'] === 'booked' && $from !== 'booked') {
                $at = Carbon::parse($x2->startAt);
                $isMove = in_array($x2->kind, BookingPlan::TRANSPORT, true);
                $this->placeItem($trip, $isMove ? 'transport' : 'note', $label, 'Đã đặt ngoài Travela', $at, (int) $x2->price,
                    $x2->kind === 'hotel' ? 14 * 60 : (int) $at->format('H') * 60 + (int) $at->format('i'), $x2->kind === 'hotel' ? 30 : 60);
            } elseif ($v['status'] !== 'booked' && $from === 'booked') {
                DB::table('tbl_trip_items')->where('tripId', $trip->tripId)->where('label', self::itemLabel($x))->limit(1)->delete();
                $this->touch($trip);
            }
        });
        $msg = ['booked' => 'Đã đánh dấu đã đặt và đưa vào lịch.', 'cancelled' => 'Đã đánh dấu đã hủy và gỡ khỏi lịch (bản ghi vẫn giữ để đối chiếu).', 'planned' => 'Đã chuyển về "cần đặt".'][$v['status']];

        return response()->json(['ok' => true, 'message' => $msg] + $bp->forTrip(DB::table('tbl_trips')->where('tripId', $id)->first()) + ['canEdit' => true]);
    }

    /** POST /len-ke-hoach/{id}/dat-cho/bo-qua {key: stay|go|back, skip} */
    public function planSkip(Request $r, int $id, BookingPlan $bp): JsonResponse
    {
        $trip = $this->trip($id, true);
        $v = $r->validate(['key' => ['required', 'in:stay,go,back'], 'skip' => ['required', 'boolean']]);
        $bp->setSkip($trip, $v['key'], (bool) $v['skip']);

        return response()->json(['ok' => true] + $bp->forTrip(DB::table('tbl_trips')->where('tripId', $id)->first()) + ['canEdit' => true]);
    }

    /**
     * A-12 POST /len-ke-hoach/{id}/xac-nhan (multipart): lưu xác nhận đặt chỗ NGOÀI Travela (khách sạn, vé máy bay / xe, vé tham quan)
     * kèm mã xác nhận và tệp PDF / ảnh (≤ 5 MB) trên đĩa riêng tư. Tạo luôn một mục trong lịch đúng ngày giờ.
     */
    public function addConfirmation(Request $r, int $id): JsonResponse
    {
        $trip = $this->trip($id, true);
        $v = $r->validate([
            'kind' => ['required', 'in:hotel,flight,train,bus,ticket'],
            'endAt' => ['nullable', 'date', 'after:startAt'],
            'title' => ['required', 'string', 'max:120'],
            'provider' => ['nullable', 'string', 'max:20'],
            'code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9\-_. ]+$/'],
            'startAt' => ['required', 'date'],
            'price' => ['nullable', 'integer', 'between:0,1000000000'],
            'file' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,webp'],
        ], ['code.regex' => 'Mã xác nhận chỉ gồm chữ, số, dấu gạch và dấu chấm.', 'file.mimes' => 'Tệp phải là PDF hoặc ảnh JPG / PNG / WebP.', 'file.max' => 'Tệp tối đa 5 MB.']);
        if (!\Illuminate\Support\Facades\Schema::hasColumn('tbl_trip_external', 'confirmationCode')) {
            return response()->json(['message' => 'Cần chạy php artisan migrate trước.'], 503);
        }
        $extra = ['confirmationCode' => isset($v['code']) ? trim($v['code']) : null];
        if (BookingPlan::ready()) {
            $extra += ['status' => 'booked', 'endAt' => isset($v['endAt']) ? Carbon::parse($v['endAt']) : null];
        }
        if ($r->hasFile('file')) {
            $f = $r->file('file');
            $path = 'trips/' . $trip->tripId . '/' . \Illuminate\Support\Str::random(32) . '.' . strtolower($f->getClientOriginalExtension() ?: $f->extension());
            \Illuminate\Support\Facades\Storage::disk('proofs')->put($path, (string) file_get_contents($f->getRealPath()));
            $extra += ['attachPath' => $path, 'attachName' => mb_substr(preg_replace('/[^\pL\pN._ -]+/u', '_', $f->getClientOriginalName()), 0, 120), 'attachMime' => $f->getMimeType()];
        }
        $at = Carbon::parse($v['startAt']);
        $kindLabel = ['hotel' => 'Nhận phòng', 'flight' => 'Khởi hành', 'train' => 'Tàu', 'bus' => 'Xe', 'ticket' => 'Vé'][$v['kind']];
        $xid = $this->attach($trip, $v['kind'], mb_substr((string) ($v['provider'] ?? 'manual'), 0, 20) ?: 'manual', false,
            $v['title'], ['manual' => true], $at, (int) ($v['price'] ?? 0), in_array($v['kind'], BookingPlan::TRANSPORT, true) ? 'transport' : 'note', $kindLabel . ' ' . $v['title'],
            // mã xác nhận chỉ nằm ở tbl_trip_external: ghi chú của mục có thể hiện ở bản chia sẻ / Cộng đồng
            'Đã đặt ngoài Travela', (int) $at->format('H') * 60 + (int) $at->format('i'),
            $v['kind'] === 'hotel' ? 30 : 60, null, null, $extra);

        return response()->json(['ok' => true, 'externalId' => $xid, 'message' => 'Đã lưu xác nhận "' . $v['title'] . '" vào chuyến đi.']);
    }

    /** A-12: tải tệp xác nhận (chỉ người xem được chuyến), không có đường dẫn công khai. */
    public function confirmationFile(int $id, int $externalId)
    {
        $this->trip($id);
        $x = DB::table('tbl_trip_external')->where(['tripId' => $id, 'externalId' => $externalId])->first();
        if (!$x || empty($x->attachPath) || !\Illuminate\Support\Facades\Storage::disk('proofs')->exists($x->attachPath)) {
            abort(404);
        }

        return \Illuminate\Support\Facades\Storage::disk('proofs')->response($x->attachPath, $x->attachName ?: basename($x->attachPath), [
            'Content-Type' => $x->attachMime ?: 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ], 'inline');
    }
}
