<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Nhật ký thao tác quản trị (D-08). Chỉ đọc; admin và manager xem được (§12.2). */
class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $title = 'Nhật ký thao tác';
        $f = $request->validate([
            'q' => 'nullable|string|max:100',
            'entity' => 'nullable|string|max:40',
            'actor' => 'nullable|integer',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $q = DB::table('tbl_audit_log')->orderByDesc('auditId');
        if (!empty($f['entity'])) {
            $q->where('entity', $f['entity']);
        }
        if (!empty($f['actor'])) {
            $q->where('actorId', $f['actor']);
        }
        if (!empty($f['from'])) {
            $q->where('createdAt', '>=', $f['from'] . ' 00:00:00');
        }
        if (!empty($f['to'])) {
            $q->where('createdAt', '<=', $f['to'] . ' 23:59:59');
        }
        if (!empty($f['q'])) {
            $kw = '%' . addcslashes($f['q'], '%_\\') . '%';
            $q->where(fn($w) => $w->where('summary', 'like', $kw)->orWhere('entityId', $f['q'])->orWhere('action', 'like', $kw));
        }
        $rows = $q->paginate(30)->withQueryString();
        $actors = DB::table('tbl_audit_log')->whereNotNull('actorId')->select('actorId', 'actorName')->distinct()->orderBy('actorName')->get();
        $today = DB::table('tbl_audit_log')->where('createdAt', '>=', now()->startOfDay())->count();

        return view('admin.audit.index', compact('title', 'rows', 'actors', 'f', 'today'));
    }

    /** Đường dẫn tới đối tượng của một dòng nhật ký (nếu có trang). */
    public static function link(object $row): ?string
    {
        return match ($row->entity) {
            'booking' => $row->entityId ? route('admin.booking-detail', ['id' => $row->entityId]) : null,
            'refund' => route('admin.refunds', ['status' => 'all']),
            'tour' => route('admin.tours'),
            'departure' => route('admin.departures'),
            'promotion' => route('admin.promotion'),
            'user' => route('admin.users'),
            'review' => $row->entityId && \Illuminate\Support\Facades\Route::has('admin.reviews.show') ? route('admin.reviews.show', $row->entityId) : route('admin.reviews'),
            'ticket' => $row->entityId ? route('admin.support.show', $row->entityId) : route('admin.support'),
            'admin' => route('admin.admin'),
            default => null,
        };
    }

    public static function label(string $action): string
    {
        return AuditLog::label($action);
    }
}
