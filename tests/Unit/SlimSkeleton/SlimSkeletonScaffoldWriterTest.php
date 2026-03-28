<?php

declare(strict_types=1);

namespace Tests\Unit\SlimSkeleton;

use AppContainer\SlimSkeleton\SlimSkeletonScaffoldWriter;
use AppContainer\SlimSkeleton\KernelMigrationResult;
use AppContainer\SlimSkeleton\ExceptionHandlerMigrationResult;
use AppContainer\SlimSkeleton\ConsoleKernelMigrationResult;
use AppContainer\SlimSkeleton\RouteServiceProviderMigrationResult;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AppContainer\SlimSkeleton\SlimSkeletonScaffoldWriter
 */
final class SlimSkeletonScaffoldWriterTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/scaffold_writer_test_' . uniqid();
        mkdir($this->tmpDir . '/bootstrap', 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDirectory($this->tmpDir);
    }

    public function test_writes_bootstrap_app_php(): void
    {
        $writer = new SlimSkeletonScaffoldWriter();

        ob_start();
        $result = $writer->write(
            workspacePath: $this->tmpDir,
            kernelResult: KernelMigrationResult::noKernelFile(),
            handlerResult: ExceptionHandlerMigrationResult::noHandlerFile(),
            consoleResult: ConsoleKernelMigrationResult::noConsoleKernel(),
            routesResult: RouteServiceProviderMigrationResult::noRouteServiceProvider(),
        );
        ob_end_clean();

        $this->assertTrue($result);
        $this->assertFileExists($this->tmpDir . '/bootstrap/app.php');
        $content = file_get_contents($this->tmpDir . '/bootstrap/app.php') ?: '';
        $this->assertStringContainsString('Application::configure', $content);
        $this->assertStringContainsString('->withMiddleware(', $content);
        $this->assertStringContainsString('->withExceptions(', $content);
    }

    public function test_idempotent_when_slim_skeleton_already_present(): void
    {
        $existingContent = '<?php return Application::configure(basePath: dirname(__DIR__))->create();';
        file_put_contents($this->tmpDir . '/bootstrap/app.php', $existingContent);

        $writer = new SlimSkeletonScaffoldWriter();

        ob_start();
        $result = $writer->write(
            workspacePath: $this->tmpDir,
            kernelResult: KernelMigrationResult::noKernelFile(),
            handlerResult: ExceptionHandlerMigrationResult::noHandlerFile(),
            consoleResult: ConsoleKernelMigrationResult::noConsoleKernel(),
            routesResult: RouteServiceProviderMigrationResult::noRouteServiceProvider(),
        );
        ob_end_clean();

        $this->assertTrue($result);
        // File should NOT be overwritten
        $this->assertSame($existingContent, file_get_contents($this->tmpDir . '/bootstrap/app.php'));
    }

    public function test_includes_custom_middleware_in_output(): void
    {
        $kernelResult = KernelMigrationResult::success(
            appendedGlobalMiddleware: ['App\\Http\\Middleware\\SecurityHeaders'],
            middlewareGroupDeltas: [],
            middlewareAliases: ['tenant' => 'App\\Http\\Middleware\\EnsureTenantIsActive'],
            middlewarePriority: [],
            trustProxiesAt: null,
            trustProxiesHeaders: null,
            manualReviewItems: [],
            backupFiles: [],
        );

        $writer = new SlimSkeletonScaffoldWriter();

        ob_start();
        $writer->write(
            workspacePath: $this->tmpDir,
            kernelResult: $kernelResult,
            handlerResult: ExceptionHandlerMigrationResult::noHandlerFile(),
            consoleResult: ConsoleKernelMigrationResult::noConsoleKernel(),
            routesResult: RouteServiceProviderMigrationResult::noRouteServiceProvider(),
        );
        ob_end_clean();

        $content = file_get_contents($this->tmpDir . '/bootstrap/app.php') ?: '';
        $this->assertStringContainsString('SecurityHeaders', $content);
        $this->assertStringContainsString('tenant', $content);
        $this->assertStringContainsString('EnsureTenantIsActive', $content);
    }

    public function test_includes_dont_report_and_dont_flash_in_output(): void
    {
        $handlerResult = ExceptionHandlerMigrationResult::success(
            dontReport: ['App\\Exceptions\\BusinessRuleException'],
            dontFlash: ['credit_card_number'],
            reportClosures: [],
            renderClosures: [],
            manualReviewItems: [],
            backupFiles: [],
        );

        $writer = new SlimSkeletonScaffoldWriter();

        ob_start();
        $writer->write(
            workspacePath: $this->tmpDir,
            kernelResult: KernelMigrationResult::noKernelFile(),
            handlerResult: $handlerResult,
            consoleResult: ConsoleKernelMigrationResult::noConsoleKernel(),
            routesResult: RouteServiceProviderMigrationResult::noRouteServiceProvider(),
        );
        ob_end_clean();

        $content = file_get_contents($this->tmpDir . '/bootstrap/app.php') ?: '';
        $this->assertStringContainsString('BusinessRuleException', $content);
        $this->assertStringContainsString('credit_card_number', $content);
    }

    public function test_includes_with_routing_paths(): void
    {
        $routesResult = RouteServiceProviderMigrationResult::success(
            webRoutes: "__DIR__.'/../routes/web.php'",
            apiRoutes: "__DIR__.'/../routes/api.php'",
            consoleRoutes: null,
            manualReviewItems: [],
        );

        $writer = new SlimSkeletonScaffoldWriter();

        ob_start();
        $writer->write(
            workspacePath: $this->tmpDir,
            kernelResult: KernelMigrationResult::noKernelFile(),
            handlerResult: ExceptionHandlerMigrationResult::noHandlerFile(),
            consoleResult: ConsoleKernelMigrationResult::noConsoleKernel(),
            routesResult: $routesResult,
        );
        ob_end_clean();

        $content = file_get_contents($this->tmpDir . '/bootstrap/app.php') ?: '';
        $this->assertStringContainsString('withRouting(', $content);
        $this->assertStringContainsString("routes/web.php", $content);
        $this->assertStringContainsString("routes/api.php", $content);
        $this->assertStringContainsString("/up", $content);
    }

    public function test_creates_bootstrap_directory_when_missing(): void
    {
        // Remove the pre-created bootstrap dir to simulate a fixture without it
        $bootstrapDir = $this->tmpDir . '/bootstrap';
        if (is_dir($bootstrapDir)) {
            rmdir($bootstrapDir);
        }

        $this->assertDirectoryDoesNotExist($bootstrapDir);

        $writer = new SlimSkeletonScaffoldWriter();

        ob_start();
        $result = $writer->write(
            workspacePath: $this->tmpDir,
            kernelResult: KernelMigrationResult::noKernelFile(),
            handlerResult: ExceptionHandlerMigrationResult::noHandlerFile(),
            consoleResult: ConsoleKernelMigrationResult::noConsoleKernel(),
            routesResult: RouteServiceProviderMigrationResult::noRouteServiceProvider(),
        );
        ob_end_clean();

        $this->assertTrue($result, 'write() should succeed even when bootstrap/ dir did not exist');
        $this->assertFileExists($this->tmpDir . '/bootstrap/app.php');
        $content = file_get_contents($this->tmpDir . '/bootstrap/app.php') ?: '';
        $this->assertStringContainsString('Application::configure', $content);
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
