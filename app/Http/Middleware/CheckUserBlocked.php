<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CheckUserBlocked
{
    public function handle(Request $request, Closure $next)
    {
        $userId = $request->session()->get('userId');

        if (!$userId) {
            return redirect()->route('login');
        }

        $user = DB::table('tbl_users')->where('userId', $userId)->first();

        // Không còn tồn tại hoặc bị chặn -> hủy phiên (L-A-07)
        if (!$user || $user->status == 'b') {
            $request->session()->forget(['username', 'userId']);

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tài khoản đã bị chặn',
                    'redirectUrl' => route('login'),
                ], 403);
            }

            toastr()->error('Tài khoản đã bị chặn');
            return redirect()->route('login');
        }

        return $next($request);
    }
}