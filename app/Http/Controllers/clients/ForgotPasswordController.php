<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Models\clients\Login;
use App\Support\PasswordHasher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ForgotPasswordController extends Controller
{
    private const TOKEN_MINUTES  = 60;   // hạn dùng của liên kết
    private const RESEND_SECONDS = 60;   // mỗi tài khoản chỉ được yêu cầu 1 lần / 60 giây

    private $login;

    public function __construct()
    {
        $this->login = new Login();
    }

    // Trang "Quên mật khẩu": nhập email
    public function index()
    {
        $title = 'Quên mật khẩu';
        return view('clients.forgotpassword', compact('title'));
    }

    // Bước 1: nhận email, gửi liên kết đặt lại. LUÔN trả cùng một thông báo (không lộ email có tồn tại hay không)
    public function sendLink(Request $request)
    {
        $generic = response()->json([
            'success' => true,
            'message' => 'Nếu email này có tài khoản, chúng tôi đã gửi hướng dẫn đặt lại mật khẩu. Vui lòng kiểm tra hộp thư.',
        ]);

        $validator = Validator::make($request->all(), ['email' => 'required|email|max:255']);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Vui lòng nhập email hợp lệ.']);
        }

        $email = trim($request->email);
        $users = DB::table('tbl_users')->where('email', $email)->get();

        // Chỉ gửi khi email thuộc đúng MỘT tài khoản đang hoạt động
        if ($users->count() !== 1 || in_array($users[0]->status, ['b', 'd'], true)) {
            return $generic;
        }
        $user = $users[0];

        // Chống gửi dồn dập
        $recent = DB::table('tbl_password_reset')
            ->where('userId', $user->userId)
            ->where('createdAt', '>', now()->subSeconds(self::RESEND_SECONDS))
            ->exists();
        if ($recent) {
            return $generic;
        }

        $token = Str::random(64);

        // Mỗi tài khoản chỉ có một liên kết còn hiệu lực
        DB::table('tbl_password_reset')->where('userId', $user->userId)->delete();
        DB::table('tbl_password_reset')->insert([
            'userId'    => $user->userId,
            'tokenHash' => hash('sha256', $token),
            'expiresAt' => now()->addMinutes(self::TOKEN_MINUTES),
        ]);

        $url = route('password.reset.form', ['token' => $token]);

        try {
            Mail::send(
                'clients.emails.reset-password',
                ['user' => $user, 'url' => $url, 'minutes' => self::TOKEN_MINUTES],
                function ($message) use ($user) {
                    $message->to($user->email, $user->fullName)->subject('Đặt lại mật khẩu Travela');
                }
            );
        } catch (\Throwable $e) {
            Log::error('Gửi email đặt lại mật khẩu thất bại: ' . $e->getMessage());
        }

        // Chỉ khi dùng MAIL_MAILER=log (phát triển): ghi liên kết ra log để lấy ra dùng
        if (config('mail.default') === 'log') {
            Log::info("[DEV] Link dat lai mat khau cho userId {$user->userId}: {$url}");
        }

        return $generic;
    }

    // Bước 2: mở liên kết trong email
    public function showResetForm($token)
    {
        $title = 'Đặt lại mật khẩu';
        $valid = $this->findValidReset((string) $token) !== null;

        return view('clients.reset-password', compact('title', 'token', 'valid'));
    }

    // Bước 3: đặt mật khẩu mới
    public function reset(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token'       => 'required|string|size:64',
            'password'    => 'required|string|min:6|max:72',
            're_password' => 'required|same:password',
        ], [
            'token.required'       => 'Liên kết không hợp lệ.',
            'token.size'           => 'Liên kết không hợp lệ.',
            'password.required'    => 'Vui lòng nhập mật khẩu mới.',
            'password.min'         => 'Mật khẩu phải có ít nhất 6 ký tự.',
            'password.max'         => 'Mật khẩu tối đa 72 ký tự.',
            're_password.required' => 'Vui lòng nhập lại mật khẩu.',
            're_password.same'     => 'Mật khẩu xác nhận không khớp.',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()]);
        }

        $expired = ['success' => false, 'message' => 'Liên kết đã hết hạn hoặc không hợp lệ. Vui lòng yêu cầu lại.'];

        $reset = $this->findValidReset($request->token);
        if (!$reset) {
            return response()->json($expired);
        }

        $user = DB::table('tbl_users')->where('userId', $reset->userId)->first();
        if (!$user || in_array($user->status, ['b', 'd'], true)) {
            return response()->json($expired);
        }

        // Đổi theo userId lấy từ token, KHÔNG theo email hay username do người gửi cung cấp (L-A-05)
        DB::transaction(function () use ($reset, $request) {
            $this->login->updatePassword($reset->userId, PasswordHasher::make($request->password));
            // Dùng một lần: xóa mọi liên kết của tài khoản này
            DB::table('tbl_password_reset')->where('userId', $reset->userId)->delete();
        });

        return response()->json([
            'success'     => true,
            'message'     => 'Đổi mật khẩu thành công',
            'redirectUrl' => route('login'),
        ]);
    }

    private function findValidReset(string $token)
    {
        if (strlen($token) !== 64) {
            return null;
        }

        return DB::table('tbl_password_reset')
            ->where('tokenHash', hash('sha256', $token))
            ->where('expiresAt', '>', now())
            ->first();
    }
}
