<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckAdminRole
{
    
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $adminRole = session('adminRole');

        if (!$adminRole) {
            return redirect()->route('admin.login');
        }

        if (!in_array($adminRole, $roles)) {
            return redirect()->route('admin.dashboard')
                ->with('error', 'Bạn không có quyền truy cập trang này');
        }

        return $next($request);
    }
}
