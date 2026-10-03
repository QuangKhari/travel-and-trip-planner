<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\clients\User;
use App\Services\InvalidImageException;
use App\Services\UserMediaService;
use App\Support\Avatar;


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
    public function update(Request $req){
        $fullName = $req->fullName;
        $address = $req->address;
        $email = $req->email;
        $phone = $req->phone;
        $username =session()->get('username');
        $userId = $this->user->getUserId($username);

        $dataUpdate = [
            'fullName' => $fullName,
            'address' => $address,
            'email' => $email,
            'phoneNumber' => $phone
        ];
        $userId = $this->getUserId();
        $update = $this->user->updateUser($userId, $dataUpdate);
        if (!$update) {
                return response()->json(['error' => true, 'message' => 'Mật khẩu mới trùng với mật khẩu cũ!']);
        }
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
