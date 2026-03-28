<?php

declare(strict_types=1);

namespace Tests\Unit\Lumen;

use AppContainer\Lumen\MiddlewareMigrator;
use PHPUnit\Framework\TestCase;

final class MiddlewareMigratorTest extends TestCase
{
    private string $tempDir;
    private string $workspace;
    private string $target;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/upgrader-middleware-test-' . uniqid('', true);
        $this->workspace = $this->tempDir . '/workspace';
        $this->target = $this->tempDir . '/target';
        mkdir($this->workspace . '/bootstrap', 0777, true);
        mkdir($this->target . '/app/Http', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    public function testMigratesGlobalMiddleware(): void
    {
        file_put_contents($this->workspace . '/bootstrap/app.php', <<<'PHP'
<?php
$app = new Laravel\Lumen\Application(dirname(__DIR__));
$app->middleware([
    App\Http\Middleware\ExampleMiddleware::class,
]);
PHP);

        $this->createKernelStub();

        $migrator = new MiddlewareMigrator();
        ob_start();
        $result = $migrator->migrate($this->workspace, $this->target);
        ob_end_clean();

        self::assertTrue($result->success);
        self::assertGreaterThanOrEqual(1, $result->migratedCount);

        $kernel = file_get_contents($this->target . '/app/Http/Kernel.php');
        self::assertStringContainsString('ExampleMiddleware', $kernel);
    }

    public function testMigratesRouteMiddleware(): void
    {
        file_put_contents($this->workspace . '/bootstrap/app.php', <<<'PHP'
<?php
$app = new Laravel\Lumen\Application(dirname(__DIR__));
$app->routeMiddleware([
    'auth' => App\Http\Middleware\Authenticate::class,
]);
PHP);

        $this->createKernelStub();

        $migrator = new MiddlewareMigrator();
        ob_start();
        $result = $migrator->migrate($this->workspace, $this->target);
        ob_end_clean();

        self::assertTrue($result->success);
        // Now stores full alias→class map
        self::assertArrayHasKey('auth', $result->routeMiddleware);

        $kernel = file_get_contents($this->target . '/app/Http/Kernel.php');
        self::assertStringContainsString("'auth'", $kernel);
        self::assertStringContainsString('Authenticate', $kernel);
    }

    public function testMissingKernelFlagsManualReview(): void
    {
        file_put_contents($this->workspace . '/bootstrap/app.php', <<<'PHP'
<?php
$app = new Laravel\Lumen\Application(dirname(__DIR__));
$app->middleware([App\Http\Middleware\Example::class]);
PHP);

        // No Kernel.php in target

        $migrator = new MiddlewareMigrator();
        ob_start();
        $result = $migrator->migrate($this->workspace, $this->target);
        ob_end_clean();

        self::assertNotEmpty($result->manualReviewItems);
        self::assertStringContainsString('Kernel.php not found', $result->manualReviewItems[0]->description);
    }

    public function testMissingBootstrapReturnsEmptySuccess(): void
    {
        rmdir($this->workspace . '/bootstrap');

        $migrator = new MiddlewareMigrator();
        ob_start();
        $result = $migrator->migrate($this->workspace, $this->target);
        ob_end_clean();

        self::assertTrue($result->success);
        self::assertSame(0, $result->migratedCount);
    }

    public function testEmitsJsonNdEvents(): void
    {
        file_put_contents($this->workspace . '/bootstrap/app.php', <<<'PHP'
<?php
$app = new Laravel\Lumen\Application(dirname(__DIR__));
$app->middleware([App\Http\Middleware\Example::class]);
PHP);

        $this->createKernelStub();

        $migrator = new MiddlewareMigrator();
        ob_start();
        $migrator->migrate($this->workspace, $this->target);
        $output = (string) ob_get_clean();

        $lines = array_filter(array_map('trim', explode("\n", $output)));
        $event = json_decode(end($lines), true);
        self::assertSame('lumen_middleware_migrated', $event['event']);
    }

    private function createKernelStub(): void
    {
        file_put_contents($this->target . '/app/Http/Kernel.php', <<<'PHP'
<?php

namespace App\Http;

class Kernel extends \Illuminate\Foundation\Http\Kernel
{
    protected $middleware = [
    ];

    protected $routeMiddleware = [
    ];
}
PHP);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
