<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckLoggedInClients
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->session()->has('userId')) {
            // Request AJAX/fetch: trả JSON 401 thay vì redirect (redirect làm JS hiểu nhầm)
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vui lòng đăng nhập để thực hiện.',
                    'redirectUrl' => route('login'),
                ], 401);
            }

            toastr()->error('Vui lòng đăng nhập để thực hiện.');
            return redirect()->route('login');
        }

        return $next($request);
    }
}
