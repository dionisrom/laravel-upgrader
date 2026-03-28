<?php

declare(strict_types=1);

namespace Tests\Unit\SlimSkeleton;

use AppContainer\SlimSkeleton\KernelMigrator;
use AppContainer\SlimSkeleton\KernelMigrationResult;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AppContainer\SlimSkeleton\KernelMigrator
 */
final class KernelMigratorTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/kernel_migrator_test_' . uniqid();
        mkdir($this->tmpDir . '/app/Http', 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDirectory($this->tmpDir);
    }

    public function test_returns_no_kernel_file_result_when_kernel_absent(): void
    {
        $migrator = new KernelMigrator();
        $result   = $migrator->migrate($this->tmpDir);

        $this->assertFalse($result->kernelFileExists);
        $this->assertTrue($result->success);
        $this->assertSame([], $result->appendedGlobalMiddleware);
    }

    public function test_extracts_custom_global_middleware(): void
    {
        $this->writeKernel(<<<'PHP'
<?php
namespace App\Http;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
class Kernel extends HttpKernel {
    protected $middleware = [
        \Illuminate\Http\Middleware\TrustProxies::class,
        \App\Http\Middleware\SecurityHeaders::class,
    ];
}
PHP);

        $migrator = new KernelMigrator();
        $result   = $migrator->migrate($this->tmpDir);

        $this->assertTrue($result->success);
        $this->assertTrue($result->kernelFileExists);
        // TrustProxies is L11 default, SecurityHeaders is custom
        $this->assertContains('App\\Http\\Middleware\\SecurityHeaders', $result->appendedGlobalMiddleware);
        $this->assertNotContains('Illuminate\\Http\\Middleware\\TrustProxies', $result->appendedGlobalMiddleware);
    }

    public function test_extracts_custom_middleware_aliases(): void
    {
        $this->writeKernel(<<<'PHP'
<?php
namespace App\Http;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
class Kernel extends HttpKernel {
    protected $middlewareAliases = [
        'auth'   => \App\Http\Middleware\Authenticate::class,
        'tenant' => \App\Http\Middleware\EnsureTenantIsActive::class,
        'plan'   => \App\Http\Middleware\CheckSubscriptionPlan::class,
    ];
}
PHP);

        $migrator = new KernelMigrator();
        $result   = $migrator->migrate($this->tmpDir);

        $this->assertTrue($result->success);
        // 'auth' is an L11 default alias — should not be in output
        $this->assertArrayNotHasKey('auth', $result->middlewareAliases);
        $this->assertArrayHasKey('tenant', $result->middlewareAliases);
        $this->assertArrayHasKey('plan', $result->middlewareAliases);
    }

    public function test_detects_custom_handle_method_and_backs_up(): void
    {
        $kernelCode = <<<'PHP'
<?php
namespace App\Http;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
class Kernel extends HttpKernel {
    public function handle($request) {
        return parent::handle($request);
    }
}
PHP;
        $this->writeKernel($kernelCode);

        $migrator = new KernelMigrator();

        ob_start();
        $result = $migrator->migrate($this->tmpDir);
        ob_end_clean();

        $this->assertTrue($result->success);
        $this->assertCount(1, $result->backupFiles);
        $this->assertFileExists($this->tmpDir . '/app/Http/Kernel.php.laravel-backup');
        $this->assertNotEmpty($result->manualReviewItems);

        $errorItems = array_filter($result->manualReviewItems, fn($i) => $i->severity === 'error');
        $this->assertNotEmpty($errorItems);
    }

    public function test_detects_configure_rate_limiting_method(): void
    {
        $this->writeKernel(<<<'PHP'
<?php
namespace App\Http;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
class Kernel extends HttpKernel {
    protected function configureRateLimiting(): void {
        // ...
    }
}
PHP);

        $migrator = new KernelMigrator();

        ob_start();
        $result = $migrator->migrate($this->tmpDir);
        ob_end_clean();

        $infoItems = array_filter($result->manualReviewItems, fn($i) => $i->category === 'kernel');
        $this->assertNotEmpty($infoItems);
    }

    public function test_extracts_middleware_priority(): void
    {
        $this->writeKernel(<<<'PHP'
<?php
namespace App\Http;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
class Kernel extends HttpKernel {
    protected $middlewarePriority = [
        \Illuminate\Session\Middleware\StartSession::class,
        \App\Http\Middleware\EnsureTenantIsActive::class,
    ];
}
PHP);

        $migrator = new KernelMigrator();
        $result   = $migrator->migrate($this->tmpDir);

        $this->assertNotEmpty($result->middlewarePriority);
        $this->assertCount(2, $result->middlewarePriority);
    }

    public function test_backup_not_overwritten_if_already_exists(): void
    {
        $this->writeKernel(<<<'PHP'
<?php
namespace App\Http;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
class Kernel extends HttpKernel {
    public function handle($request) { return parent::handle($request); }
}
PHP);
        // Pre-create backup with known content
        file_put_contents($this->tmpDir . '/app/Http/Kernel.php.laravel-backup', 'existing-backup');

        $migrator = new KernelMigrator();

        ob_start();
        $result = $migrator->migrate($this->tmpDir);
        ob_end_clean();

        // Backup should not be overwritten
        $this->assertSame('existing-backup', file_get_contents($this->tmpDir . '/app/Http/Kernel.php.laravel-backup'));
    }

    public function test_failure_result_on_parse_error(): void
    {
        $this->writeKernel('<?php this is not valid php {{{{');

        $migrator = new KernelMigrator();
        $result   = $migrator->migrate($this->tmpDir);

        $this->assertFalse($result->success);
        $this->assertNotNull($result->errorMessage);
    }

    private function writeKernel(string $code): void
    {
        file_put_contents($this->tmpDir . '/app/Http/Kernel.php', $code);
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
