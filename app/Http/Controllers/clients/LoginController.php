<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\clients\Login;
use Illuminate\Support\Facades\Validator;
use App\Support\PasswordHasher;
use Illuminate\Database\QueryException;

class LoginController extends Controller
{
    private $login;

    public function __construct()
    {
        parent::__construct();
        $this->login = new Login();
    }

    public function index()
    {
        $title = 'Đăng nhập';
        return view('clients.login', compact('title'));
    }

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'username_regis' => 'required|string|max:50',
            'email'          => ['required', 'email:filter', 'max:255', 'regex:/^[^\s@]+@[^\s@]+\.[A-Za-z]{2,}$/'],
            'password_regis' => 'required|string|min:6|max:72',
        ], [
            'username_regis.required' => 'Vui lòng nhập tên tài khoản.',
            'username_regis.max'      => 'Tên tài khoản tối đa 50 ký tự.',
            'email.required'          => 'Vui lòng nhập email.',
            'email.email'             => 'Email không hợp lệ.',
            'email.regex'             => 'Email không hợp lệ (cần có dạng ten@ten-mien.com).',
            'password_regis.required' => 'Vui lòng nhập mật khẩu.',
            'password_regis.min'      => 'Mật khẩu phải có ít nhất 6 ký tự.',
            'password_regis.max'      => 'Mật khẩu tối đa 72 ký tự.',
        ]);

        if ($validator->fails()) {
            // Trả 200 + success=false để JS hiện đúng thông báo
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ]);
        }

        $username_regis = trim($request->username_regis);
        $email = trim($request->email);

        if ($this->login->checkUserExist($username_regis, $email)) {
            return response()->json([
                'success' => false,
                'message' => 'Tài khoản đã tồn tại'
            ]);
        }

        $dataInsert = [
            'username' => $username_regis,
            'fullName' => $username_regis,   // cột fullName bắt buộc; người dùng sửa lại ở trang hồ sơ
            'email'    => $email,
            'password' => PasswordHasher::make($request->password_regis),
        ];
        try {
            $this->login->registerAccount($dataInsert);
        } catch (QueryException $e) {
            if ((string) $e->getCode() === '23000') {
                return response()->json([
                    'success' => false,
                    'message' => 'Tài khoản hoặc email đã tồn tại.'
                ]);
            }

            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Không thể đăng ký tài khoản. Vui lòng thử lại sau.'
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Đăng ký thành công'
        ]);
    }


    //xử lý người dùng đăng nhập
    public function login(Request $request)
    {
        $username = trim((string) $request->username);
        $password = (string) $request->password;

        $user = $this->login->findByUsername($username);

        // Sai username hoặc sai mật khẩu: cùng một thông báo, không cho biết tài khoản có tồn tại hay không
        if (!$user || !PasswordHasher::check($password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Thông tin tài khoản không chính xác!',
            ]);
        }

        // Chỉ người nhập đúng mật khẩu mới biết tài khoản bị chặn
        if ($user->status === 'b') {
            return response()->json([
                'success' => false,
                'message' => 'Tài khoản đã bị chặn',
            ]);
        }

        if ($user->isActive !== 'y') {
            return response()->json([
                'success' => false,
                'message' => 'Tài khoản chưa được kích hoạt.',
            ]);
        }

        // Tài khoản đã xóa: coi như không tồn tại
        if ($user->status === 'd') {
            return response()->json([
                'success' => false,
                'message' => 'Thông tin tài khoản không chính xác!',
            ]);
        }

        // Nâng cấp MD5 -> bcrypt ngay lúc người dùng vừa nhập đúng mật khẩu
        if (PasswordHasher::needsUpgrade($user->password)) {
            $this->login->updatePassword($user->userId, PasswordHasher::make($password));
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
