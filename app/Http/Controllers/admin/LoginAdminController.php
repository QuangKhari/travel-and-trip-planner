<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\admin\LoginModel;
use App\Services\AdminTwoFactor;
use App\Support\AuditLog;
use App\Support\PasswordHasher;
use Illuminate\Support\Facades\Validator;

class LoginAdminController extends Controller
{
    private $login;

    public function __construct()
    {
        parent::__construct();
        $this->login = new LoginModel();
    }
    public function index()
    {
        $title = 'Đăng nhập';

        return view('admin.login', compact('title'));
    }

    public function loginAdmin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string|max:50',
            'password' => 'required|string|max:72',
        ]);

        if ($validator->fails()) {
            toastr()->error('Vui lòng nhập đầy đủ thông tin đăng nhập.');
            return redirect()->route('admin.login');
        }

        $username = trim((string) $request->username);
        $password = (string) $request->password;

        $admin = $this->login->findByUserName($username);

        // Tên cột là passWord; đọc cả hai cách viết để không phụ thuộc hoa/thường
        $stored = $admin ? ($admin->passWord ?? $admin->password ?? null) : null;

        if ($admin && PasswordHasher::check($password, $stored)) {
            if (PasswordHasher::needsUpgrade($stored)) {
                $this->login->updatePasswordById($admin->adminId, PasswordHasher::make($password));
            }

            $request->session()->regenerate();
            // Đã bật đăng nhập hai lớp: đúng mật khẩu mới qua bước 1, chưa vào trang quản trị
            if (AdminTwoFactor::enabled((int) $admin->adminId)) {
                $request->session()->put('admin2fa', ['id' => (int) $admin->adminId, 'at' => time()]);
                return redirect()->route('admin.login.2fa');
            }

            return $this->finish($request, $admin);
        }

        toastr()->error('Thông tin đăng nhập không chính xác');
        return redirect()->route('admin.login');
    }

    /** Bước 2: nhập mã 6 số từ ứng dụng xác thực hoặc một mã dự phòng. */
    public function twoFactorForm(Request $request)
    {
        if (!$this->pending($request)) {
            return redirect()->route('admin.login');
        }
        $title = 'Xác minh đăng nhập';
        $step = '2fa';

        return view('admin.login', compact('title', 'step'));
    }

    public function twoFactorVerify(Request $request)
    {
        $id = $this->pending($request);
        if (!$id) {
            toastr()->error('Phiên xác minh đã hết hạn. Vui lòng đăng nhập lại.');
            return redirect()->route('admin.login');
        }
        $code = mb_substr(trim((string) $request->input('code')), 0, 20);
        $kind = $code !== '' ? AdminTwoFactor::check($id, $code) : null;
        if (!$kind) {
            AuditLog::record('admin.2fa_fail', 'admin', $id, 'Nhập sai mã đăng nhập hai lớp');
            toastr()->error('Mã không đúng hoặc đã dùng. Nhập mã đang hiện trên ứng dụng xác thực.');
            return redirect()->route('admin.login.2fa');
        }
        $admin = $this->login->findById($id);
        if (!$admin) {
            return redirect()->route('admin.login');
        }
        $request->session()->forget('admin2fa');
        $request->session()->regenerate();
        if ($kind === 'recovery') {
            $left = AdminTwoFactor::recoveryLeft($id);
            session()->flash('admin2faNotice', 'Bạn vừa dùng một mã dự phòng, còn ' . $left . ' mã. Tạo bộ mới trong Tài khoản nếu sắp hết.');
        }

        return $this->finish($request, $admin);
    }

    private function pending(Request $request): ?int
    {
        $p = $request->session()->get('admin2fa');
        if (!is_array($p) || empty($p['id']) || time() - (int) ($p['at'] ?? 0) > AdminTwoFactor::PENDING_TTL) {
            $request->session()->forget('admin2fa');
            return null;
        }

        return (int) $p['id'];
    }

    private function finish(Request $request, object $admin)
    {
        $request->session()->put('admin', $admin->userName);
        $request->session()->put('adminRole', $admin->role);
        $request->session()->put('adminId', $admin->adminId);
        toastr()->success('Đăng nhập thành công');

        return redirect()->route('admin.dashboard');
    }

    public function logout(Request $request)
    {
        $request->session()->forget(['admin', 'adminRole', 'adminId', 'admin2fa', 'admin2faSetup']);
        $request->session()->regenerate();
        $request->session()->regenerateToken();
        toastr()->success("Đăng xuất thành công!");
        return redirect()->route('admin.login');
    }
}
