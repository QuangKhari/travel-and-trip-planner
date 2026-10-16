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

            // UX: nhớ trang đang định vào để đăng nhập xong quay lại đúng chỗ (xem LoginController::login)
            if ($request->isMethod('GET')) {
                $request->session()->put('url.intended', $request->fullUrl());
            } elseif ($request->routeIs('booking') && $request->route('id')) {
                // Bấm "Đặt ngay" ở trang chi tiết tour là POST: lưu lại link GET kèm số khách
                $request->session()->put('url.intended', route('booking', array_filter([
                    'id'       => (int) $request->route('id'),
                    'adults'   => (int) $request->input('adults', 1),
                    'children' => (int) $request->input('children', 0),
                ], fn($v) => $v !== 0)));
            }

            toastr()->error('Vui lòng đăng nhập để thực hiện.');
            return redirect()->route('login');
        }

        return $next($request);
    }
}
