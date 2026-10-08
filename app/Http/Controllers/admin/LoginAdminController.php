<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\admin\LoginModel;
use App\Support\PasswordHasher;
use Illuminate\Support\Facades\Validator;

class LoginAdminController extends Controller
{
    private $login;

    public function __construct()
    {
        parent::__construct();
        $this->login = new LoginModel();
    }
    public function index()
    {
        $title = 'Đăng nhập';

        return view('admin.login', compact('title'));
    }

    public function loginAdmin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string|max:50',
            'password' => 'required|string|max:72',
        ]);

        if ($validator->fails()) {
            toastr()->error('Vui lòng nhập đầy đủ thông tin đăng nhập.');
            return redirect()->route('admin.login');
        }

        $username = trim((string) $request->username);
        $password = (string) $request->password;

        $admin = $this->login->findByUserName($username);

        // Tên cột là passWord; đọc cả hai cách viết để không phụ thuộc hoa/thường
        $stored = $admin ? ($admin->passWord ?? $admin->password ?? null) : null;

        if ($admin && PasswordHasher::check($password, $stored)) {
            if (PasswordHasher::needsUpgrade($stored)) {
                $this->login->updatePasswordById($admin->adminId, PasswordHasher::make($password));
            }

            $request->session()->regenerate();
            $request->session()->put('admin', $admin->userName);
            $request->session()->put('adminRole', $admin->role);
            $request->session()->put('adminId', $admin->adminId);
            toastr()->success('Đăng nhập thành công');
            return redirect()->route('admin.dashboard');
        }

        toastr()->error('Thông tin đăng nhập không chính xác');
        return redirect()->route('admin.login');
    }

    public function logout(Request $request)
    {
        $request->session()->forget(['admin', 'adminRole', 'adminId']);
        $request->session()->regenerate();
        $request->session()->regenerateToken();
        toastr()->success("Đăng xuất thành công!");
        return redirect()->route('admin.login');
    }
}
