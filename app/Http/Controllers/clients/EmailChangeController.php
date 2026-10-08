<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class EmailChangeController extends Controller
{
    private const TOKEN_MINUTES = 60;
    private const RESEND_SECONDS = 60;

    public function verify(string $token)
    {
        if (!preg_match('/^[A-Za-z0-9]+$/', $token) || strlen($token) !== 64) {
            return view('clients.email-change-verification', [
                'success' => false,
                'message' => 'Liên kết xác minh email không hợp lệ hoặc đã hết hạn.',
            ]);
        }

        $tokenHash = hash('sha256', $token);

        $success = DB::transaction(function () use ($tokenHash) {
            $verification = DB::table('tbl_email_verification')
                ->where('tokenHash', $tokenHash)
                ->whereNotNull('pendingEmail')
                ->where('expiresAt', '>', now())
                ->lockForUpdate()
                ->first();

            if (!$verification) {
                return false;
            }

            $user = DB::table('tbl_users')
                ->where('userId', $verification->userId)
                ->lockForUpdate()
                ->first();

            if (!$user || in_array($user->status, ['b', 'd'], true)) {
                return false;
            }

            $pendingEmail = trim($verification->pendingEmail);

            $emailTaken = DB::table('tbl_users')
                ->where('email', $pendingEmail)
                ->where('userId', '!=', $user->userId)
                ->exists();

            if ($emailTaken) {
                return false;
            }

            DB::table('tbl_users')
                ->where('userId', $user->userId)
                ->update([
                    'email' => $pendingEmail,
                    'updatedDate' => now(),
                ]);

            DB::table('tbl_email_verification')
                ->where('userId', $user->userId)
                ->delete();

            return true;
        });

        return view('clients.email-change-verification', [
            'success' => $success,
            'message' => $success
                ? 'Email mới đã được xác minh và cập nhật thành công.'
                : 'Liên kết xác minh email không hợp lệ, đã hết hạn hoặc email đã được tài khoản khác sử dụng.',
        ]);
    }

    public function resend(Request $request)
    {
        $userId = $this->getUserId();

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'Phiên đăng nhập không hợp lệ.',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email:filter', 'max:255', 'regex:/^[^\s@]+@[^\s@]+\.[A-Za-z]{2,}$/'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Email không hợp lệ.',
            ]);
        }

        $email = trim($request->email);
        $token = Str::random(64);

        $created = DB::transaction(function () use ($userId, $email, $token) {
            $user = DB::table('tbl_users')
                ->where('userId', $userId)
                ->lockForUpdate()
                ->first();

            if (!$user || in_array($user->status, ['b', 'd'], true)) {
                return null;
            }

            $verification = DB::table('tbl_email_verification')
                ->where('userId', $userId)
                ->whereNotNull('pendingEmail')
                ->lockForUpdate()
                ->first();

            if (!$verification) {
                return null;
            }

            if ($verification->pendingEmail !== $email) {
                return null;
            }

            if ($verification->createdAt > now()->subSeconds(self::RESEND_SECONDS)) {
                return null;
            }

            $emailTaken = DB::table('tbl_users')
                ->where('email', $email)
                ->where('userId', '!=', $userId)
                ->exists();

            if ($emailTaken) {
                return null;
            }

            DB::table('tbl_email_verification')
                ->where('userId', $userId)
                ->delete();

            DB::table('tbl_email_verification')->insert([
                'userId' => $userId,
                'tokenHash' => hash('sha256', $token),
                'pendingEmail' => $email,
                'expiresAt' => now()->addMinutes(self::TOKEN_MINUTES),
            ]);

            return $user;
        });

        if (!$created) {
            return response()->json([
                'success' => false,
                'message' => 'Không thể gửi lại email xác minh. Vui lòng thử lại sau.',
            ]);
        }

        $url = route('email.change.verify', [
            'token' => $token,
        ]);

        try {
            Mail::send(
                'clients.emails.verify-email-change',
                [
                    'user' => $created,
                    'url' => $url,
                    'minutes' => self::TOKEN_MINUTES,
                    'newEmail' => $email,
                ],
                function ($message) use ($email) {
                    $message
                        ->to($email)
                        ->subject('Xác minh email mới - Travela');
                }
            );
        } catch (\Throwable $e) {
            Log::error('Gửi email xác minh email mới thất bại: ' . $e->getMessage(), [
                'userId' => $created->userId,
                'email' => $email,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Không thể gửi email xác minh. Vui lòng thử lại sau.',
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Đã gửi lại email xác minh đến email mới.',
        ]);
    }
}
