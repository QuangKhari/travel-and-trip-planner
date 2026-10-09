<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

/**
 * Tài khoản khách (tbl_users) dùng cho guard JWT 'api'.
 * Không dùng để ghi dữ liệu: các thao tác ghi vẫn đi qua các Model/Service hiện có.
 */
class Account extends Authenticatable implements JWTSubject
{
    protected $table = 'tbl_users';

    protected $primaryKey = 'userId';

    public $timestamps = false;

    protected $hidden = ['password'];

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [];
    }

    /** Tài khoản còn quyền dùng API: đã kích hoạt và không bị chặn/xóa */
    public function isUsable(): bool
    {
        return $this->isActive === 'y' && !in_array($this->status, ['b', 'd'], true);
    }
}
