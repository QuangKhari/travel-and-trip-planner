<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\clients\Login;

class ForgotPasswordController extends Controller
{
    private $login;

    public function __construct()
    {
        $this->login = new Login();
    }

    // Hiển thị trang "Quên mật khẩu"
    public function index()
    {
        $title = 'Quên mật khẩu';
        return view('clients.forgotpassword', compact('title'));
    }

    // Bước 1: xác thực username + email có khớp nhau trong hệ thống không
    public function verifyAccount(Request $request)
    {
        $username = $request->username;
        $email = $request->email;

        $user = $this->login->checkAccountMatch($username, $email);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Tên tài khoản hoặc email không chính xác'
            ]);
        }

        if ($user->status == 'b') {
            return response()->json([
                'success' => false,
                'message' => 'Tài khoản đã bị chặn'
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Xác thực thành công'
        ]);
    }

    // Bước 2: xác thực lại rồi cập nhật mật khẩu mới
    public function reset(Request $request)
    {
        $username    = $request->username;
        $email       = $request->email;
        $password    = $request->password;
        $re_password = $request->re_password;

        if (empty($password) || strlen($password) < 6) {
            return response()->json([
                'success' => false,
                'message' => 'Mật khẩu phải có ít nhất 6 ký tự'
            ]);
        }

        if ($password !== $re_password) {
            return response()->json([
                'success' => false,
                'message' => 'Mật khẩu xác nhận không khớp'
            ]);
        }

        // Xác thực lại username + email để tránh việc gọi thẳng API bước 2 mà bỏ qua bước 1
        $user = $this->login->checkAccountMatch($username, $email);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Tên tài khoản hoặc email không chính xác'
            ]);
        }

        $this->login->updatePassword($email, md5($password));

        return response()->json([
            'success' => true,
            'message' => 'Đổi mật khẩu thành công',
            'redirectUrl' => route('login')
        ]);
    }
}