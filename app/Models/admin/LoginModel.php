<?php

namespace App\Models\admin;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;

class LoginModel extends Model
{
    use HasFactory;

    protected $table = 'tbl_admin';

    // Chỉ tìm theo userName; việc so mật khẩu làm bằng PasswordHasher trong controller
    public function findByUserName($username)
    {
        return DB::table($this->table)
            ->where('userName', $username)
            ->first();
    }

    public function updatePasswordById($adminId, $hash)
    {
        return DB::table($this->table)
            ->where('adminId', $adminId)
            ->update(['passWord' => $hash]);
    }

    public function getAdmin()
    {
        return DB::table($this->table)->where('role', 'admin')->first();
    }

    public function getByRole($role)
    {
        return DB::table($this->table)->where('role', $role)->get();
    }

    public function addAdmin($data)
    {
        return DB::table($this->table)->insertGetId($data);
    }

    public function deleteByIdAndRole($adminId, $role)
    {
        return DB::table($this->table)
            ->where('adminId', $adminId)
            ->where('role', $role)
            ->delete();
    }

    public function updateAdmin($data)
    {
        return DB::table($this->table)
            ->where('role', 'admin')
            ->update($data);
    }
}
