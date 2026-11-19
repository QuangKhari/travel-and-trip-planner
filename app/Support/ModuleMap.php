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

    /** Truy cập bảng vi phạm luật (rules, deny_tables) và gọi lớp nội bộ vi phạm class_rules, kể cả đã có trong baseline. @return list<array> */
    public static function violations(): array
    {
        $rules = (array) config('modules.rules', []);
        $deny = (array) config('modules.deny_tables', []);
        $out = [];
        foreach (self::scan() as $h) {
            $rule = $rules[$h['module']][$h['owner']] ?? null;
            if (in_array($h['table'], (array) ($deny[$h['module']] ?? []), true)) {
                $rule = 'deny';
            }
            if ($rule === 'none' || $rule === 'deny' || ($rule === 'read' && $h['kind'] === 'write')) {
                $out[] = $h + ['key' => $h['file'] . '|' . $h['table'] . '|' . $h['kind'], 'rule' => $rule];
            }
        }
        foreach (self::classViolations() as $h) {
            $out[] = $h;
        }

        return $out;
    }

    /** @return array<string,string> lớp (tên đầy đủ) -> phân hệ chứa nó */
    public static function classOwners(): array
    {
        $out = [];
        foreach (array_keys((array) config('modules.modules', [])) as $m) {
            foreach (self::files($m) as $file) {
                $src = (string) file_get_contents(base_path($file));
                if (preg_match('/^namespace\s+([^;]+);/m', $src, $ns) && preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $src, $c)) {
                    $out[$ns[1] . '\\' . $c[1]] = $m;
                }
            }
        }

        return $out;
    }

    /**
     * Lớp của phân hệ khác mà mỗi tệp dùng (use, tên đầy đủ \App\..., hoặc tên ngắn cùng namespace).
     * @return list<array{module:string,file:string,class:string,owner:string,line:int}>
     */
    public static function classDeps(): array
    {
        $owners = self::classOwners();
        $hits = [];
        foreach (array_keys((array) config('modules.modules', [])) as $m) {
            foreach (self::files($m) as $file) {
                $seen = [];
                foreach (self::referencedClasses((string) file_get_contents(base_path($file))) as [$cls, $line]) {
                    $owner = $owners[$cls] ?? null;
                    if ($owner === null || $owner === $m || isset($seen[$cls])) {
                        continue;
                    }
                    $seen[$cls] = true;
                    $hits[] = ['module' => $m, 'file' => $file, 'class' => $cls, 'owner' => $owner, 'line' => $line];
                }
            }
        }

        return $hits;
    }

    /** Lớp nội bộ (ngoài app/Modules) của phân hệ mà class_rules cấm gọi trực tiếp. @return list<array> */
    public static function classViolations(): array
    {
        $rules = (array) config('modules.class_rules', []);
        $out = [];
        foreach (self::classDeps() as $h) {
            if (in_array($h['owner'], (array) ($rules[$h['module']] ?? []), true) && !str_starts_with($h['class'], 'App\\Modules\\' . $h['owner'] . '\\')) {
                $out[] = $h + ['key' => $h['file'] . '|class|' . $h['class'], 'rule' => 'class', 'table' => $h['class'], 'kind' => 'class'];
            }
        }

        return $out;
    }

    /** @return list<array{0:string,1:int}> tên lớp đầy đủ + dòng đầu tiên xuất hiện */
    private static function referencedClasses(string $src): array
    {
        $ns = '';
        $imports = [];
        $out = [];
        $tokens = token_get_all($src);
        $n = count($tokens);
        $depth = 0;
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t)) {
                $depth += $t === '{' ? 1 : ($t === '}' ? -1 : 0);
                continue;
            }
            if ($t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            }
            if ($t[0] === T_NAMESPACE) {
                for ($j = $i + 1; $j < $n && $tokens[$j] !== ';' && $tokens[$j] !== '{'; $j++) {
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_NAME_QUALIFIED, T_STRING], true)) {
                        $ns = $tokens[$j][1];
                    }
                }
            } elseif ($t[0] === T_USE && $depth === 0) {
                // use A\B\C; / use A\B\C as D;
                $name = null;
                $alias = null;
                for ($j = $i + 1; $j < $n && $tokens[$j] !== ';'; $j++) {
                    if (!is_array($tokens[$j])) {
                        continue;
                    }
                    if (in_array($tokens[$j][0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                        $name = ltrim($tokens[$j][1], '\\');
                    } elseif ($tokens[$j][0] === T_STRING && $name !== null) {
                        $alias = $tokens[$j][1];
                    }
                }
                if ($name !== null) {
                    $imports[$alias ?? substr($name, (int) strrpos($name, '\\') + 1)] = $name;
                    $out[] = [$name, $t[2]];
                }
                $i = $j;
            } elseif ($t[0] === T_NAME_FULLY_QUALIFIED) {
                $out[] = [ltrim($t[1], '\\'), $t[2]];
            } elseif ($t[0] === T_NAME_QUALIFIED) {
                $first = strstr($t[1], '\\', true);
                $out[] = [isset($imports[$first]) ? $imports[$first] . substr($t[1], strlen($first)) : ($ns ? $ns . '\\' : '') . $t[1], $t[2]];
            } elseif ($t[0] === T_STRING && $ns !== '') {
                // tên ngắn: chỉ quan tâm lớp cùng namespace (lớp của namespace khác đã được bắt qua use)
                $prev = $tokens[$i - 1] ?? null;
                if (!isset($imports[$t[1]]) && !(is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST], true))) {
                    $out[] = [$ns . '\\' . $t[1], $t[2]];
                }
            }
        }

        return $out;
    }
}
