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
    public function index()
    {
        $title = 'Quản lý người dùng';

        $users = $this->users->getAllUsers();

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
        // dd($users);

        return view('admin.users', compact('title', 'users'));
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
