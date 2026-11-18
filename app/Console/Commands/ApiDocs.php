<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use ReflectionMethod;

/**
 * Sinh đặc tả OpenAPI 3.0 cho API có phiên bản (/api/v1) từ CHÍNH bảng route + docblock của hàm controller, để tài liệu không lệch mã:
 *   php artisan api:docs            ghi docs/api/openapi.json
 *   php artisan api:docs --check    chỉ so, khác thì báo lỗi (CI / test dùng)
 * Mỗi đường dẫn: phương thức, tham số đường dẫn, cần đăng nhập (auth:api -> bearer JWT) hay không, giới hạn tần suất, mô tả = dòng đầu docblock.
 */
class ApiDocs extends Command
{
    protected $signature = 'api:docs {--check : chỉ kiểm tra tệp đã khớp chưa} {--out=docs/api/openapi.json}';
    protected $description = 'Sinh đặc tả OpenAPI cho /api/v1 từ bảng route';

    public function handle(): int
    {
        $json = json_encode(self::spec(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $path = base_path((string) $this->option('out'));
        if ($this->option('check')) {
            if (!is_file($path) || file_get_contents($path) !== $json) {
                $this->error('docs/api/openapi.json chưa khớp với bảng route: chạy php artisan api:docs');

                return self::FAILURE;
            }
            $this->info('OpenAPI khớp với bảng route.');

            return self::SUCCESS;
        }
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, $json);
        $this->info('Đã ghi ' . $this->option('out') . ' (' . count(self::spec()['paths']) . ' đường dẫn).');

        return self::SUCCESS;
    }

    public static function spec(): array
    {
        $paths = [];
        foreach (Router::getRoutes()->getRoutes() as $r) {
            /** @var Route $r */
            $uri = $r->uri();
            if (!str_starts_with($uri, 'api/v1/')) {
                continue;
            }
            $p = '/' . preg_replace('/\{(\w+)\??\}/', '{$1}', $uri);
            $mw = $r->gatherMiddleware();
            $auth = in_array('auth:api', $mw, true);
            $throttle = collect($mw)->first(fn($m) => is_string($m) && str_starts_with($m, 'throttle:'));
            foreach (array_diff($r->methods(), ['HEAD']) as $method) {
                $op = ['summary' => self::summary($r), 'operationId' => strtolower($method) . str_replace(['/', '{', '}', '-'], ['_', '', '', '_'], substr($p, 7)),
                    'tags' => [ucfirst(explode('/', substr($p, 8))[0] ?: 'misc')], 'responses' => ['200' => ['description' => 'Thành công']]];
                preg_match_all('/\{(\w+)\}/', $p, $m);
                foreach ($m[1] as $name) {
                    $op['parameters'][] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => in_array($name, ['id', 'tripId'], true) ? 'integer' : 'string']];
                }
                if ($auth) {
                    $op['security'] = [['bearerAuth' => []]];
                    $op['responses']['401'] = ['description' => 'Chưa đăng nhập / token hết hạn'];
                }
                if ($throttle) {
                    $op['responses']['429'] = ['description' => 'Quá giới hạn tần suất (' . substr((string) $throttle, 9) . ')'];
                }
                $paths[$p][strtolower($method)] = $op;
            }
        }
        ksort($paths);

        return [
            'openapi' => '3.0.3',
            'info' => ['title' => 'Travela API', 'version' => 'v1', 'description' => 'API có phiên bản cho app di động và đối tác. Sinh tự động từ bảng route (php artisan api:docs).'],
            'servers' => [['url' => '/']],
            'components' => ['securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT']]],
            'paths' => $paths,
        ];
    }

    private static function summary(Route $r): string
    {
        $a = $r->getAction('uses');
        if (is_string($a) && str_contains($a, '@')) {
            [$c, $m] = explode('@', $a);
            try {
                $doc = (string) (new ReflectionMethod($c, $m))->getDocComment();
                $line = trim(preg_replace('/^\s*\/?\*+\s?/m', '', explode("\n", trim($doc, "/* \n"))[0] ?? '') ?? '');
                if ($line !== '') {
                    return mb_substr($line, 0, 160);
                }
            } catch (\ReflectionException) {
            }

            return class_basename($c) . '::' . $m;
        }

        return 'closure';
    }
}
