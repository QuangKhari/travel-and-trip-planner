<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\admin\AdminModel;
use App\Services\InvalidImageException;
use App\Services\UserMediaService;
use App\Support\PasswordHasher;
use Illuminate\Database\QueryException;

class AdminManagementController extends Controller
{
    private $admin;

    public function __construct()
    {
        parent::__construct();
        $this->admin = new AdminModel();
    }

    public function index()
    {
        $title = 'Quản lý tài khoản';
        // Tài khoản đang đăng nhập (không phải "admin đầu tiên tìm thấy")
        $admin = $this->admin->getAdminById(session('adminId'));
        $managers = $this->admin->getByRole('manager');
        $staffs = $this->admin->getByRole('staff');

        return view('admin.profile-admin', compact('title', 'admin', 'managers', 'staffs'));
    }

    public function updateAdmin(Request $request)
    {
        $request->validate([
            'fullName' => 'required|string|max:50',
            'email'    => ['required', 'email:filter', 'max:50', 'regex:/^[^\s@]+@[^\s@]+\.[A-Za-z]{2,}$/'],
            'address'  => 'required|string|max:255',
            'password' => 'nullable|string|min:8|max:72',
        ]);

        $dataUpdate = [
            'fullName' => $request->fullName,
            'email'    => $request->email,
            'address'  => $request->address,
        ];

        // Chỉ đổi mật khẩu khi người dùng nhập mật khẩu mới (L-A-18)
        if ($request->filled('password')) {
            $dataUpdate['passWord'] = PasswordHasher::make($request->password);
        }

        $adminId = (int) session('adminId');

        try {
            $update = $this->admin->updateAdmin($adminId, $dataUpdate);
        } catch (QueryException $e) {
            $errorCode = $e->errorInfo[1] ?? $e->getCode();

            if ((int) $errorCode === 1062) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email đã được sử dụng bởi tài khoản khác.',
                ], 422);
            }

            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Không thể cập nhật tài khoản.',
            ], 500);
        }

        $newinfo = $this->admin->getAdminById($adminId);

        if ($update) {
            // Chỉ trả các trường cần hiển thị, KHÔNG trả hash mật khẩu
            return response()->json([
                'success' => true,
                'data' => [
                    'fullName' => $newinfo->fullName,
                    'email'    => $newinfo->email,
                    'address'  => $newinfo->address,
                ],
            ]);
        }
        return response()->json(['success' => false, 'message' => 'Không có thông tin nào thay đổi!']);
    }

    public function updateAvatar(Request $req, UserMediaService $media)
    {
        $req->validate([
            'avatarAdmin' => 'required|file|max:5120',
        ]);

        try {
            $media->storeAdminAvatar($req->file('avatarAdmin'));
        } catch (InvalidImageException $e) {
            return response()->json(['error' => true, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => 'Cập nhật ảnh thành công!']);
    }

    // Thêm manager
    public function addManager(Request $request)
    {
        $request->validate([
            'userName' => 'required|string|max:50',
            'password' => 'required|string|min:8|max:72',
            'email'    => ['required', 'email:filter', 'max:50', 'regex:/^[^\s@]+@[^\s@]+\.[A-Za-z]{2,}$/'],
            'fullName' => 'required|string|max:50',
            'address'  => 'required|string|max:255',
        ]);

        $data = [
            'username' => $request->userName,
            'password' => PasswordHasher::make($request->password),
            'email'    => $request->email,
            'fullName' => $request->fullName,
            'address'  => $request->address,
            'role'     => 'manager'
        ];

        try {
            $insert = $this->admin->addAdmin($data);
        } catch (QueryException $e) {
            $errorCode = $e->errorInfo[1] ?? $e->getCode();

            if ((int) $errorCode === 1062) {
                return redirect()
                    ->back()
                    ->withInput()
                    ->with('error', 'Tên đăng nhập hoặc email đã tồn tại.');
            }

            report($e);
            return redirect()->back()->with('error', 'Không thể thêm quản lý.');
        }

        return redirect()->back()->with(
            $insert ? 'success' : 'error',
            $insert ? 'Thêm quản lý thành công' : 'Thêm quản lý thất bại'
        );
    }

    // Xóa manager
    public function deleteManager(Request $request)
    {
        $request->validate([
            'adminId' => 'required|integer|min:1|exists:tbl_admin,adminId',
        ]);

        $adminId = (int) $request->input('adminId');
        $delete = $this->admin->deleteByIdAndRole($adminId, 'manager');

        if ($request->ajax()) {
            return response()->json([
                'success' => $delete ? true : false,
                'message' => $delete ? 'Xóa quản lý thành công' : 'Xóa quản lý thất bại'
            ]);
        }
        return redirect()->back()->with(
            $delete ? 'success' : 'error',
            $delete ? 'Xóa quản lý thành công' : 'Xóa quản lý thất bại'
        );
    }

    // Thêm staff
    public function addStaff(Request $request)
    {
        $request->validate([
            'userName' => 'required|string|max:50',
            'password' => 'required|string|min:8|max:72',
            'email'    => ['required', 'email:filter', 'max:50', 'regex:/^[^\s@]+@[^\s@]+\.[A-Za-z]{2,}$/'],
            'fullName' => 'required|string|max:50',
            'address'  => 'required|string|max:255',
        ]);

        $data = [
            'username' => $request->userName,
            'password' => PasswordHasher::make($request->password),
            'email'    => $request->email,
            'fullName' => $request->fullName,
            'address'  => $request->address,
            'role'     => 'staff'
        ];

        try {
            $insert = $this->admin->addAdmin($data);
        } catch (QueryException $e) {
            $errorCode = $e->errorInfo[1] ?? $e->getCode();

            if ((int) $errorCode === 1062) {
                return redirect()
                    ->back()
                    ->withInput()
                    ->with('error', 'Tên đăng nhập hoặc email đã tồn tại.');
            }

            report($e);
            return redirect()->back()->with('error', 'Không thể thêm nhân viên.');
        }

        return redirect()->back()->with(
            $insert ? 'success' : 'error',
            $insert ? 'Thêm nhân viên thành công' : 'Thêm nhân viên thất bại'
        );
    }

    // Xóa staff
    public function deleteStaff(Request $request)
    {
        $request->validate([
            'adminId' => 'required|integer|min:1|exists:tbl_admin,adminId',
        ]);

        $adminId = (int) $request->input('adminId');

        $delete = $this->admin->deleteByIdAndRole($adminId, 'staff');

        if ($request->ajax()) {
            return response()->json([
                'success' => $delete ? true : false,
                'message' => $delete ? 'Xóa nhân viên thành công' : 'Xóa nhân viên thất bại'
            ]);
        }
        return redirect()->back()->with(
            $delete ? 'success' : 'error',
            $delete ? 'Xóa nhân viên thành công' : 'Xóa nhân viên thất bại'
        );
    }
}
