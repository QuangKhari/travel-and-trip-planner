<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class LoginAdminController extends Controller
{
    public function index()
    {
        $title = 'Đăng nhập';

        return view('admin.login', compact('title'));
    }
    public function loginAdmin(Request $request)
    {
        $username = $request->username;
        $password = $request->password;

        if (
            $username === 'admin' &&
            $password === '123456'
        ) {

            $request->session()->put('admin', $username);

            toastr()->success('Đăng nhập thành công');

            return redirect()->route('admin.dashboard');
        }

        toastr()->error('Thông tin đăng nhập không chính xác');

        return redirect()->route('admin.login');
    }

    public function logout(Request $request)
    {
        $request->session()->forget('admin');

        toastr()->success('Đăng xuất thành công!');

        return redirect()->route('admin.login');
    }
}
