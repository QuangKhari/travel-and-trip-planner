<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Support\Avatar;
use App\Support\PasswordHasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;

class AuthController extends Controller
{
    public function __construct()
    {
        // Controller gốc của dự án nạp Model/session cho web; API không cần
    }

    private function guard(): JWTGuard
    {
        /** @var JWTGuard $guard */
        $guard = auth('api');

        return $guard;
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => 'required|string|max:50',
            'password' => 'required|string|max:72',
        ]);

        $account = Account::where('username', trim($data['username']))->first();

        // Kiểm tra mật khẩu trước, rồi mới báo trạng thái tài khoản (giống đăng nhập web)
        if (!$account || !PasswordHasher::check($data['password'], $account->password)) {
            return response()->json(['message' => 'Sai tên đăng nhập hoặc mật khẩu.'], 401);
        }

        if ($account->status === 'b') {
            return response()->json(['message' => 'Tài khoản đã bị chặn.'], 403);
        }
        if ($account->status === 'd') {
            return response()->json(['message' => 'Tài khoản đã bị xóa.'], 403);
        }
        if ($account->isActive !== 'y') {
            return response()->json(['message' => 'Tài khoản chưa kích hoạt email.'], 403);
        }

        if (PasswordHasher::needsUpgrade($account->password)) {
            DB::table('tbl_users')
                ->where('userId', $account->userId)
                ->update(['password' => PasswordHasher::make($data['password'])]);
        }

        $token = $this->guard()->login($account);

        return $this->respondWithToken($token, $account);
    }

    public function refresh(): JsonResponse
    {
        try {
            $token = $this->guard()->refresh();

            /** @var Account|null $account */
            $account = $this->guard()->setToken($token)->user();
        } catch (JWTException $e) {
            return response()->json(['message' => 'Phiên đăng nhập đã hết hạn, vui lòng đăng nhập lại.'], 401);
        }

        // Không cấp token mới cho tài khoản đã bị chặn/xóa
        if (!$account || !$account->isUsable()) {
            $this->guard()->invalidate();

            return response()->json(['message' => 'Tài khoản không còn quyền truy cập.'], 403);
        }

        return $this->respondWithToken($token, $account);
    }

    public function logout(): JsonResponse
    {
        $this->guard()->logout();

        return response()->json(['message' => 'Đã đăng xuất.']);
    }

    public function me(): JsonResponse
    {
        /** @var Account $account */
        $account = $this->guard()->user();

        return response()->json(['user' => $this->userPayload($account)]);
    }

    private function respondWithToken(string $token, Account $account): JsonResponse
    {
        return response()->json([
            'access_token' => $token,
            'token_type'   => 'bearer',
            'expires_in'   => $this->guard()->factory()->getTTL() * 60,
            'user'         => $this->userPayload($account),
        ]);
    }

    private function userPayload(Account $account): array
    {
        return [
            'userId'   => (int) $account->userId,
            'username' => $account->username,
            'fullName' => $account->fullName,
            'email'    => $account->email,
            'avatar'   => Avatar::url($account->avatar),
        ];
    }
}
