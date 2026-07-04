<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('tbl_users')->insert([
            [
                'username' => 'nguyenvana',
                'email' => 'nguyenvana@gmail.com',
                'password' => Hash::make('12345678'),
                'fullname' => 'Nguyễn Văn A',
                'phone' => '0900000002',
                'address' => 'Đà Nẵng',
                'role' => 'user',
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'username' => 'tranthib',
                'email' => 'tranthib@gmail.com',
                'password' => Hash::make('12345678'),
                'fullname' => 'Trần Thị B',
                'phone' => '0900000003',
                'address' => 'Hồ Chí Minh',
                'role' => 'user',
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}

