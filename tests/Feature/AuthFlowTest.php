<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Đăng ký, kích hoạt, đăng nhập, đăng xuất của khách hàng: các nhánh lỗi không lộ thông tin, nâng cấp MD5, chống open-redirect. */
class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $over = []): int
    {
        return DB::table('tbl_users')->insertGetId($over + ['username' => 'lan', 'email' => 'lan@example.com', 'password' => Hash::make('Mat-khau-1'),
            'fullName' => 'Lan', 'isActive' => 'y']);
    }

    private function login(string $u, string $p)
    {
        return $this->postJson('/login', ['username' => $u, 'password' => $p]);
    }

    public function test_pages_render(): void
    {
        $this->get('/login')->assertOk();
        $this->get('/register')->assertOk();
    }

    public function test_register_validates_creates_inactive_account_and_queues_activation_mail(): void
    {
        Mail::fake();
        $this->postJson('/register', ['username_regis' => 'minh', 'email' => 'minh@', 'password_regis' => '12345678'])
            ->assertOk()->assertJson(['success' => false]);
        $this->postJson('/register', ['username_regis' => 'minh', 'email' => 'minh@example.com', 'password_regis' => 'ngan'])
            ->assertJson(['success' => false, 'message' => 'Mật khẩu phải có ít nhất 8 ký tự.']);

        $this->postJson('/register', ['username_regis' => 'minh', 'email' => 'minh@example.com', 'password_regis' => '12345678'])
            ->assertOk()->assertJson(['success' => true]);
        $u = DB::table('tbl_users')->where('username', 'minh')->first();
        $this->assertNotNull($u);
        $this->assertNotSame('12345678', $u->password);
        $this->assertTrue(Hash::check('12345678', $u->password));
        $this->assertTrue(DB::table('tbl_email_verification')->where('userId', $u->userId)->exists());
        Mail::assertQueuedCount(1);

        // trùng tài khoản hoặc email
        $this->postJson('/register', ['username_regis' => 'minh', 'email' => 'khac@example.com', 'password_regis' => '12345678'])->assertJson(['success' => false]);
        $this->postJson('/register', ['username_regis' => 'khac', 'email' => 'minh@example.com', 'password_regis' => '12345678'])->assertJson(['success' => false]);

        // chưa kích hoạt thì chưa đăng nhập được
        $this->login('minh', '12345678')->assertJson(['success' => false, 'message' => 'Tài khoản chưa được kích hoạt.']);
    }

    public function test_login_branches_do_not_reveal_whether_account_exists(): void
    {
        $this->user();
        $this->login('', '')->assertJson(['success' => false]);
        $wrong = $this->login('lan', 'sai')->json('message');
        $none = $this->login('khong-co', 'sai')->json('message');
        $this->assertSame($wrong, $none);

        DB::table('tbl_users')->where('username', 'lan')->update(['status' => 'd']);
        $this->login('lan', 'Mat-khau-1')->assertJson(['success' => false, 'message' => $wrong]);
        DB::table('tbl_users')->where('username', 'lan')->update(['status' => 'b']);
        $this->login('lan', 'Mat-khau-1')->assertJson(['success' => false, 'message' => 'Tài khoản đã bị chặn']);
        $this->assertNull(session('userId'));
    }

    public function test_login_by_username_or_email_sets_session_and_logout_clears_it(): void
    {
        $id = $this->user();
        $this->login('lan@example.com', 'Mat-khau-1')->assertJson(['success' => true, 'redirectUrl' => route('home')]);
        $this->assertSame($id, (int) session('userId'));

        $this->post('/logout')->assertRedirect(route('home'));
        $this->assertNull(session('userId'));
        $this->assertNull(session('username'));
    }

    public function test_legacy_md5_password_is_upgraded_on_login(): void
    {
        $id = $this->user(['password' => md5('cu-123456')]);
        $this->login('lan', 'cu-123456')->assertJson(['success' => true]);
        $stored = DB::table('tbl_users')->where('userId', $id)->value('password');
        $this->assertStringStartsWith('$2y$', $stored);
        $this->assertTrue(Hash::check('cu-123456', $stored));
    }

    public function test_redirect_after_login_only_goes_to_same_site_pages(): void
    {
        $this->user();
        $this->withSession(['url.intended' => url('/tour-da-dat')])->postJson('/login', ['username' => 'lan', 'password' => 'Mat-khau-1'])
            ->assertJson(['redirectUrl' => url('/tour-da-dat')]);
        $this->post('/logout');
        $this->withSession(['url.intended' => 'https://evil.example.com/x'])->postJson('/login', ['username' => 'lan', 'password' => 'Mat-khau-1'])
            ->assertJson(['redirectUrl' => route('home')]);
        $this->post('/logout');
        $this->withSession(['url.intended' => url('/login')])->postJson('/login', ['username' => 'lan', 'password' => 'Mat-khau-1'])
            ->assertJson(['redirectUrl' => route('home')]);
    }
}
