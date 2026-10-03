<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\admin\AdminModel;
use App\Services\InvalidImageException;
use App\Services\UserMediaService;

class AdminManagementController extends Controller
{
    private $admin;

    public function __construct()
    {
        $this->admin = new AdminModel();
    }

    public function index()
    {
        $title = 'Quản lý tài khoản';
        $admin = $this->admin->getAdmin();
        $managers = $this->admin->getByRole('manager');
        $staffs = $this->admin->getByRole('staff');

        return view('admin.profile-admin', compact('title', 'admin', 'managers', 'staffs'));
    }

    public function updateAdmin(Request $request)
    {
        $admin = $this->admin->getAdmin();
        $password = $request->password;

        if ($password != $admin->password) {
            $password = md5($password);
        }

        $dataUpdate = [
            'fullName' => $request->fullName,
            'password' => $password,
            'email'    => $request->email,
            'address'  => $request->address
        ];

        $update = $this->admin->updateAdmin($dataUpdate);
        $newinfo = $this->admin->getAdmin();

        if ($update) {
            return response()->json(['success' => true, 'data' => $newinfo]);
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
            'userName' => 'required',
            'password' => 'required|min:6',
            'email'    => 'required|email',
            'fullName' => 'required',
            'address'  => 'required',
        ]);

        $data = [
            'username' => $request->userName,
            'password' => md5($request->password),
            'email'    => $request->email,
            'fullName' => $request->fullName,
            'address'  => $request->address,
            'role'     => 'manager'
        ];

        $insert = $this->admin->addAdmin($data);
        return redirect()->back()->with(
            $insert ? 'success' : 'error',
            $insert ? 'Thêm quản lý thành công' : 'Thêm quản lý thất bại'
        );
    }

    // Xóa manager
    public function deleteManager(Request $request)
    {
        $delete = $this->admin->deleteByIdAndRole($request->adminId, 'manager');

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
            'userName' => 'required',
            'password' => 'required|min:6',
            'email'    => 'required|email',
            'fullName' => 'required',
            'address'  => 'required',
        ]);

        $data = [
            'username' => $request->userName,
            'password' => md5($request->password),
            'email'    => $request->email,
            'fullName' => $request->fullName,
            'address'  => $request->address,
            'role'     => 'staff'
        ];

        $insert = $this->admin->addAdmin($data);
        return redirect()->back()->with(
            $insert ? 'success' : 'error',
            $insert ? 'Thêm nhân viên thành công' : 'Thêm nhân viên thất bại'
        );
    }

    // Xóa staff
    public function deleteStaff(Request $request)
    {
        $delete = $this->admin->deleteByIdAndRole($request->adminId, 'staff');

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