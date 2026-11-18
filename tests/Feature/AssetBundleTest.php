<?php

namespace Tests\Feature;

use App\Console\Commands\AssetsBundle;
use App\Support\AssetBundle;
use Tests\TestCase;

/** Đợt C mục 17: gộp CSS/JS dùng chung. Đường dẫn ảnh/phông trong CSS phải còn đúng sau khi gộp; chưa gộp hoặc gộp cũ thì dùng tệp lẻ. */
class AssetBundleTest extends TestCase
{
    private string $dir = 'clients/assets/build-test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['assets.dir' => $this->dir, 'assets.enabled' => true, 'assets.bundles' => [
            't.css' => ['clients/assets/css/planner-media.css'],
        ]]);
        AssetBundle::reset();
        @mkdir(public_path($this->dir), 0777, true);
    }

    protected function tearDown(): void
    {
        // xóa cả thư mục con (bản JS rút gọn nằm ở min/{băm}/)
        if (is_dir(public_path($this->dir))) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(public_path($this->dir), \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
        }
        @rmdir(public_path($this->dir));
        AssetBundle::reset();
        parent::tearDown();
    }

    public function test_rebase_keeps_relative_urls_pointing_to_the_same_file(): void
    {
        $css = 'a{background:url(../images/x.png)} b{src:url("../fonts/f.woff2?v=1#iefix")} c{background:url(data:image/png;base64,AA)} d{background:url(https://cdn.example/x.png)} e{background:url(/abs.png)}';
        $out = AssetsBundle::rebase($css, 'clients/assets/css', 'clients/assets/build');

        $this->assertStringContainsString('url(../images/x.png)', $out);   // cùng độ sâu thư mục
        $this->assertStringContainsString('url("../fonts/f.woff2?v=1#iefix")', $out);
        $this->assertStringContainsString('url(data:image/png;base64,AA)', $out);
        $this->assertStringContainsString('url(https://cdn.example/x.png)', $out);
        $this->assertStringContainsString('url(/abs.png)', $out);

        $deep = AssetsBundle::rebase('a{background:url(fonts/i.woff)}', 'clients/assets/css/css-login/fonts/material-icon/css', 'clients/assets/build');
        $this->assertStringContainsString('url(../css/css-login/fonts/material-icon/css/fonts/i.woff)', $deep);
    }

    public function test_minify_keeps_meaning_of_selectors_strings_and_calc(): void
    {
        $css = "/* chú thích */\n.a  .b ,\n .c>.d {\n  width: calc(100% - 2px) ;\n  content: \"x  /* y */  z\";\n}\n.e/**/.f { color : red }\n";
        $out = AssetsBundle::minifyCss($css);

        $this->assertSame('.a .b,.c>.d{width: calc(100% - 2px);content: "x  /* y */  z";}.e.f{color : red}', $out);
    }

    public function test_without_bundle_each_file_is_linked_with_version(): void
    {
        $html = AssetBundle::tags('t.css');
        $this->assertStringContainsString('clients/assets/css/planner-media.css?v=', $html);
        $this->assertNull(AssetBundle::built('t.css'));
    }

    public function test_built_bundle_is_used_and_stale_one_is_skipped_in_debug(): void
    {
        $this->artisan('assets:bundle')->assertExitCode(0);
        AssetBundle::reset();
        $built = AssetBundle::built('t.css');
        $this->assertNotNull($built);
        $this->assertStringContainsString($built, AssetBundle::tags('t.css'));
        $this->assertFileExists(public_path($built));

        // sửa tệp nguồn sau lần gộp: lúc phát triển phải quay về tệp lẻ
        $manifest = json_decode(file_get_contents(AssetBundle::manifestPath()), true);
        $manifest['t.css']['built'] = filemtime(public_path('clients/assets/css/planner-media.css')) - 10;
        file_put_contents(AssetBundle::manifestPath(), json_encode($manifest));
        AssetBundle::reset();
        config(['app.debug' => true]);
        // giờ sửa mới hơn nhưng nội dung y nguyên (chép / giải nén): vẫn dùng bản gộp
        $this->assertNotNull(AssetBundle::built('t.css'));
        $this->assertFalse(AssetBundle::stale('t.css'));
        // nội dung nguồn đã khác bản gộp: quay về tệp lẻ, trang Bảo trì báo cũ
        $manifest['t.css']['src'] = 'khac';
        file_put_contents(AssetBundle::manifestPath(), json_encode($manifest));
        AssetBundle::reset();
        $this->assertNull(AssetBundle::built('t.css'));
        $this->assertTrue(AssetBundle::stale('t.css'));
        config(['app.debug' => false]);
        AssetBundle::reset();
        $this->assertNotNull(AssetBundle::built('t.css'));

        config(['assets.enabled' => false]);
        $this->assertNull(AssetBundle::built('t.css'));
    }
}
