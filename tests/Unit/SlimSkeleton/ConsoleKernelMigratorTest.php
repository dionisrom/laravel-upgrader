<?php

declare(strict_types=1);

namespace Tests\Unit\SlimSkeleton;

use AppContainer\SlimSkeleton\ConsoleKernelMigrator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AppContainer\SlimSkeleton\ConsoleKernelMigrator
 */
final class ConsoleKernelMigratorTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/console_kernel_test_' . uniqid();
        mkdir($this->tmpDir . '/app/Console', 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDirectory($this->tmpDir);
    }

    public function test_returns_no_console_kernel_result_when_absent(): void
    {
        $migrator = new ConsoleKernelMigrator();
        $result   = $migrator->migrate($this->tmpDir);

        $this->assertFalse($result->consoleKernelExists);
        $this->assertTrue($result->success);
    }

    public function test_extracts_schedule_statements(): void
    {
        $this->writeKernel(<<<'PHP'
<?php
namespace App\Console;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
class Kernel extends ConsoleKernel {
    protected function schedule(Schedule $schedule): void {
        $schedule->command('emails:send')->dailyAt('08:00');
        $schedule->command('reports:generate')->weekly();
    }
}
PHP);

        $migrator = new ConsoleKernelMigrator();

        ob_start();
        $result = $migrator->migrate($this->tmpDir);
        ob_end_clean();

        $this->assertTrue($result->success);
        $this->assertCount(2, $result->scheduleStatements);
        // Schedule:: transformation should be applied
        foreach ($result->scheduleStatements as $stmt) {
            $this->assertStringContainsString('Schedule::', $stmt);
            $this->assertStringNotContainsString('$schedule->', $stmt);
        }
    }

    public function test_extracts_command_classes_from_property(): void
    {
        $this->writeKernel(<<<'PHP'
<?php
namespace App\Console;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
class Kernel extends ConsoleKernel {
    protected $commands = [
        \App\Console\Commands\ImportUsers::class,
        \App\Console\Commands\GenerateReport::class,
    ];
}
PHP);

        $migrator = new ConsoleKernelMigrator();

        ob_start();
        $result = $migrator->migrate($this->tmpDir);
        ob_end_clean();

        $this->assertTrue($result->success);
        $this->assertCount(2, $result->commandClasses);
        $this->assertContains('App\\Console\\Commands\\ImportUsers', $result->commandClasses);
        $this->assertContains('App\\Console\\Commands\\GenerateReport', $result->commandClasses);
    }

    public function test_detects_bootstrap_with_override(): void
    {
        $this->writeKernel(<<<'PHP'
<?php
namespace App\Console;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
class Kernel extends ConsoleKernel {
    protected function bootstrapWith(array $bootstrappers): void {
        parent::bootstrapWith($bootstrappers);
    }
}
PHP);

        $migrator = new ConsoleKernelMigrator();

        ob_start();
        $result = $migrator->migrate($this->tmpDir);
        ob_end_clean();

        $errorItems = array_filter($result->manualReviewItems, fn($i) => $i->severity === 'error');
        $this->assertNotEmpty($errorItems);
        $this->assertCount(1, $result->backupFiles);
    }

    public function test_resolves_short_imported_command_class_names(): void
    {
        $this->writeKernel(<<<'PHP'
<?php
namespace App\Console;

use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Console\Commands\ImportUsers;
use App\Console\Commands\GenerateReport;

class Kernel extends ConsoleKernel {
    protected $commands = [
        ImportUsers::class,
        GenerateReport::class,
    ];
}
PHP);

        $migrator = new ConsoleKernelMigrator();

        ob_start();
        $result = $migrator->migrate($this->tmpDir);
        ob_end_clean();

        $this->assertTrue($result->success);
        $this->assertCount(2, $result->commandClasses);
        $this->assertContains('App\\Console\\Commands\\ImportUsers', $result->commandClasses);
        $this->assertContains('App\\Console\\Commands\\GenerateReport', $result->commandClasses);
    }

    public function test_detects_conditional_scheduling(): void
    {
        $this->writeKernel(<<<'PHP'
<?php
namespace App\Console;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
class Kernel extends ConsoleKernel {
    protected function schedule(Schedule $schedule): void {
        if (app()->isProduction()) {
            $schedule->command('cleanup')->daily();
        }
    }
}
PHP);

        $migrator = new ConsoleKernelMigrator();

        ob_start();
        $result = $migrator->migrate($this->tmpDir);
        ob_end_clean();

        $infoItems = array_filter($result->manualReviewItems, fn($i) => $i->severity === 'info');
        $this->assertNotEmpty($infoItems);
    }

    private function writeKernel(string $code): void
    {
        file_put_contents($this->tmpDir . '/app/Console/Kernel.php', $code);
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
