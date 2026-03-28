<?php

declare(strict_types=1);

namespace Tests\Unit\Lumen;

use AppContainer\Lumen\ExceptionHandlerMigrator;
use PHPUnit\Framework\TestCase;

final class ExceptionHandlerMigratorTest extends TestCase
{
    private string $tempDir;
    private string $workspace;
    private string $target;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/upgrader-handler-test-' . uniqid('', true);
        $this->workspace = $this->tempDir . '/workspace';
        $this->target = $this->tempDir . '/target';
        mkdir($this->workspace . '/app/Exceptions', 0777, true);
        mkdir($this->target, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    public function testReplacesLumenParentClassWithLaravelParent(): void
    {
        file_put_contents($this->workspace . '/app/Exceptions/Handler.php', <<<'PHP'
<?php

namespace App\Exceptions;

use Laravel\Lumen\Exceptions\Handler as ExceptionHandler;

class Handler extends ExceptionHandler
{
    public function report(\Throwable $e): void
    {
        parent::report($e);
    }

    public function render($request, \Throwable $e)
    {
        return parent::render($request, $e);
    }
}
PHP);

        $migrator = new ExceptionHandlerMigrator();
        ob_start();
        $result = $migrator->migrate($this->workspace, $this->target);
        ob_end_clean();

        self::assertTrue($result->success);
        self::assertContains('report', $result->mappedMethods);
        self::assertContains('render', $result->mappedMethods);

        $output = file_get_contents($this->target . '/app/Exceptions/Handler.php');
        self::assertStringContainsString('Illuminate\Foundation\Exceptions\Handler', $output);
        // The extends clause should reference the Laravel parent, not Lumen
        self::assertMatchesRegularExpression('/extends\s+\\\\?Illuminate\\\\Foundation\\\\Exceptions\\\\Handler/', $output);
    }

    public function testCustomMethodFlaggedForManualReview(): void
    {
        file_put_contents($this->workspace . '/app/Exceptions/Handler.php', <<<'PHP'
<?php

namespace App\Exceptions;

use Laravel\Lumen\Exceptions\Handler as ExceptionHandler;

class Handler extends ExceptionHandler
{
    public function report(\Throwable $e): void
    {
        parent::report($e);
    }

    public function customHandler(\Throwable $e): string
    {
        return 'handled';
    }
}
PHP);

        $migrator = new ExceptionHandlerMigrator();
        ob_start();
        $result = $migrator->migrate($this->workspace, $this->target);
        ob_end_clean();

        self::assertNotEmpty($result->manualReviewItems);
        self::assertStringContainsString('customHandler', $result->manualReviewItems[0]->description);
    }

    public function testMissingHandlerReturnsSuccessEmpty(): void
    {
        rmdir($this->workspace . '/app/Exceptions');

        $migrator = new ExceptionHandlerMigrator();
        ob_start();
        $result = $migrator->migrate($this->workspace, $this->target);
        ob_end_clean();

        self::assertTrue($result->success);
        self::assertEmpty($result->mappedMethods);
    }

    public function testEmitsJsonNdEvents(): void
    {
        file_put_contents($this->workspace . '/app/Exceptions/Handler.php', <<<'PHP'
<?php
namespace App\Exceptions;
use Laravel\Lumen\Exceptions\Handler as ExceptionHandler;
class Handler extends ExceptionHandler {}
PHP);

        $migrator = new ExceptionHandlerMigrator();
        ob_start();
        $migrator->migrate($this->workspace, $this->target);
        $output = (string) ob_get_clean();

        $lines = array_filter(array_map('trim', explode("\n", $output)));
        $event = json_decode(end($lines), true);
        self::assertSame('lumen_exception_handler_migrated', $event['event']);
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
