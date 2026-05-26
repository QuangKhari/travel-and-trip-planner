<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\DB;

class CheckUserBlocked
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {

        if (!$request->session()->has('username')) {

            return redirect()->route('login');
        }

        $username = $request->session()->get('username');

        $user = DB::table('tbl_users')
            ->where('username', $username)
            ->first();

        // Kiểm tra bị chặn
        if ($user && $user->status == 'b') {

            $request->session()->flush();

            toastr()->error('Tài khoản đã bị chặn');

            return redirect()->route('login');
        }

        return $next($request);
    }
}
