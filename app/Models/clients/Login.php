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
    public function login($account){
        $getUser = DB::table($this->table)
        ->where('username', $account['username'])
        ->where('password', $account['password'])
        ->first();
        return $getUser;

    }

}
