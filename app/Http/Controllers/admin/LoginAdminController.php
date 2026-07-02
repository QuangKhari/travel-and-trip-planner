<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\admin\LoginModel;

class LoginAdminController extends Controller
{
    private $login;

    public function __construct()
    {
        $this->login = new LoginModel();
    }
    public function index()
    {
        $title = 'Đăng nhập';

        return view('admin.login', compact('title'));
    }

    public function loginAdmin(Request $request)
    {
        $username = $request->username;
    $password = md5($request->password);

    // Bỏ hardcode admin/123456, tìm trong DB
    $login = $this->login->login($username, $password);

    if ($login !== null) {
        $request->session()->put('admin', $login->userName);
        $request->session()->put('adminRole', $login->role); // ✅ lưu role
        $request->session()->put('adminId', $login->adminId);
        toastr()->success('Đăng nhập thành công');
        return redirect()->route('admin.dashboard');
    } else {
        toastr()->error('Thông tin đăng nhập không chính xác');
        return redirect()->route('admin.login');
    }
}

public function logout(Request $request)
{
    $request->session()->forget(['admin', 'adminRole', 'adminId']); // xóa hết session
    toastr()->success("Đăng xuất thành công!");
    return redirect()->route('admin.login');
}
}
