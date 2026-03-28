<?php

declare(strict_types=1);

namespace Tests\Unit\SlimSkeleton;

use AppContainer\SlimSkeleton\SlimSkeletonGenerator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AppContainer\SlimSkeleton\SlimSkeletonGenerator
 */
final class SlimSkeletonGeneratorTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/slim_skeleton_gen_test_' . uniqid();
        mkdir($this->tmpDir . '/app/Http', 0777, true);
        mkdir($this->tmpDir . '/app/Exceptions', 0777, true);
        mkdir($this->tmpDir . '/app/Console', 0777, true);
        mkdir($this->tmpDir . '/bootstrap', 0777, true);
        mkdir($this->tmpDir . '/config', 0777, true);
        mkdir($this->tmpDir . '/routes', 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDirectory($this->tmpDir);
    }

    public function test_generates_bootstrap_app_from_minimal_fixture(): void
    {
        // Standard L10 Kernel
        file_put_contents($this->tmpDir . '/app/Http/Kernel.php', <<<'PHP'
<?php
namespace App\Http;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
class Kernel extends HttpKernel {
    protected $middleware = [
        \Illuminate\Http\Middleware\TrustProxies::class,
    ];
    protected $middlewareGroups = [
        'web' => [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
        ],
    ];
}
PHP);

        // Standard Handler
        file_put_contents($this->tmpDir . '/app/Exceptions/Handler.php', <<<'PHP'
<?php
namespace App\Exceptions;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
class Handler extends ExceptionHandler {
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];
}
PHP);

        // Config app with providers
        file_put_contents($this->tmpDir . '/config/app.php', <<<'PHP'
<?php
return [
    'providers' => [
        Illuminate\Auth\AuthServiceProvider::class,
        App\Providers\AppServiceProvider::class,
    ],
];
PHP);

        file_put_contents($this->tmpDir . '/routes/web.php', '<?php // web');

        $generator = SlimSkeletonGenerator::create();

        ob_start();
        $result = $generator->generate($this->tmpDir);
        ob_end_clean();

        // bootstrap/app.php should be written
        $this->assertFileExists($this->tmpDir . '/bootstrap/app.php');
        $content = file_get_contents($this->tmpDir . '/bootstrap/app.php') ?: '';
        $this->assertStringContainsString('Application::configure', $content);
        $this->assertStringContainsString('->withMiddleware(', $content);
        $this->assertStringContainsString('->withExceptions(', $content);

        // Audit result should have counts
        $this->assertGreaterThanOrEqual(0, $result->totalManualReviewItems);
        $this->assertIsArray($result->summary);
    }

    public function test_generates_with_no_source_files(): void
    {
        $generator = SlimSkeletonGenerator::create();

        ob_start();
        $result = $generator->generate($this->tmpDir);
        ob_end_clean();

        $this->assertFileExists($this->tmpDir . '/bootstrap/app.php');
        $this->assertSame(0, $result->errorCount);
    }

    public function test_writes_console_routes_when_schedule_exists(): void
    {
        file_put_contents($this->tmpDir . '/app/Console/Kernel.php', <<<'PHP'
<?php
namespace App\Console;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
class Kernel extends ConsoleKernel {
    protected function schedule(Schedule $schedule): void {
        $schedule->command('emails:send')->dailyAt('08:00');
    }
}
PHP);

        $generator = SlimSkeletonGenerator::create();

        ob_start();
        $generator->generate($this->tmpDir);
        ob_end_clean();

        $consoleRoutes = $this->tmpDir . '/routes/console.php';
        $this->assertFileExists($consoleRoutes);
        $content = file_get_contents($consoleRoutes) ?: '';
        $this->assertStringContainsString('Schedule::', $content);
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
