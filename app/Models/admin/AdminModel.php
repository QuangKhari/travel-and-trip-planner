<?php

namespace App\Models\admin;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;

class AdminModel extends Model
{
    use HasFactory;

    protected $table = 'tbl_admin';

    // Lấy tài khoản admin chính
    public function getAdmin()
    {
        return DB::table($this->table)->where('role', 'admin')->first();
    }

    // Lấy danh sách theo role
    public function getByRole($role)
    {
        return DB::table($this->table)->where('role', $role)->get();
    }

    // Cập nhật thông tin admin
    public function updateAdmin($data)
    {
        return DB::table($this->table)
            ->where('role', 'admin')
            ->update($data);
    }

    // Thêm tài khoản mới (manager hoặc staff)
    public function addAdmin($data)
    {
        return DB::table($this->table)->insertGetId($data);
    }

    // Xóa theo ID và role (tránh xóa nhầm)
    public function deleteByIdAndRole($adminId, $role)
    {
        return DB::table($this->table)
            ->where('adminId', $adminId)
            ->where('role', $role)
            ->delete();
    }

    // Lấy theo ID
    public function getAdminById($adminId)
    {
        return DB::table($this->table)
            ->where('adminId', $adminId)
            ->first();
    }
}