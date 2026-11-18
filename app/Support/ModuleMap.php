<?php

namespace App\Support;

/**
 * Quét mã nguồn để thấy phân hệ nào đọc / ghi bảng của phân hệ nào (config/modules.php). Dùng cho test kiến trúc và lệnh arch:modules.
 * Nhận diện bằng cú pháp truy vấn: DB::table('tbl_x'...) / ->join('tbl_x'...) / ->from('tbl_x') (đọc), cùng câu lệnh có ->insert / ->update /
 * ->delete / ->increment / ->decrement / ->upsert / ->updateOrInsert / ->insertOrIgnore / ->insertGetId (ghi). Câu SQL thô ít gặp nên không tính.
 */
final class ModuleMap
{
    private const WRITE = '/->\s*(insert|insertGetId|insertOrIgnore|update|updateOrInsert|upsert|delete|increment|decrement|truncate)\s*\(/';

    /** @return array<string,string> bảng -> phân hệ chủ */
    public static function owners(): array
    {
        $out = [];
        foreach ((array) config('modules.modules', []) as $m => $def) {
            foreach ((array) $def['tables'] as $t) {
                $out[$t] = $m;
            }
        }

        return $out;
    }

    /** @return list<string> tệp PHP thuộc một phân hệ */
    public static function files(string $module): array
    {
        $out = [];
        foreach ((array) config('modules.modules.' . $module . '.paths', []) as $p) {
            $abs = base_path($p);
            if (is_file($abs)) {
                $out[] = $p;
            } elseif (is_dir($abs)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if ($f->getExtension() === 'php') {
                        $out[] = ltrim(str_replace([base_path(), '\\'], ['', '/'], $f->getPathname()), '/');
                    }
                }
            }
        }
        sort($out);

        return array_values(array_unique($out));
    }

    /**
     * Mọi truy cập bảng của phân hệ khác.
     * @return list<array{module:string,file:string,table:string,owner:string,kind:string,line:int}>
     */
    public static function scan(): array
    {
        $owners = self::owners();
        $hits = [];
        foreach (array_keys((array) config('modules.modules', [])) as $m) {
            foreach (self::files($m) as $file) {
                $src = (string) file_get_contents(base_path($file));
                // tách theo câu lệnh (dấu ;) để biết truy vấn có ghi hay không
                $offset = 0;
                foreach (explode(';', $src) as $stmt) {
                    if (preg_match_all("/(?:DB::table|->join|->leftJoin|->rightJoin|->from)\\(\\s*'(tbl_[a-z_]+)/", $stmt, $mm, PREG_OFFSET_CAPTURE)) {
                        $isWrite = (bool) preg_match(self::WRITE, $stmt);
                        foreach ($mm[1] as $i => [$table, $pos]) {
                            $owner = $owners[$table] ?? null;
                            if ($owner === null || $owner === $m) {
                                continue;
                            }
                            // chỉ bảng chính của câu (DB::table đầu tiên) mới là bảng bị ghi; bảng join chỉ được đọc
                            $kind = $isWrite && $i === 0 && str_starts_with(ltrim(substr($stmt, (int) $mm[0][$i][1], 9)), 'DB::table') ? 'write' : 'read';
                            $hits[] = ['module' => $m, 'file' => $file, 'table' => $table, 'owner' => $owner, 'kind' => $kind,
                                'line' => substr_count(substr($src, 0, $offset + $pos), "\n") + 1];
                        }
                    }
                    $offset += strlen($stmt) + 1;
                }
            }
        }

        return $hits;
    }

    /** Truy cập vi phạm luật (kể cả đã có trong baseline). @return list<array> */
    public static function violations(): array
    {
        $rules = (array) config('modules.rules', []);
        $out = [];
        foreach (self::scan() as $h) {
            $rule = $rules[$h['module']][$h['owner']] ?? null;
            if ($rule === 'none' || ($rule === 'read' && $h['kind'] === 'write')) {
                $out[] = $h + ['key' => $h['file'] . '|' . $h['table'] . '|' . $h['kind'], 'rule' => $rule];
            }
        }

        return $out;
    }
}
