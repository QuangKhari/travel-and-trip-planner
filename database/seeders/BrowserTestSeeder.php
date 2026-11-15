<?php

namespace Database\Seeders;

use App\Support\PasswordHasher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Dữ liệu cho kiểm thử trình duyệt (tests/Browser, CI): dữ liệu mẫu chung + một tài khoản quản trị riêng.
 * php artisan migrate:fresh --seed --seeder=BrowserTestSeeder
 */
class BrowserTestSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DatabaseSeeder::class);

        DB::table('tbl_admin')->updateOrInsert(['userName' => 'e2e_admin'], [
            'passWord' => PasswordHasher::make('matkhau123'),
            'email' => 'e2e_admin@travela.test',
            'fullName' => 'Kiểm thử Quản trị',
            'address' => 'Hà Nội',
            'role' => 'admin',
        ]);
    }
}
