<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Models\admin\UserModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class UserManagementController extends Controller
{
    private UserModel $users;

    /** Bộ lọc trạng thái: khóa => nhãn */
    private const FILTERS = [
        'all' => 'Tất cả',
        'active' => 'Đang hoạt động',
        'pending' => 'Chưa kích hoạt',
        'blocked' => 'Bị chặn',
        'deleted' => 'Đã xóa',
    ];

    public function __construct()
    {
        parent::__construct();
        $this->users = new UserModel();
    }

    public function index(Request $request)
    {
        $title = 'Người dùng';
        $q = trim((string) $request->query('q', ''));
        $f = array_key_exists((string) $request->query('f'), self::FILTERS) ? (string) $request->query('f') : 'all';

        // Đếm theo từng bộ lọc bằng một truy vấn
        $c = DB::table('tbl_users')->selectRaw(
            "COUNT(*) AS total,
             SUM(status IS NULL AND isActive = 'y') AS active,
             SUM(status IS NULL AND isActive = 'n') AS pending,
             SUM(status = 'b') AS blocked,
             SUM(status = 'd') AS deleted"
        )->first();
        $counts = [
            'all' => (int) $c->total, 'active' => (int) $c->active, 'pending' => (int) $c->pending,
            'blocked' => (int) $c->blocked, 'deleted' => (int) $c->deleted,
        ];

        $query = DB::table('tbl_users');
        match ($f) {
            'active' => $query->whereNull('status')->where('isActive', 'y'),
            'pending' => $query->whereNull('status')->where('isActive', 'n'),
            'blocked' => $query->where('status', 'b'),
            'deleted' => $query->where('status', 'd'),
            default => null,
        };
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $query->where(fn($w) => $w->where('fullName', 'like', $like)->orWhere('username', 'like', $like)
                ->orWhere('email', 'like', $like)->orWhere('phoneNumber', 'like', $like));
        }

        $users = $query->orderByDesc('userId')->paginate(15)->withQueryString();

        $bookingCount = $users->count()
            ? DB::table('tbl_booking')->whereIn('userId', $users->pluck('userId'))
                ->selectRaw('userId, COUNT(*) AS c')->groupBy('userId')->pluck('c', 'userId')
            : collect();

        return view('admin.users', [
            'title' => $title, 'users' => $users, 'q' => $q, 'f' => $f,
            'filters' => self::FILTERS, 'counts' => $counts, 'bookingCount' => $bookingCount,
        ]);
    }

    public function activeUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'userId' => 'required|integer|min:1|exists:tbl_users,userId',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        // Không dựa vào số dòng bị đổi: kích hoạt lần hai (0 dòng đổi) không phải là lỗi
        $this->users->updateActive((int) $request->userId);

        return response()->json(['success' => true, 'message' => 'Người dùng đã được kích hoạt thành công!']);
    }

    public function changeStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'userId' => 'required|integer|min:1|exists:tbl_users,userId',
            // 'active' = bỏ chặn / khôi phục (ghi NULL vào cột status)
            'status' => 'required|in:b,d,active',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $status = $request->status === 'active' ? null : $request->status;
        $this->users->changeStatus((int) $request->userId, ['status' => $status]);

        return response()->json([
            'success' => true,
            'status' => $status,   // null | 'b' | 'd' để giao diện tự vẽ lại nhãn
            'message' => 'Trạng thái người dùng đã được cập nhật thành công!',
        ]);
    }
}
