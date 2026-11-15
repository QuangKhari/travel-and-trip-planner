<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Services\AdminTwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Bật / tắt đăng nhập hai lớp và tạo lại mã dự phòng (khối "Bảo mật đăng nhập" ở trang Tài khoản quản trị). */
class AdminTwoFactorController extends Controller
{
    private function back(string $msg, bool $ok = true)
    {
        toastr()->{$ok ? 'success' : 'error'}($msg);

        return redirect()->to(route('admin.admin') . '#bao-mat');
    }

    public function enable(Request $request)
    {
        $id = (int) session('adminId');
        if (AdminTwoFactor::enabled($id)) {
            return $this->back('Đăng nhập hai lớp đang bật.', false);
        }
        $r = AdminTwoFactor::confirm($id, (string) $request->input('code'));
        if (!$r['ok']) {
            return $this->back($r['message'], false);
        }
        session()->flash('admin2faCodes', $r['codes']);

        return $this->back($r['message'] . ' Lưu các mã dự phòng bên dưới ở nơi an toàn.');
    }

    public function disable(Request $request)
    {
        $me = (int) session('adminId');
        $target = (int) $request->input('adminId', $me);

        // Quản trị viên đặt lại cho quản lý / nhân viên bị mất điện thoại (không cần mã của người đó)
        if ($target !== $me) {
            $role = DB::table('tbl_admin')->where('adminId', $target)->value('role');
            if (session('adminRole') !== 'admin' || !$role || $role === 'admin') {
                return $this->back('Bạn không có quyền đặt lại cho tài khoản này.', false);
            }
            AdminTwoFactor::disable($target, $me);

            return $this->back('Đã đặt lại đăng nhập hai lớp. Người đó cần bật lại ở lần đăng nhập tới.');
        }
        if (!AdminTwoFactor::check($me, (string) $request->input('code'))) {
            return $this->back('Mã không đúng. Nhập mã đang hiện hoặc một mã dự phòng để tắt.', false);
        }
        if (AdminTwoFactor::requiredFor((string) session('adminRole'))) {
            return $this->back('Vai trò của bạn bắt buộc đăng nhập hai lớp. Hãy tạo lại mã dự phòng thay vì tắt.', false);
        }
        AdminTwoFactor::disable($me);

        return $this->back('Đã tắt đăng nhập hai lớp.');
    }

    public function codes(Request $request)
    {
        $me = (int) session('adminId');
        if (!AdminTwoFactor::check($me, (string) $request->input('code'))) {
            return $this->back('Mã không đúng.', false);
        }
        session()->flash('admin2faCodes', AdminTwoFactor::regenerate($me));

        return $this->back('Đã tạo bộ mã dự phòng mới; bộ cũ không dùng được nữa.');
    }
}
