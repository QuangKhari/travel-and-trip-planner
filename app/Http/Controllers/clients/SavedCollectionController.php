<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Content\TripTray;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Mục đã lưu trên tài khoản và các mục trong khay lập kế hoạch là hai danh sách khác nhau. */
class SavedCollectionController extends Controller
{
    public function index(Request $request, TripTray $tray)
    {
        $userId = (int) $request->session()->get('userId');
        $type = (string) $request->query('type', 'all');
        abort_unless(in_array($type, ['all', 'destination', 'food', 'event'], true), 404);

        $tables = [
            'destination' => ['tbl_destinations', 'destinationId', 'isActive', 1, 'destination.show'],
            'food' => ['tbl_foods', 'foodId', 'reviewStatus', 'published', 'foods.show'],
            'event' => ['tbl_events', 'eventId', 'reviewStatus', 'published', 'events.show'],
        ];
        $saved = collect();
        foreach ($tables as $ref => [$table, $pk, $status, $published, $route]) {
            if ($type !== 'all' && $type !== $ref) continue;
            $rows = DB::table('tbl_user_marks as m')
                ->join($table . ' as c', function ($join) use ($pk) {
                    $join->on('c.' . $pk, '=', 'm.refId');
                })
                ->where('m.userId', $userId)
                ->where('m.type', 'saved')
                ->where('m.refType', $ref)
                ->where('c.' . $status, $published)
                ->orderByDesc('m.markedAt')
                ->limit(200)
                ->get(['c.slug', 'c.name', 'm.markedAt']);
            foreach ($rows as $row) {
                $saved->push((object) [
                    'type' => $ref,
                    'slug' => $row->slug,
                    'name' => $row->name,
                    'markedAt' => $row->markedAt,
                    'url' => route($route, ['slug' => $row->slug]),
                ]);
            }
        }
        return view('clients.saved-collection', [
            'title' => 'Danh sách đã lưu',
            'noindex' => true,
            'saved' => $saved->sortByDesc('markedAt')->values(),
            'trayItems' => $tray->items($userId),
            'filterType' => $type,
        ]);
    }
}
