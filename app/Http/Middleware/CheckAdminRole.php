<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class CheckAdminRole
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $adminId = session('adminId');

        if (!$adminId) {
            return $this->denyUnauthenticated($request);
        }

        // Đọc lại vai trò từ database ở MỖI request (L-A-17)
        $role = DB::table('tbl_admin')->where('adminId', $adminId)->value('role');

        // Tài khoản đã bị xóa: hủy phiên ngay
        if (!$role) {
            $request->session()->forget(['admin', 'adminRole', 'adminId']);
            return $this->denyUnauthenticated($request);
        }

        // Đồng bộ lại session để giao diện đang đọc session('adminRole') hiện đúng menu
        session(['adminRole' => $role]);

        if (!in_array($role, $roles, true)) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => 'Bạn không có quyền thực hiện thao tác này.'], 403);
            }

            return redirect()->route('admin.dashboard')
                ->with('error', 'Bạn không có quyền truy cập trang này');
        }

        return $next($request);
    }

    private function denyUnauthenticated(Request $request)
    {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['success' => false, 'message' => 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.'], 401);
        }

        return redirect()->route('admin.login');
    }
}
