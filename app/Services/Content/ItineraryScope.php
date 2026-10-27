<?php

namespace App\Services\Content;

use Illuminate\Support\Facades\DB;

/**
 * Lịch trình thuộc những điểm đến nào. Dùng chung cho Coverage (đếm "lịch trình một điểm đến" - tuần 3)
 * và nút "Dùng làm chuyến đi của tôi" (tuần 4). Quy tắc theo kế hoạch v6, mục 6:
 * một lịch trình thuộc MỘT điểm đến khi mọi mục có tham chiếu của nó quy về cùng một destinationId.
 *  - destination: chính refId
 *  - place / food / experience / event: destinationId của mục đó
 *  - transport, note, chữ tự do (refId rỗng): không tính (trung tính)
 *  - mục trỏ tới bản ghi đã bị xóa: tính vào 'broken', lịch trình đó KHÔNG được coi là một điểm đến
 */
final class ItineraryScope
{
    private const TABLES = [
        'destination' => ['tbl_destinations', 'destinationId', 'destinationId'],
        'place' => ['tbl_external_places', 'placeId', 'destinationId'],
        'food' => ['tbl_foods', 'foodId', 'destinationId'],
        'experience' => ['tbl_experiences', 'experienceId', 'destinationId'],
        'event' => ['tbl_events', 'eventId', 'destinationId'],
    ];

    /**
     * @param list<int>|null $itineraryIds null = mọi lịch trình đã xuất bản
     * @return array<int, array{dests:list<int>,broken:int,stops:int,single:?int}> theo itineraryId
     */
    public function resolve(?array $itineraryIds = null): array
    {
        $q = DB::table('tbl_itinerary_items as it')->join('tbl_itineraries as i', 'i.itineraryId', '=', 'it.itineraryId');
        if ($itineraryIds === null) {
            $q->where('i.reviewStatus', 'published');
        } else {
            if (!$itineraryIds) {
                return [];
            }
            $q->whereIn('it.itineraryId', $itineraryIds);
        }
        $items = $q->get(['it.itineraryId', 'it.refType', 'it.refId']);

        $want = [];
        foreach ($items as $r) {
            if ($r->refType && $r->refId && isset(self::TABLES[$r->refType])) {
                $want[$r->refType][(int) $r->refId] = true;
            }
        }
        $dest = [];   // [refType][refId] => destinationId|null (null = bản ghi tồn tại nhưng chưa gắn điểm đến); vắng = đã xóa
        foreach ($want as $type => $ids) {
            [$table, $key, $col] = self::TABLES[$type];
            foreach (DB::table($table)->whereIn($key, array_keys($ids))->get([$key . ' as id', $col . ' as d']) as $row) {
                $dest[$type][(int) $row->id] = $row->d !== null ? (int) $row->d : null;
            }
        }

        $out = [];
        foreach ($items as $r) {
            $id = (int) $r->itineraryId;
            $out[$id] ??= ['dests' => [], 'broken' => 0, 'stops' => 0, 'single' => null];
            $out[$id]['stops']++;
            if (!$r->refType || !$r->refId || !isset(self::TABLES[$r->refType])) {
                continue;
            }
            $ref = (int) $r->refId;
            if (!array_key_exists($ref, $dest[$r->refType] ?? [])) {
                $out[$id]['broken']++;
                continue;
            }
            $d = $dest[$r->refType][$ref];
            if ($d === null) {
                $out[$id]['broken']++;   // chưa gắn điểm đến: không xác định được
                continue;
            }
            $out[$id]['dests'][$d] = $d;
        }
        foreach ($out as $id => $o) {
            $o['dests'] = array_values($o['dests']);
            $o['single'] = (count($o['dests']) === 1 && $o['broken'] === 0) ? $o['dests'][0] : null;
            $out[$id] = $o;
        }

        return $out;
    }

    public function singleDestination(int $itineraryId): ?int
    {
        return $this->resolve([$itineraryId])[$itineraryId]['single'] ?? null;
    }
}
