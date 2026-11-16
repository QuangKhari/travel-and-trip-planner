<?php

namespace App\Console\Commands;

use App\Support\AssetBundle;
use Illuminate\Console\Command;

/**
 * php artisan assets:bundle : gộp các nhóm CSS / JS của config/assets.php (đúng thứ tự), sửa đường dẫn url(...) trong CSS
 * cho đúng vị trí mới, đặt tên theo mã băm nội dung (trình duyệt lưu đệm lâu được), ghi manifest.json. Xóa bản gộp cũ.
 */
class AssetsBundle extends Command
{
    protected $signature = 'assets:bundle';

    protected $description = 'Gộp CSS/JS dùng chung của trang khách thành ít tệp (giữ đúng thứ tự)';

    public function handle(): int
    {
        $dirRel = trim((string) config('assets.dir', 'clients/assets/build'), '/');
        $dir = public_path($dirRel);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->error('Không tạo được thư mục ' . $dir);

            return self::FAILURE;
        }
        $manifest = [];
        foreach ((array) config('assets.bundles') as $name => $files) {
            $isCss = str_ends_with($name, '.css');
            $parts = [];
            $before = 0;
            foreach ($files as $f) {
                $path = public_path($f);
                if (!is_file($path)) {
                    $this->error("Thiếu tệp $f (nhóm $name)");

                    return self::FAILURE;
                }
                // CRLF -> LF: máy Windows và CI (git eol=lf) cho ra cùng một bản gộp, cùng tên băm
                $src = str_replace("\r\n", "\n", (string) file_get_contents($path));
                $before += strlen($src);
                $parts[] = '/* ' . $f . " */\n" . ($isCss ? self::minifyCss(self::rebase(self::stripCharset($src), dirname($f), $dirRel)) : rtrim($src) . "\n;");
            }
            $out = ($isCss ? "@charset \"UTF-8\";\n" : '') . implode("\n", $parts) . "\n";
            $hash = substr(md5($out), 0, 10);
            $file = $dirRel . '/' . preg_replace('/\.(css|js)$/', '.' . $hash . '.$1', $name);
            foreach (glob($dir . '/' . preg_replace('/\.(css|js)$/', '.*.$1', $name)) ?: [] as $old) {
                if (realpath($old) !== realpath(public_path($file))) {
                    @unlink($old);
                }
            }
            file_put_contents(public_path($file), $out);
            $manifest[$name] = ['file' => $file, 'built' => time(), 'files' => count($files), 'src' => AssetBundle::sourceHash($files)];
            $this->line(sprintf('  %-12s %2d tệp -> %s (%d KB)', $name, count($files), basename($file), (int) round(strlen($out) / 1024)));
        }
        file_put_contents(AssetBundle::manifestPath(), json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        AssetBundle::reset();
        $this->info('Xong. Sửa CSS/JS trong danh sách thì chạy lại lệnh này.');

        return self::SUCCESS;
    }

    /**
     * Thu gọn CSS an toàn: bỏ chú thích, gộp khoảng trắng, bỏ khoảng trắng quanh { } ; ,
     * Không đụng tới nội dung chuỗi "..." / '...', không bỏ khoảng trắng quanh : > + ~ (selector con cháu, calc()).
     * Chú thích nằm sát hai token (.a/ * x * /.b) không bị biến thành khoảng trắng nên không đổi nghĩa selector. @internal
     */
    public static function minifyCss(string $css): string
    {
        $out = '';
        $n = strlen($css);
        $space = false;
        for ($i = 0; $i < $n; $i++) {
            $c = $css[$i];
            if ($c === '/' && ($css[$i + 1] ?? '') === '*') {
                $end = strpos($css, '*/', $i + 2);
                $i = $end === false ? $n : $end + 1;
                continue;
            }
            if ($c === ' ' || $c === "\n" || $c === "\r" || $c === "\t" || $c === "\f") {
                $space = true;
                continue;
            }
            if ($space) {
                $last = substr($out, -1);
                if ($out !== '' && !str_contains('{};,', $last) && !str_contains('{};,', $c)) {
                    $out .= ' ';
                }
                $space = false;
            }
            if ($c === '"' || $c === "'") {
                $j = $i + 1;
                while ($j < $n && $css[$j] !== $c) {
                    $j += $css[$j] === '\\' ? 2 : 1;
                }
                $out .= substr($css, $i, $j - $i + 1);
                $i = $j;
                continue;
            }
            $out .= $c;
        }

        return $out;
    }

    private static function stripCharset(string $css): string
    {
        return preg_replace('/^\xEF\xBB\xBF?|@charset\s+"[^"]*"\s*;/i', '', $css) ?? $css;
    }

    /** url(...) tương đối trong tệp ở $fromDir -> tương đối với thư mục bản gộp $toDir (cả hai tính từ public/). @internal */
    public static function rebase(string $css, string $fromDir, string $toDir): string
    {
        return preg_replace_callback('/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', function ($m) use ($fromDir, $toDir) {
            $u = trim($m[2]);
            if ($u === '' || preg_match('#^(data:|https?:|//|/|\#)#i', $u)) {
                return $m[0];
            }
            $suffix = '';
            if (preg_match('/^([^?#]*)([?#].*)$/', $u, $x)) {
                [$u, $suffix] = [$x[1], $x[2]];
            }
            $abs = self::normalize($fromDir . '/' . $u);

            return 'url(' . $m[1] . self::relative($toDir, $abs) . $suffix . $m[1] . ')';
        }, $css) ?? $css;
    }

    private static function normalize(string $p): string
    {
        $out = [];
        foreach (explode('/', str_replace('\\', '/', $p)) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($out);
            } else {
                $out[] = $seg;
            }
        }

        return implode('/', $out);
    }

    private static function relative(string $fromDir, string $toFile): string
    {
        $a = explode('/', self::normalize($fromDir));
        $b = explode('/', $toFile);
        while ($a && $b && $a[0] === $b[0]) {
            array_shift($a);
            array_shift($b);
        }

        return str_repeat('../', count($a)) . implode('/', $b);
    }
}
