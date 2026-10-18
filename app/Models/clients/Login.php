<?php

namespace App\Models\clients;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;

class Login extends Model
{
    protected $table = 'tbl_users';

    public function registerAccount($data)
    {
        return DB::table($this->table)->insertGetId($data);
    }

    public function checkUserExist($username, $email)
    {
        $check = DB::table($this->table)
            ->where('username', $username)
            ->orWhere('email', $email)
            ->exists();
        return $check;
    }

    // Chỉ tìm theo username; việc so mật khẩu làm bằng PasswordHasher trong controller
    public function findByUsername($username)
    {
        return DB::table($this->table)
            ->where('username', $username)
            ->first();
    }

    /**
     * Đăng nhập bằng tên đăng nhập HOẶC email (người dùng hay quên tên đăng nhập hơn quên email).
     * Ưu tiên tên đăng nhập trước; chỉ khi không thấy và chuỗi có dạng email mới tìm theo email.
     */
    public function findByLogin($login)
    {
        $user = $this->findByUsername($login);

        if (!$user && filter_var($login, FILTER_VALIDATE_EMAIL)) {
            $user = DB::table($this->table)
                ->where('email', $login)
                ->first();
        }

        return $user;
    }

    public function checkAccountMatch($username, $email)
    {
        $check = DB::table($this->table)
            ->where('username', $username)
            ->where('email', $email)
            ->first();
        return $check;
    }

    public function updatePassword($userId, $password)
    {
        return DB::table($this->table)
            ->where('userId', $userId)
            ->update(['password' => $password]);
    }
}
