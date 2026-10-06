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
        return DB::table($this->table)->insert($data);
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
