<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\clients\ContactController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Hộp thư liên hệ (tbl_contact_messages): xem, đổi trạng thái, ghi chú nội bộ. */
class ContactInboxController extends Controller
{
    private const STATUSES = ['new' => 'Mới', 'read' => 'Đã đọc', 'replied' => 'Đã phản hồi', 'closed' => 'Đã đóng'];

    public function index(Request $request)
    {
        $status = array_key_exists((string) $request->query('status'), self::STATUSES) ? (string) $request->query('status') : null;
        $q = DB::table('tbl_contact_messages');
        $counts = (clone $q)->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');
        if ($status) {
            $q->where('status', $status);
        }
        if ($request->query('mail') === 'failed') {
            $q->where('mailSent', 0);
        }
        if ($s = trim((string) $request->query('q', ''))) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $s) . '%';
            $q->where(fn($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('message', 'like', $like));
        }

        return view('admin.contact.index', [
            'title' => 'Hộp thư liên hệ',
            'rows' => $q->orderByRaw("status = 'new' DESC")->orderByDesc('createdAt')->paginate(20)->withQueryString(),
            'counts' => $counts, 'statuses' => self::STATUSES, 'status' => $status, 'topics' => ContactController::TOPICS,
            'adminNames' => DB::table('tbl_admin')->pluck('userName', 'adminId'),
            'search' => (string) $request->query('q', ''), 'mail' => (string) $request->query('mail', ''),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $d = $request->validate(['status' => ['required', 'in:new,read,replied,closed'], 'adminNote' => ['nullable', 'max:2000']]);
        DB::table('tbl_contact_messages')->where('messageId', $id)->update($d + ['handledBy' => session('adminId'), 'handledAt' => now()]);

        return back()->with('success', 'Đã cập nhật.');
    }

    public function destroy(int $id)
    {
        DB::table('tbl_contact_messages')->where('messageId', $id)->delete();

        return back()->with('success', 'Đã xóa.');
    }
}
