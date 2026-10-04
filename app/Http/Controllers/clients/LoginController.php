<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\clients\Login;

class LoginController extends Controller
{
    private $login;
    public function __construct()
    {
        $this->login = new Login();
    }
    public function index()
    {
        $title = 'Đăng nhập';
        return view('clients.login', compact('title'));
    }

    public function register(Request $request)
    {
        $username_regis = $request->username_regis;
        $email = $request->email;
        $password_regis = $request->password_regis;


        $checkAccountExist = $this->login->checkUserExist($username_regis, $email);

        if ($checkAccountExist) {
            return response()->json([
                'success' => false,
                'message' => 'Tài khoản đã tồn tại'
            ]);
        }
        $dataInsert = [
            'username' => $username_regis,
            'email' => $email,
            'password' => md5($password_regis)
        ];
        $this->login->registerAccount($dataInsert);
        return response()->json([
            'success' => true,
            'message' => 'Đăng ký thành công'
        ]);
    }


    //xử lý người dùng đăng nhập
    public function login(Request $request)
    {
        $data_login = [
            'username' => $request->username,
            'password' => md5($request->password),   // Tuần 2 (L-A-02) sẽ đổi sang bcrypt
        ];

        $user = $this->login->login($data_login);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Thông tin tài khoản không chính xác!',
            ]);
        }

        if ($user->status == 'b') {
            return response()->json([
                'success' => false,
                'message' => 'Tài khoản đã bị chặn',
            ]);
        }

        // Cấp session id mới để chống session fixation (L-A-01)
        $request->session()->regenerate();
        $request->session()->put('username', $user->username);
        $request->session()->put('userId', $user->userId);

        return response()->json([
            'success' => true,
            'message' => 'Đăng nhập thành công',
            'redirectUrl' => route('home'),
        ]);
    }

    public function logout(Request $request)
    {
        // Xóa cả userId (trước đây chỉ xóa username nên người sau thấy hồ sơ người trước)
        $request->session()->forget(['username', 'userId']);
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
