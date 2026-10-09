<?php

namespace App\Console\Commands;

use App\Support\PasswordHasher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature = 'admin:create
        {--username= : Tên đăng nhập}
        {--reset : Đặt lại mật khẩu nếu tài khoản đã tồn tại}';

    protected $description = 'Tạo tài khoản admin đầu tiên (hoặc đặt lại mật khẩu), mật khẩu nhập lúc chạy, không lưu trong mã';

    public function handle(): int
    {
        $username = trim((string) ($this->option('username') ?: $this->ask('Tên đăng nhập')));

        if ($username === '' || mb_strlen($username) > 50) {
            $this->error('Tên đăng nhập bắt buộc, tối đa 50 ký tự.');
            return self::FAILURE;
        }

        $existing = DB::table('tbl_admin')->where('userName', $username)->first();

        if ($existing && !$this->option('reset')) {
            $this->error("Tài khoản '{$username}' đã tồn tại. Thêm --reset để đặt lại mật khẩu.");
            return self::FAILURE;
        }

        if (!$existing && $this->option('reset')) {
            $this->error("Không có tài khoản '{$username}' để đặt lại.");
            return self::FAILURE;
        }

        $password = (string) $this->secret('Mật khẩu (8–72 ký tự)');
        $confirm  = (string) $this->secret('Nhập lại mật khẩu');

        if ($password !== $confirm) {
            $this->error('Hai lần nhập mật khẩu không khớp.');
            return self::FAILURE;
        }
        if (mb_strlen($password) < 8 || mb_strlen($password) > 72) {
            $this->error('Mật khẩu phải từ 8 đến 72 ký tự.');
            return self::FAILURE;
        }

        if ($existing) {
            DB::table('tbl_admin')->where('adminId', $existing->adminId)
                ->update(['passWord' => PasswordHasher::make($password)]);
            $this->info("Đã đặt lại mật khẩu cho '{$username}'.");
            return self::SUCCESS;
        }

        $email   = trim((string) $this->ask('Email'));
        $name    = trim((string) $this->ask('Họ tên'));
        $address = trim((string) $this->ask('Địa chỉ'));

        $validator = Validator::make(
            ['email' => $email, 'fullName' => $name, 'address' => $address],
            [
                'email'    => 'required|email|max:50|unique:tbl_admin,email',
                'fullName' => 'required|string|max:50',
                'address'  => 'required|string|max:255',
            ]
        );

        if ($validator->fails()) {
            $this->error($validator->errors()->first());
            return self::FAILURE;
        }

        DB::table('tbl_admin')->insert([
            'userName' => $username,
            'passWord' => PasswordHasher::make($password),
            'email'    => $email,
            'fullName' => $name,
            'address'  => $address,
            'role'     => 'admin',
        ]);

        $this->info("Đã tạo admin '{$username}'. Đăng nhập tại /admin/login.");

        return self::SUCCESS;
    }
}
