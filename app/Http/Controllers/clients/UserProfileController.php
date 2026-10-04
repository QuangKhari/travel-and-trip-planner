<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\clients\User;
use App\Services\InvalidImageException;
use App\Services\UserMediaService;
use App\Support\Avatar;
use Illuminate\Support\Facades\Validator;


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
            // Trả 200 + success=false để JS hiện đúng thông báo (JS chỉ đọc message khi HTTP 200)
            return response()->json(['success' => false, 'message' => $validator->errors()->first()]);
        }

        $email = trim($req->email);

        if ($this->user->emailTakenByOther($email, $userId)) {
            return response()->json([
                'success' => false,
                'message' => 'Email này đã được tài khoản khác sử dụng.',
            ]);
        }

        // updateUser trả về 0 khi dữ liệu không thay đổi: đó KHÔNG phải lỗi
        $this->user->updateUser($userId, [
            'fullName'    => trim($req->fullName),
            'address'     => $req->address,
            'email'       => $email,
            'phoneNumber' => $req->phone,
        ]);

        return response()->json(['success' => true, 'message' => 'Cập nhật thông tin thành công!']);
    }

    public function changePassword(Request $req)
    {
        $userId = $this->getUserId();
        $user = $this->user->getUser($userId);

        if (md5($req->oldPass) === $user->password) {
            $update = $this->user->updateUser($userId, ['password' => md5($req->newPass)]);
            if (!$update) {
                return response()->json(['error' => true, 'message' => 'Mật khẩu mới trùng với mật khẩu cũ!']);
            } else {
                return response()->json(['success' => true, 'message' => 'Đổi mật khẩu thành công!']);
            }
        } else {
            return response()->json(['error' => true, 'message' => 'Mật khẩu cũ không chính xác.'], 500);
        }
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
