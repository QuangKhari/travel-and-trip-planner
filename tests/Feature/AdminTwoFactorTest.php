<?php

namespace Tests\Feature;

use App\Services\AdminTwoFactor;
use App\Support\PasswordHasher;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Đợt C mục 18: đăng nhập hai lớp cho trang quản trị. */
class AdminTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role = 'admin', string $user = 'tfa_admin'): int
    {
        return (int) DB::table('tbl_admin')->insertGetId([
            'userName' => $user, 'passWord' => PasswordHasher::make('matkhau123'), 'email' => $user . '@travela.test',
            'fullName' => 'Người ' . $user, 'address' => 'Hà Nội', 'role' => $role,
        ]);
    }

    /** Bật 2 lớp như người dùng làm trên trang Tài khoản; trả về [secret, mã dự phòng]. */
    private function enable(int $id): array
    {
        $this->withSession(['adminId' => $id, 'adminRole' => 'admin'])->get('/admin/admin')->assertOk()->assertSee('Đăng nhập hai lớp');
        $secret = session('admin2faSetup');
        $this->assertIsString($secret);
        $this->post('/admin/bao-mat/hai-lop/bat', ['code' => Totp::code($secret, Totp::step() - 1)])->assertRedirect();
        $this->assertTrue(AdminTwoFactor::enabled($id));
        $codes = session('admin2faCodes');
        $this->assertCount(AdminTwoFactor::RECOVERY_COUNT, $codes);

        return [$secret, $codes];
    }

    public function test_totp_matches_rfc6238_vectors(): void
    {
        $s = Totp::base32Encode('12345678901234567890');
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $s);
        $this->assertSame('12345678901234567890', Totp::base32Decode($s));
        // RFC 6238 phụ lục B (SHA1, 8 chữ số) lấy 6 chữ số cuối
        foreach ([59 => '287082', 1111111109 => '081804', 1234567890 => '005924', 2000000000 => '279037'] as $t => $code) {
            $this->assertSame($code, Totp::code($s, Totp::step($t)));
        }
        $this->assertSame(Totp::step(59), Totp::verify($s, '287 082', null, 1, 59));
        $this->assertNull(Totp::verify($s, '287082', Totp::step(59), 1, 59));   // bước đã dùng: không nhận lại
        $this->assertNull(Totp::verify($s, '12345', null, 1, 59));
    }

    public function test_secret_is_stored_encrypted_and_login_needs_code(): void
    {
        $id = $this->admin();
        [$secret] = $this->enable($id);
        $stored = DB::table('tbl_admin')->where('adminId', $id)->value('totpSecret');
        $this->assertStringStartsWith('enc:v1:', $stored);
        $this->assertStringNotContainsString($secret, $stored);

        $this->flushSession();
        $this->post('/admin/login-account', ['username' => 'tfa_admin', 'password' => 'matkhau123'])->assertRedirect(route('admin.login.2fa'));
        $this->assertNull(session('adminId'));   // đúng mật khẩu vẫn chưa vào được
        $this->get('/admin/dashboard')->assertRedirect(route('admin.login'));
        $this->get('/admin/login/xac-minh')->assertOk()->assertSee('Xác minh đăng nhập');

        $this->post('/admin/login/xac-minh', ['code' => '000000'])->assertRedirect(route('admin.login.2fa'));
        $this->assertNull(session('adminId'));

        $code = Totp::code($secret, Totp::step());
        $this->post('/admin/login/xac-minh', ['code' => $code])->assertRedirect(route('admin.dashboard'));
        $this->assertSame($id, (int) session('adminId'));

        // cùng mã đó không dùng lại được cho lần đăng nhập sau
        $this->flushSession();
        $this->post('/admin/login-account', ['username' => 'tfa_admin', 'password' => 'matkhau123']);
        $this->post('/admin/login/xac-minh', ['code' => $code])->assertRedirect(route('admin.login.2fa'));
        $this->assertNull(session('adminId'));
    }

    public function test_recovery_code_works_once_and_pending_step_expires(): void
    {
        $id = $this->admin();
        [, $codes] = $this->enable($id);

        $this->flushSession();
        $this->post('/admin/login-account', ['username' => 'tfa_admin', 'password' => 'matkhau123']);
        $this->post('/admin/login/xac-minh', ['code' => strtoupper($codes[0])])->assertRedirect(route('admin.dashboard'));
        $this->assertSame(AdminTwoFactor::RECOVERY_COUNT - 1, AdminTwoFactor::recoveryLeft($id));

        $this->flushSession();
        $this->post('/admin/login-account', ['username' => 'tfa_admin', 'password' => 'matkhau123']);
        $this->post('/admin/login/xac-minh', ['code' => $codes[0]])->assertRedirect(route('admin.login.2fa'));
        $this->assertNull(session('adminId'));

        // quá 5 phút sau bước mật khẩu: phải đăng nhập lại từ đầu
        $this->withSession(['admin2fa' => ['id' => $id, 'at' => time() - AdminTwoFactor::PENDING_TTL - 1]])
            ->post('/admin/login/xac-minh', ['code' => $codes[1]])->assertRedirect(route('admin.login'));
        $this->assertNull(session('adminId'));
    }

    public function test_admin_without_2fa_logs_in_directly(): void
    {
        $id = $this->admin('staff', 'tfa_staff');
        $this->post('/admin/login-account', ['username' => 'tfa_staff', 'password' => 'matkhau123'])->assertRedirect(route('admin.dashboard'));
        $this->assertSame($id, (int) session('adminId'));
    }

    public function test_wrong_code_does_not_enable_and_disable_needs_code(): void
    {
        $id = $this->admin();
        $this->withSession(['adminId' => $id, 'adminRole' => 'admin'])->get('/admin/admin');
        $this->post('/admin/bao-mat/hai-lop/bat', ['code' => '000000']);
        $this->assertFalse(AdminTwoFactor::enabled($id));

        [$secret] = $this->enable($id);
        $this->post('/admin/bao-mat/hai-lop/tat', ['code' => '111111']);
        $this->assertTrue(AdminTwoFactor::enabled($id));
        $this->post('/admin/bao-mat/hai-lop/tat', ['code' => Totp::code($secret, Totp::step())]);
        $this->assertFalse(AdminTwoFactor::enabled($id));
        $this->assertNull(DB::table('tbl_admin')->where('adminId', $id)->value('totpSecret'));
    }

    public function test_required_role_is_sent_to_setup_and_admin_can_reset_staff(): void
    {
        config(['travela.admin_2fa_required' => 'admin']);
        $id = $this->admin();
        $this->withSession(['adminId' => $id, 'adminRole' => 'admin'])->get('/admin/dashboard')->assertRedirect(route('admin.admin') . '#bao-mat');
        $this->get('/admin/admin')->assertOk()->assertSee('Bắt buộc với vai trò của bạn');
        $this->enable($id);
        $this->get('/admin/dashboard')->assertOk();

        // nhân viên không thuộc vai trò bắt buộc: vào bình thường
        $staff = $this->admin('staff', 'tfa_staff2');
        $this->withSession(['adminId' => $staff, 'adminRole' => 'staff'])->get('/admin/dashboard')->assertOk();
        $this->enable($staff);
        $this->assertTrue(AdminTwoFactor::enabled($staff));

        // nhân viên không đặt lại cho người khác được; quản trị viên thì được
        $other = $this->admin('staff', 'tfa_staff3');
        $this->withSession(['adminId' => $staff, 'adminRole' => 'staff'])->post('/admin/bao-mat/hai-lop/tat', ['adminId' => $id]);
        $this->assertTrue(AdminTwoFactor::enabled($id));
        $this->withSession(['adminId' => $id, 'adminRole' => 'admin'])->post('/admin/bao-mat/hai-lop/tat', ['adminId' => $staff]);
        $this->assertFalse(AdminTwoFactor::enabled($staff));
        $this->assertNotNull($other);
    }
}
