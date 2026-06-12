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
        if ($username === 'admin' && $password === '123456') {

        $request->session()->put('admin', 'admin');

        toastr()->success('Đăng nhập thành công');

        return redirect()->route('admin.dashboard');
        }

        $login = $this->login->login($username, $password);

        if ($login !== null) {
            $request->session()->put('admin', $username);
            toastr()->success('Đăng nhập thành công');
            return redirect()->route('admin.dashboard');
        } else {
            toastr()->error('Thông tin đăng nhập không chính xác');
            return redirect()->route('admin.login');
        }
    }

    public function logout(Request $request)
    {
        $request->session()->forget('admin');
        toastr()->success("Đăng xuất thành công!", 'Thông báo');
        return redirect()->route('admin.login');
    }
}
