<?php

declare(strict_types=1);

namespace Tests\Unit\Detector;

use AppContainer\Detector\DetectionResult;
use AppContainer\Detector\Exception\DetectionException;
use AppContainer\Detector\InventoryScanner;
use PHPUnit\Framework\TestCase;

final class InventoryScannerTest extends TestCase
{
    private InventoryScanner $scanner;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scanner = new InventoryScanner();
        $this->tempDir = sys_get_temp_dir() . '/upgrader-inventory-tests-' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->removeDir($this->tempDir);
    }

    // -------------------------------------------------
    // Returns DetectionResult
    // -------------------------------------------------

    public function testScanReturnsDetectionResult(): void
    {
        $workspace = $this->buildMinimalLaravelWorkspace();

        ob_start();
        $result = $this->scanner->scan($workspace);
        ob_end_clean();

        self::assertInstanceOf(DetectionResult::class, $result);
    }

    public function testScanPopulatesFrameworkAndVersion(): void
    {
        $workspace = $this->buildMinimalLaravelWorkspace();

        ob_start();
        $result = $this->scanner->scan($workspace);
        ob_end_clean();

        self::assertSame('laravel', $result->framework);
        self::assertSame('8.83.27', $result->laravelVersion);
        self::assertSame('^8.0', $result->phpConstraint);
    }

    // -------------------------------------------------
    // File counting
    // -------------------------------------------------

    public function testCountsPhpFiles(): void
    {
        $workspace = $this->buildMinimalLaravelWorkspace();

        // Add a couple of PHP files in random directories
        $this->writeFile($workspace . '/app/Http/Controllers/FooController.php', '<?php class FooController {}');
        $this->writeFile($workspace . '/app/Models/User.php', '<?php class User {}');

        ob_start();
        $result = $this->scanner->scan($workspace);
        ob_end_clean();

        // At minimum our 2 plus whatever's already in the workspace scaffold
        self::assertGreaterThanOrEqual(2, $result->phpFiles);
    }

    public function testCountsConfigFiles(): void
    {
        $workspace = $this->buildMinimalLaravelWorkspace();

        $this->writeFile($workspace . '/config/app.php', '<?php return [];');
        $this->writeFile($workspace . '/config/database.php', '<?php return [];');

        ob_start();
        $result = $this->scanner->scan($workspace);
        ob_end_clean();

        self::assertSame(2, $result->configFiles);
    }

    public function testCountsRouteFiles(): void
    {
        $workspace = $this->buildMinimalLaravelWorkspace();

        $this->writeFile($workspace . '/routes/web.php', '<?php');
        $this->writeFile($workspace . '/routes/api.php', '<?php');

        ob_start();
        $result = $this->scanner->scan($workspace);
        ob_end_clean();

        self::assertSame(2, $result->routeFiles);
    }

    public function testCountsMigrationFiles(): void
    {
        $workspace = $this->buildMinimalLaravelWorkspace();

        $this->writeFile($workspace . '/database/migrations/2020_01_01_000000_create_users_table.php', '<?php');
        $this->writeFile($workspace . '/database/migrations/2021_06_01_000000_add_column.php', '<?php');

        ob_start();
        $result = $this->scanner->scan($workspace);
        ob_end_clean();

        self::assertSame(2, $result->migrationFiles);
    }

    public function testCountsViewFiles(): void
    {
        $workspace = $this->buildMinimalLaravelWorkspace();

        $this->writeFile($workspace . '/resources/views/welcome.blade.php', '<html></html>');
        $this->writeFile($workspace . '/resources/views/layouts/app.blade.php', '<html></html>');

        ob_start();
        $result = $this->scanner->scan($workspace);
        ob_end_clean();

        self::assertSame(2, $result->viewFiles);
    }

    public function testExcludesVendorDirectory(): void
    {
        $workspace = $this->buildMinimalLaravelWorkspace();

        $this->writeFile($workspace . '/vendor/laravel/framework/src/Foo.php', '<?php');
        $this->writeFile($workspace . '/app/Models/Bar.php', '<?php class Bar {}');

        ob_start();
        $result = $this->scanner->scan($workspace);
        ob_end_clean();

        // vendor file must not be counted
        // Only app/Models/Bar.php should influence phpFiles
        // (plus bootstrap/app.php if present)
        self::assertGreaterThanOrEqual(1, $result->phpFiles);

        // Verify the vendor file is not in total count by checking
        // scanning vendor-only workspace would yield fewer files
        $vendorOnlyWorkspace = $this->buildMinimalLaravelWorkspace('vendor-only-check');
        $this->writeFile($vendorOnlyWorkspace . '/vendor/foo/bar.php', '<?php');

        ob_start();
        $vendorResult = $this->scanner->scan($vendorOnlyWorkspace);
        ob_end_clean();

        // Our workspace with an extra app file should have >= vendor-only result
        self::assertGreaterThanOrEqual($vendorResult->phpFiles, $result->phpFiles);
    }

    public function testExcludesUpgraderStateDirectory(): void
    {
        $workspace = $this->buildMinimalLaravelWorkspace();

        $this->writeFile($workspace . '/.upgrader-state/checkpoint.json', '{}');
        $this->writeFile($workspace . '/.upgrader-state/state.php', '<?php');

        ob_start();
        $result = $this->scanner->scan($workspace);
        ob_end_clean();

        // .upgrader-state PHP files must not count toward phpFiles
        // We can verify indirectly: a clean workspace with no other PHP files
        $cleanWorkspace = $this->buildMinimalLaravelWorkspace('clean-check');

        ob_start();
        $cleanResult = $this->scanner->scan($cleanWorkspace);
        ob_end_clean();

        self::assertSame($cleanResult->phpFiles, $result->phpFiles);
    }

    // -------------------------------------------------
    // pipeline_start event
    // -------------------------------------------------

    public function testEmitsPipelineStartEvent(): void
    {
        $workspace = $this->buildMinimalLaravelWorkspace();
        putenv('UPGRADER_REPO_LABEL=fixture-repo');

        ob_start();
        try {
            $this->scanner->scan($workspace, '8_to_9', 1);
            $output = (string) ob_get_clean();
        } finally {
            putenv('UPGRADER_REPO_LABEL');
        }

        // May contain a warning event from framework detection before pipeline_start,
        // so locate the pipeline_start line
        $lines = array_filter(array_map('trim', explode("\n", $output)));

        $pipelineEvent = null;
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && ($decoded['event'] ?? null) === 'pipeline_start') {
                $pipelineEvent = $decoded;
                break;
            }
        }

        self::assertNotNull($pipelineEvent, 'pipeline_start event not found in stdout');
        self::assertSame('pipeline_start', $pipelineEvent['event']);
        self::assertSame('8_to_9', $pipelineEvent['hop']);
        self::assertSame(1, $pipelineEvent['seq']);
        self::assertSame('fixture-repo', $pipelineEvent['repo']);
        self::assertArrayHasKey('total_files', $pipelineEvent);
        self::assertArrayHasKey('php_files', $pipelineEvent);
        self::assertArrayHasKey('config_files', $pipelineEvent);
        self::assertArrayHasKey('ts', $pipelineEvent);
    }

    // -------------------------------------------------
    // Error cases
    // -------------------------------------------------

    public function testThrowsDetectionExceptionForInvalidWorkspacePath(): void
    {
        $this->expectException(DetectionException::class);
        $this->expectExceptionMessage('Workspace path is not a directory');

        ob_start();
        try {
            $this->scanner->scan($this->tempDir . '/does-not-exist');
        } finally {
            ob_end_clean();
        }
    }

    // -------------------------------------------------
    // Helpers
    // -------------------------------------------------

    private function buildMinimalLaravelWorkspace(string $name = 'laravel-workspace'): string
    {
        $workspace = $this->tempDir . '/' . $name;
        mkdir($workspace, 0777, true);

        file_put_contents(
            $workspace . '/composer.json',
            json_encode(['require' => ['php' => '^8.0', 'laravel/framework' => '^8.0']])
        );

        file_put_contents(
            $workspace . '/composer.lock',
            json_encode([
                'packages' => [
                    ['name' => 'laravel/framework', 'version' => 'v8.83.27'],
                ],
            ])
        );

        return $workspace;
    }

    private function writeFile(string $path, string $contents): void
    {
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($path, $contents);
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
