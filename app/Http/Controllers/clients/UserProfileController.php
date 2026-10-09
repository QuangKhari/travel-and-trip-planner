<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\clients\User;
use App\Services\InvalidImageException;
use App\Services\UserMediaService;
use App\Support\Avatar;
use Illuminate\Support\Facades\Validator;
use App\Support\PasswordHasher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;


class UserProfileController extends Controller
{
    public function __construct()
    {
        parent::__construct(); // Gọi constructor của Controller để khởi tạo $user
    }

    public function index()
    {

        $title = 'Thông tin cá nhân';
        $userId = $this->getUserId();
        $user = $this->user->getUser($userId);
        //dd(session()->all());

        if (!$userId) {
            return redirect('/login')->withErrors('User not found');
        }

        $user = $this->user->getUser($userId);
        return view('clients.user-profile', compact('title', 'user'));
    }

    public function update(Request $req)
    {
        $userId = $this->getUserId();

        $validator = Validator::make($req->all(), [
            'fullName' => 'required|string|max:50',
            'address'  => 'nullable|string|max:255',
            'email'    => ['required', 'email:filter', 'max:255', 'regex:/^[^\s@]+@[^\s@]+\.[A-Za-z]{2,}$/'],
            'phone'    => ['nullable', 'regex:/^[0-9+\-\s().]{8,15}$/'],
        ], [
            'fullName.required' => 'Vui lòng nhập họ tên.',
            'fullName.max'      => 'Họ tên tối đa 50 ký tự.',
            'email.required'    => 'Vui lòng nhập email.',
            'email.email'       => 'Email không hợp lệ.',
            'email.regex'       => 'Email không hợp lệ (cần có dạng ten@ten-mien.com).',
            'phone.regex'       => 'Số điện thoại không hợp lệ (8–15 ký tự).',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()]);
        }

        $email = trim($req->email);
        $token = Str::random(64);
        $emailChanged = false;
        $mailUser = null;

        try {
            $result = DB::transaction(function () use ($userId, $req, $email, $token, &$emailChanged, &$mailUser) {
                $user = DB::table('tbl_users')
                    ->where('userId', $userId)
                    ->lockForUpdate()
                    ->first();

                if (!$user) {
                    return [
                        'success' => false,
                        'message' => 'Không tìm thấy tài khoản.',
                    ];
                }

                if ($email !== trim($user->email)) {
                    $emailChanged = true;

                    $emailTaken = DB::table('tbl_users')
                        ->where('email', $email)
                        ->where('userId', '!=', $userId)
                        ->exists();

                    if ($emailTaken) {
                        return [
                            'success' => false,
                            'message' => 'Email này đã được tài khoản khác sử dụng.',
                        ];
                    }

                    DB::table('tbl_email_verification')
                        ->where('userId', $userId)
                        ->delete();

                    DB::table('tbl_email_verification')->insert([
                        'userId' => $userId,
                        'tokenHash' => hash('sha256', $token),
                        'pendingEmail' => $email,
                        'expiresAt' => now()->addMinutes(60),
                    ]);

                    $mailUser = (object) [
                        'userId' => $user->userId,
                        'fullName' => $req->fullName,
                    ];
                }

                DB::table('tbl_users')
                    ->where('userId', $userId)
                    ->update([
                        'fullName' => trim($req->fullName),
                        'address' => $req->address,
                        'phoneNumber' => $req->phone,
                        'updatedDate' => now(),
                    ]);

                return [
                    'success' => true,
                ];
            });

            if (!$result['success']) {
                return response()->json($result);
            }

            if ($emailChanged) {
                $url = route('email.change.verify', [
                    'token' => $token,
                ]);

                try {
                    Mail::send(
                        'clients.emails.verify-email-change',
                        [
                            'user' => $mailUser,
                            'url' => $url,
                            'minutes' => 60,
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
                        'userId' => $userId,
                        'email' => $email,
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Thông tin khác đã được cập nhật nhưng chưa thể gửi email xác minh. Vui lòng thử lại bằng chức năng gửi lại email.',
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'emailVerificationRequired' => true,
                    'message' => 'Thông tin đã được cập nhật. Vui lòng kiểm tra email mới và bấm liên kết xác minh để hoàn tất việc đổi email.',
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Cập nhật thông tin thành công!',
            ]);
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                return response()->json([
                    'success' => false,
                    'message' => 'Email này đã được tài khoản khác sử dụng.',
                ]);
            }

            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Không thể cập nhật thông tin tài khoản.',
            ], 500);
        }
    }

    public function changePassword(Request $req)
    {
        $validator = Validator::make($req->all(), [
            'oldPass' => 'required|string',
            'newPass' => 'required|string|min:8|max:72',
        ], [
            'oldPass.required' => 'Vui lòng nhập mật khẩu cũ.',
            'newPass.required' => 'Vui lòng nhập mật khẩu mới.',
            'newPass.min'      => 'Mật khẩu mới phải có ít nhất 8 ký tự.',
            'newPass.max'      => 'Mật khẩu mới tối đa 72 ký tự.',
        ]);

        // 422: JS hiện thông báo từ xhr.responseJSON.message
        if ($validator->fails()) {
            return response()->json(['error' => true, 'message' => $validator->errors()->first()], 422);
        }

        $userId = $this->getUserId();
        $user = $this->user->getUser($userId);

        if (!$user || !PasswordHasher::check($req->oldPass, $user->password)) {
            return response()->json(['error' => true, 'message' => 'Mật khẩu cũ không chính xác.'], 422);
        }

        if (PasswordHasher::check($req->newPass, $user->password)) {
            return response()->json(['error' => true, 'message' => 'Mật khẩu mới trùng với mật khẩu cũ!'], 422);
        }

        $this->user->updateUser($userId, ['password' => PasswordHasher::make($req->newPass)]);

        return response()->json(['success' => true, 'message' => 'Đổi mật khẩu thành công!']);
    }

    public function changeAvatar(Request $req, UserMediaService $media)
    {
        $userId = $this->getUserId();

        // Loại file thật được kiểm tra trong service (không tin đuôi file)
        $req->validate([
            'avatar' => 'required|file|max:5120', // 5MB
        ]);

        $user = $this->user->getUser($userId);

        try {
            $path = $media->storeAvatar($req->file('avatar'), (int) $userId);
        } catch (InvalidImageException $e) {
            return response()->json(['error' => true, 'message' => $e->getMessage()], 422);
        }

        $changed = $path !== $user->avatar;
        $update = $this->user->updateUser($userId, ['avatar' => $path]);

        if (!$update && $changed) {
            $media->deleteAvatar($path); // DB không lưu được → không để file mồ côi
            return response()->json(['error' => true, 'message' => 'Có vấn đề khi cập nhật ảnh!']);
        }

        if ($changed) {
            // Chỉ xóa ảnh cũ nếu là ảnh do hệ thống sinh ra; ảnh mặc định/legacy được giữ nguyên
            $media->deleteAvatar($user->avatar);
        }

        $req->session()->put('avatar', $path);

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật ảnh thành công!',
            'url'     => Avatar::url($path),
        ]);
    }
}
