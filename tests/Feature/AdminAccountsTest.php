<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Tài khoản quản trị: sửa hồ sơ, thêm / xóa quản lý (chỉ admin), thêm / xóa nhân viên; mật khẩu luôn băm, xóa đúng vai trò. */
class AdminAccountsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role, string $name): int
    {
        return DB::table('tbl_admin')->insertGetId(['userName' => $name, 'passWord' => 'x', 'email' => $name . '@example.com', 'fullName' => ucfirst($name), 'address' => 'HN', 'role' => $role]);
    }

    private function as(int $id): static
    {
        return $this->withSession(['adminId' => $id]);
    }

    public function test_profile_update_and_password_is_hashed(): void
    {
        $a = $this->admin('admin', 'boss');
        $other = $this->admin('staff', 'nv');
        $this->as($a)->get('/admin/admin')->assertOk();

        $this->as($a)->postJson('/admin/update-admin', ['fullName' => 'Sếp', 'email' => 'nv@example.com', 'address' => 'HN'])->assertJson(['success' => false]);
        $this->as($a)->postJson('/admin/update-admin', ['fullName' => 'Sếp', 'email' => 'sep@example.com', 'address' => 'Huế', 'password' => 'Mat-khau-moi'])
            ->assertJson(['success' => true, 'data' => ['fullName' => 'Sếp']]);
        $row = DB::table('tbl_admin')->where('adminId', $a)->first();
        $this->assertSame('sep@example.com', $row->email);
        $this->assertTrue(Hash::check('Mat-khau-moi', $row->passWord));
        $this->as($a)->postJson('/admin/update-admin', ['fullName' => 'Sếp', 'email' => 'sai', 'address' => 'Huế'])->assertStatus(422);
        $this->assertTrue(DB::table('tbl_audit_log')->exists());
        unset($other);
    }

    public function test_only_admin_manages_managers_and_delete_respects_role(): void
    {
        $a = $this->admin('admin', 'boss');
        $m = $this->admin('manager', 'ql');
        $form = ['userName' => 'ql2', 'password' => '12345678', 'email' => 'ql2@example.com', 'fullName' => 'QL 2', 'address' => 'HN'];

        $this->as($m)->post('/admin/add-manager', $form)->assertRedirect();
        $this->assertFalse(DB::table('tbl_admin')->where('userName', 'ql2')->exists(), 'quản lý không tự thêm quản lý');

        $this->as($a)->post('/admin/add-manager', $form)->assertRedirect()->assertSessionHas('success');
        $new = DB::table('tbl_admin')->where('userName', 'ql2')->first();
        $this->assertSame('manager', $new->role);
        $this->assertNotSame('12345678', $new->passWord);

        // xóa "quản lý" nhưng đưa id của admin: không xóa được
        $this->as($a)->postJson('/admin/delete-manager', ['adminId' => $a], ['X-Requested-With' => 'XMLHttpRequest'])->assertJson(['success' => false]);
        $this->as($a)->postJson('/admin/delete-manager', ['adminId' => $new->adminId], ['X-Requested-With' => 'XMLHttpRequest'])->assertJson(['success' => true]);
        $this->assertFalse(DB::table('tbl_admin')->where('adminId', $new->adminId)->exists());
    }

    public function test_staff_add_and_delete(): void
    {
        $a = $this->admin('admin', 'boss');
        $this->as($a)->post('/admin/add-staff', ['userName' => 'nv1', 'password' => '12345678', 'email' => 'nv1@example.com', 'fullName' => 'NV', 'address' => 'HN'])->assertRedirect();
        $s = DB::table('tbl_admin')->where('userName', 'nv1')->first();
        $this->assertSame('staff', $s->role);
        $this->as($a)->post('/admin/add-staff', ['userName' => 'nv2', 'password' => 'ngan', 'email' => 'nv2@example.com', 'fullName' => 'NV', 'address' => 'HN'])->assertSessionHasErrors('password');

        $this->as($a)->postJson('/admin/delete-staff', ['adminId' => $s->adminId], ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        $this->assertFalse(DB::table('tbl_admin')->where('adminId', $s->adminId)->exists());
    }
}
