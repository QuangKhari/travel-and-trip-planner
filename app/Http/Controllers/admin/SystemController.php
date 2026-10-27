<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Services\Content\PublicCache;
use App\Support\AdminBadges;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Bảo trì hệ thống: xem nhanh cấu hình có an toàn không + các nút xóa cache.
 * Chỉ admin/manager (route nằm trong nhóm checkAdminRole:admin,manager).
 */
class SystemController extends Controller
{
    public function index()
    {
        $checks = [];
        $add = function (string $name, string $state, string $value, string $hint = '') use (&$checks) {
            $checks[] = compact('name', 'state', 'value', 'hint');   // state: ok | warn | bad
        };

        $prod = app()->environment('production');
        $add('Môi trường (APP_ENV)', 'ok', (string) app()->environment());
        $add('Chế độ gỡ lỗi (APP_DEBUG)', config('app.debug') ? ($prod ? 'bad' : 'warn') : 'ok', config('app.debug') ? 'Bật' : 'Tắt',
            config('app.debug') && $prod ? 'Khi chạy thật phải đặt APP_DEBUG=false để không lộ lỗi và khóa.' : '');
        $add('PHP / Laravel', 'ok', PHP_VERSION . ' / ' . app()->version());

        try {
            DB::select('select 1');
            $add('Kết nối cơ sở dữ liệu', 'ok', DB::connection()->getDatabaseName());
        } catch (\Throwable $e) {
            $add('Kết nối cơ sở dữ liệu', 'bad', 'Lỗi', $e->getMessage());
        }

        $link = public_path('storage');
        $add('Liên kết ảnh (storage:link)', is_link($link) || is_dir($link) ? 'ok' : 'bad', is_link($link) || is_dir($link) ? 'Có' : 'Thiếu',
            is_link($link) || is_dir($link) ? '' : 'Chạy: php artisan storage:link — nếu thiếu, ảnh upload không hiện ra.');

        try {
            $root = Storage::disk(config('media.disk', 'public'))->path('');
            $free = @disk_free_space($root);
            $add('Thư mục ảnh ghi được', is_writable($root) ? 'ok' : 'bad', is_writable($root) ? 'Ghi được' : 'Không ghi được', $root);
            if ($free !== false) {
                $add('Dung lượng trống', $free < 1073741824 ? 'warn' : 'ok', number_format($free / 1073741824, 1) . ' GB');
            }
        } catch (\Throwable $e) {
            $add('Thư mục ảnh', 'warn', 'Không kiểm tra được (disk ' . config('media.disk', 'public') . ')');
        }

        $add('Cache', config('cache.default') === 'array' ? 'warn' : 'ok', (string) config('cache.default'));
        $add('Hàng đợi (queue)', 'ok', (string) config('queue.default'), 'Hủy đơn quá hạn giữ chỗ cần chạy: php artisan schedule:work');
        $add('Gửi mail', config('mail.default') === 'log' ? 'warn' : 'ok', (string) config('mail.default'),
            config('mail.default') === 'log' ? 'Mail chỉ ghi vào log, khách sẽ không nhận được email.' : '');

        $ragDirty = (bool) Cache::get('rag.dirty');
        $add('Chỉ mục Hỏi đáp (RAG)', $ragDirty ? 'warn' : 'ok', $ragDirty ? 'Cần dựng lại' : 'Mới', $ragDirty ? 'Nội dung vừa đổi; bấm "Dựng lại chỉ mục" bên dưới.' : '');

        return view('admin.system', ['title' => 'Bảo trì hệ thống', 'checks' => $checks, 'ragDirty' => $ragDirty]);
    }

    /** Xóa cache nội dung công khai (trang chủ, bưu ảnh, sitemap, tìm kiếm) + số liệu menu admin. */
    public function flushContent()
    {
        PublicCache::flush(false);
        AdminBadges::forget();

        return back()->with('success', 'Đã xóa cache nội dung công khai. Trang chủ và sitemap sẽ được dựng lại ở lần truy cập tới.');
    }

    /** Xóa view đã biên dịch (dùng sau khi sửa giao diện mà trang không đổi). */
    public function clearViews()
    {
        try {
            Artisan::call('view:clear');
        } catch (\Throwable $e) {
            return back()->with('error', 'Không xóa được: ' . $e->getMessage());
        }

        return back()->with('success', 'Đã xóa view đã biên dịch.');
    }
}
