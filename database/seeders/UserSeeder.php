<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Tài khoản mẫu cho môi trường phát triển (mật khẩu: 12345678).
 * Dùng đúng tên cột của tbl_users; chạy lại nhiều lần không lỗi (updateOrInsert theo username).
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'username'    => 'nguyenvana',
                'email'       => 'nguyenvana@gmail.com',
                'fullName'    => 'Nguyễn Văn A',
                'phoneNumber' => '0900000002',
                'address'     => 'Đà Nẵng',
            ],
            [
                'username'    => 'tranthib',
                'email'       => 'tranthib@gmail.com',
                'fullName'    => 'Trần Thị B',
                'phoneNumber' => '0900000003',
                'address'     => 'Hồ Chí Minh',
            ],
        ];

        foreach ($users as $user) {
            DB::table('tbl_users')->updateOrInsert(
                ['username' => $user['username']],
                $user + [
                    'password' => Hash::make('12345678'),   // bcrypt, khớp PasswordHasher
                    'isActive' => 'y',
                    // status để NULL = tài khoản bình thường ('b' = bị chặn, 'd' = đã xóa)
                ]
            );
        }
    }
}
