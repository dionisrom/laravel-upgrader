<?php

declare(strict_types=1);

namespace Tests\Unit\SlimSkeleton;

use AppContainer\SlimSkeleton\RouteServiceProviderMigrator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AppContainer\SlimSkeleton\RouteServiceProviderMigrator
 */
final class RouteServiceProviderMigratorTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/route_sp_migrator_test_' . uniqid();
        mkdir($this->tmpDir . '/app/Providers', 0777, true);
        mkdir($this->tmpDir . '/routes', 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDirectory($this->tmpDir);
    }

    public function test_falls_back_to_defaults_when_rsp_absent(): void
    {
        file_put_contents($this->tmpDir . '/routes/web.php', '<?php // web');
        file_put_contents($this->tmpDir . '/routes/api.php', '<?php // api');

        $migrator = new RouteServiceProviderMigrator();

        ob_start();
        $result = $migrator->migrate($this->tmpDir);
        ob_end_clean();

        $this->assertTrue($result->success);
        $this->assertStringContainsString('routes/web.php', $result->webRoutes ?? '');
        $this->assertStringContainsString('routes/api.php', $result->apiRoutes ?? '');
    }

    public function test_returns_null_routes_when_no_route_files_exist(): void
    {
        // Remove routes dir
        $this->removeDirectory($this->tmpDir . '/routes');

        $migrator = new RouteServiceProviderMigrator();

        ob_start();
        $result = $migrator->migrate($this->tmpDir);
        ob_end_clean();

        $this->assertTrue($result->success);
        $this->assertNull($result->webRoutes);
        $this->assertNull($result->apiRoutes);
    }

    public function test_extracts_route_paths_from_rsp(): void
    {
        file_put_contents($this->tmpDir . '/routes/web.php', '<?php // web');
        file_put_contents($this->tmpDir . '/routes/api.php', '<?php // api');
        $this->writeRsp(<<<'PHP'
<?php
namespace App\Providers;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
class RouteServiceProvider extends ServiceProvider {
    public function boot(): void {
        $this->routes(function () {
            Route::middleware('web')->group(base_path('routes/web.php'));
            Route::prefix('api')->middleware('api')->group(base_path('routes/api.php'));
        });
    }
}
PHP);

        $migrator = new RouteServiceProviderMigrator();

        ob_start();
        $result = $migrator->migrate($this->tmpDir);
        ob_end_clean();

        $this->assertTrue($result->success);
        $this->assertNotNull($result->webRoutes);
        $this->assertNotNull($result->apiRoutes);
    }

    public function test_detects_dynamic_loading(): void
    {
        $this->writeRsp(<<<'PHP'
<?php
namespace App\Providers;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
class RouteServiceProvider extends ServiceProvider {
    public function boot(): void {
        foreach (glob(base_path('routes/modules/*.php')) as $file) {
            require $file;
        }
    }
}
PHP);

        $migrator = new RouteServiceProviderMigrator();

        ob_start();
        $result = $migrator->migrate($this->tmpDir);
        ob_end_clean();

        $this->assertTrue($result->success);
        $this->assertNotEmpty($result->manualReviewItems);
        $warningItems = array_filter($result->manualReviewItems, fn($i) => $i->severity === 'warning');
        $this->assertNotEmpty($warningItems);
    }

    public function test_failure_result_on_parse_error(): void
    {
        $this->writeRsp('<?php not valid {{{{');

        $migrator = new RouteServiceProviderMigrator();
        $result   = $migrator->migrate($this->tmpDir);

        $this->assertFalse($result->success);
        $this->assertNotNull($result->errorMessage);
    }

    private function writeRsp(string $code): void
    {
        file_put_contents($this->tmpDir . '/app/Providers/RouteServiceProvider.php', $code);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
