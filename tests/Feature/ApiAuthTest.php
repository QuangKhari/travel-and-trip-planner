<?php

namespace Tests\Feature;

use App\Support\PasswordHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApiAuthTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $over = []): int
    {
        return DB::table('tbl_users')->insertGetId($over + [
            'fullName' => 'Khach Api',
            'username' => 'apiuser',
            'password' => PasswordHasher::make('12345678'),
            'email' => 'api@example.com',
            'isActive' => 'y',
        ]);
    }

    /** Guard JWT lưu user trong bộ nhớ; xóa để mỗi request đọc lại từ DB như thực tế */
    private function freshRequest(): void
    {
        $this->app['auth']->forgetGuards();
    }

    private function login(): string
    {
        return $this->postJson('/api/v1/auth/login', ['username' => 'apiuser', 'password' => '12345678'])
            ->assertOk()
            ->json('access_token');
    }

    private function me(string $token)
    {
        $this->freshRequest();

        return $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer $token"]);
    }

    public function test_login_returns_token_and_me_works(): void
    {
        $this->makeUser();
        $token = $this->login();

        $this->me($token)->assertOk()->assertJsonPath('user.username', 'apiuser');
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->makeUser();

        $this->postJson('/api/v1/auth/login', ['username' => 'apiuser', 'password' => 'saimatkhau'])
            ->assertStatus(401);
    }

    public function test_blocked_user_cannot_login(): void
    {
        $this->makeUser(['status' => 'b']);

        $this->postJson('/api/v1/auth/login', ['username' => 'apiuser', 'password' => '12345678'])
            ->assertStatus(403);
    }

    public function test_user_blocked_after_token_issued_is_cut_off(): void
    {
        $id = $this->makeUser();
        $token = $this->login();

        DB::table('tbl_users')->where('userId', $id)->update(['status' => 'b']);

        $this->me($token)->assertStatus(403);
    }

    public function test_logout_invalidates_token(): void
    {
        $this->makeUser();
        $token = $this->login();

        $this->freshRequest();
        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => "Bearer $token"])->assertOk();

        $this->me($token)->assertStatus(401);
    }

    public function test_missing_token_returns_json_401(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }
}
