<?php

/**
 * N-07: độ phủ dòng của tầng service (app/Services) từ báo cáo Clover của PHPUnit; mã thoát 1 nếu dưới ngưỡng.
 *
 *   php -d pcov.enabled=1 -d pcov.directory=app vendor/bin/phpunit --coverage-clover storage/coverage.xml
 *   php tools/coverage/services.php storage/coverage.xml [--min=60] [--top=15]
 *
 * (Xdebug: thay "-d pcov.enabled=1 ..." bằng "-d xdebug.mode=coverage".)
 */
$file = $argv[1] ?? 'storage/coverage.xml';
$min = 60.0;
$top = 15;
foreach ($argv as $a) {
    if (str_starts_with($a, '--min=')) {
        $min = (float) substr($a, 6);
    }
    if (str_starts_with($a, '--top=')) {
        $top = (int) substr($a, 6);
    }
}
if (!is_file($file)) {
    fwrite(STDERR, "Không thấy $file. Chạy PHPUnit với --coverage-clover trước.\n");
    exit(2);
}
$xml = simplexml_load_file($file);
$tot = $cov = 0;
$rows = [];
$groups = [];
foreach ($xml->xpath('//file') as $f) {
    $name = str_replace('\\', '/', (string) $f['name']);
    $pos = strpos($name, '/app/Services/');
    if ($pos === false) {
        continue;
    }
    $m = $f->metrics;
    $s = (int) $m['statements'];
    $c = (int) $m['coveredstatements'];
    $tot += $s;
    $cov += $c;
    $rel = substr($name, $pos + 1);
    $rows[] = [$s - $c, $s, $c, $rel];
    $g = explode('/', $rel)[2] ?? '';
    $g = str_ends_with($g, '.php') ? '(gốc)' : $g;
    $groups[$g] = [($groups[$g][0] ?? 0) + $s, ($groups[$g][1] ?? 0) + $c];
}
$pct = $tot ? $cov / $tot * 100 : 0;
printf("Độ phủ dòng app/Services: %d / %d = %.2f%% (ngưỡng N-07: %.0f%%)\n\n", $cov, $tot, $pct, $min);
ksort($groups);
echo "Theo nhóm:\n";
foreach ($groups as $g => [$s, $c]) {
    printf("  %-14s %5d / %5d  %5.1f%%\n", $g, $c, $s, $s ? $c / $s * 100 : 0);
}
usort($rows, fn($a, $b) => $b[0] <=> $a[0]);
echo "\nNhiều dòng chưa phủ nhất:\n";
foreach (array_slice($rows, 0, $top) as [$miss, $s, $c, $rel]) {
    printf("  %4d dòng chưa phủ  %5.1f%%  %s\n", $miss, $s ? $c / $s * 100 : 0, $rel);
}
exit($pct >= $min ? 0 : 1);
