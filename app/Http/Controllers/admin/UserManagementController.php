<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\admin\UserModel;

class UserManagementController extends Controller
{
    private $users;

    public function __construct()
    {
        parent::__construct();
        $this->users = new UserModel();
    }
    public function index(\Illuminate\Http\Request $request)
    {
        $title = 'Quản lý người dùng';

        $all = collect($this->users->getAllUsers());

        // Tìm nhanh theo tên, tên đăng nhập, email, số điện thoại (?q=)
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $needle = mb_strtolower($q);
            $all = $all->filter(function ($u) use ($needle) {
                foreach (['fullName', 'username', 'email', 'phoneNumber'] as $field) {
                    if (isset($u->$field) && mb_strpos(mb_strtolower((string) $u->$field), $needle) !== false) {
                        return true;
                    }
                }
                return false;
            });
        }

        // Phân trang 12 người dùng / trang (?page=)
        $perPage = 12;
        $page = max(1, (int) $request->query('page', 1));
        $users = new \Illuminate\Pagination\LengthAwarePaginator(
            $all->forPage($page, $perPage)->values(),
            $all->count(),
            $perPage,
            $page,
            ['path' => route('admin.users'), 'query' => $q !== '' ? ['q' => $q] : []]
        );

        foreach ($users as $user) {
            if (!$user->fullName) {
                $user->fullName = "Unnamed";
            }
            if (!$user->avatar) {
                $user->avatar = 'unnamed.png';
            }
            if ($user->isActive == 'y')
                $user->isActive = 'Đã kích hoạt';
            else
                $user->isActive = 'Chưa kích hoạt';
        }

        return view('admin.users', compact('title', 'users', 'q'));
    }

    public function activeUser(Request $request)
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'userId' => 'required|integer|min:1|exists:tbl_users,userId',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        // Không dựa vào số dòng bị đổi: kích hoạt lần hai (0 dòng đổi) không phải là lỗi
        $this->users->updateActive((int) $request->userId);

        return response()->json([
            'success' => true,
            'message' => 'Người dùng đã được kích hoạt thành công!'
        ]);
    }

    public function changeStatus(Request $request)
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'userId' => 'required|integer|min:1|exists:tbl_users,userId',
            // 'active' = bỏ chặn / khôi phục (ghi NULL vào cột status)
            'status' => 'required|in:b,d,active',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $userId = (int) $request->userId;
        $status = $request->status === 'active' ? null : $request->status;

        $this->users->changeStatus($userId, ['status' => $status]);

        return response()->json([
            'success' => true,
            'status'  => $this->getStatusText($request->status),
            'message' => 'Trạng thái người dùng đã được cập nhật thành công!'
        ]);
    }

    private function getStatusText($status)
    {
        switch ($status) {
            case 'active':
                return 'Bình thường';
            case 'b':
                return 'Đã chặn';
            case 'd':
                return 'Đã xóa';
            default:
                return 'Không xác định';
        }
    }
}
