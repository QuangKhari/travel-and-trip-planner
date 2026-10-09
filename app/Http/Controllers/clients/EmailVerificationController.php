<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class EmailVerificationController extends Controller
{
    private const TOKEN_MINUTES = 60;
    private const RESEND_SECONDS = 60;

    public function verify(string $token)
    {
        if (!preg_match('/^[A-Za-z0-9]+$/', $token) || strlen($token) !== 64) {
            return view('clients.email-verification', [
                'success' => false,
                'message' => 'Liên kết kích hoạt không hợp lệ hoặc đã hết hạn.',
            ]);
        }

        $tokenHash = hash('sha256', $token);

        $verification = DB::table('tbl_email_verification')
            ->where('tokenHash', $tokenHash)
            ->whereNull('pendingEmail')
            ->where('expiresAt', '>', now())
            ->first();

        if (!$verification) {
            return view('clients.email-verification', [
                'success' => false,
                'message' => 'Liên kết kích hoạt không hợp lệ hoặc đã hết hạn.',
            ]);
        }

        $success = DB::transaction(function () use ($verification, $tokenHash) {
            $user = DB::table('tbl_users')
                ->where('userId', $verification->userId)
                ->lockForUpdate()
                ->first();

            if (!$user || in_array($user->status, ['b', 'd'], true)) {
                return false;
            }

            if ($user->isActive === 'y') {
                DB::table('tbl_email_verification')
                    ->where('userId', $user->userId)
                    ->whereNull('pendingEmail')
                    ->delete();

                return true;
            }

            $currentVerification = DB::table('tbl_email_verification')
                ->where('userId', $user->userId)
                ->where('tokenHash', $tokenHash)
                ->whereNull('pendingEmail')
                ->where('expiresAt', '>', now())
                ->first();

            if (!$currentVerification) {
                return false;
            }

            DB::table('tbl_users')
                ->where('userId', $user->userId)
                ->update([
                    'isActive' => 'y',
                    'updatedDate' => now(),
                ]);

            DB::table('tbl_email_verification')
                ->where('userId', $user->userId)
                ->delete();

            return true;
        });

        return view('clients.email-verification', [
            'success' => $success,
            'message' => $success
                ? 'Tài khoản đã được kích hoạt thành công. Bạn có thể đăng nhập ngay bây giờ.'
                : 'Liên kết kích hoạt không hợp lệ hoặc đã hết hạn.',
        ]);
    }

    public function resend(Request $request)
    {
        $generic = response()->json([
            'success' => true,
            'message' => 'Nếu tài khoản cần kích hoạt, chúng tôi đã gửi lại email xác thực. Vui lòng kiểm tra hộp thư.',
        ]);

        $validator = Validator::make($request->all(), [
            'email' => 'required|email:filter|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Vui lòng nhập email hợp lệ.',
            ]);
        }

        $email = trim($request->email);

        $user = DB::table('tbl_users')
            ->where('email', $email)
            ->first();

        if (!$user || in_array($user->status, ['b', 'd'], true) || $user->isActive === 'y') {
            return $generic;
        }

        $token = Str::random(64);

        $created = DB::transaction(function () use ($user, $token) {
            $lockedUser = DB::table('tbl_users')
                ->where('userId', $user->userId)
                ->lockForUpdate()
                ->first();

            if (!$lockedUser || in_array($lockedUser->status, ['b', 'd'], true) || $lockedUser->isActive === 'y') {
                return false;
            }

            $recent = DB::table('tbl_email_verification')
                ->where('userId', $lockedUser->userId)
                ->where('createdAt', '>', now()->subSeconds(self::RESEND_SECONDS))
                ->exists();

            if ($recent) {
                return false;
            }

            DB::table('tbl_email_verification')
                ->where('userId', $lockedUser->userId)
                ->delete();

            DB::table('tbl_email_verification')->insert([
                'userId' => $lockedUser->userId,
                'tokenHash' => hash('sha256', $token),
                'expiresAt' => now()->addMinutes(self::TOKEN_MINUTES),
            ]);

            return true;
        });

        if (!$created) {
            return $generic;
        }

        $url = route('email.verify', [
            'token' => $token,
        ]);

        try {
            Mail::send(
                'clients.emails.verify-email',
                [
                    'user' => $user,
                    'url' => $url,
                    'minutes' => self::TOKEN_MINUTES,
                ],
                function ($message) use ($user) {
                    $message
                        ->to($user->email, $user->fullName)
                        ->subject('Kích hoạt tài khoản Travela');
                }
            );
        } catch (\Throwable $e) {
            Log::error('Gửi email kích hoạt thất bại: ' . $e->getMessage());
        }

        if (config('mail.default') === 'log') {
            Log::info("[DEV] Link kich hoat tai khoan cho userId {$user->userId}: {$url}");
        }

        return $generic;
    }
}
