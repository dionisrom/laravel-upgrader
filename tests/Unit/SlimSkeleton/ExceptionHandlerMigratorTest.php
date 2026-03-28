<?php

declare(strict_types=1);

namespace Tests\Unit\SlimSkeleton;

use AppContainer\SlimSkeleton\ExceptionHandlerMigrator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AppContainer\SlimSkeleton\ExceptionHandlerMigrator
 */
final class ExceptionHandlerMigratorTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/handler_migrator_test_' . uniqid();
        mkdir($this->tmpDir . '/app/Exceptions', 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDirectory($this->tmpDir);
    }

    public function test_returns_no_handler_file_result_when_absent(): void
    {
        $migrator = new ExceptionHandlerMigrator();
        $result   = $migrator->migrate($this->tmpDir);

        $this->assertFalse($result->handlerFileExists);
        $this->assertTrue($result->success);
        $this->assertSame([], $result->dontReport);
    }

    public function test_extracts_custom_dont_flash(): void
    {
        $this->writeHandler(<<<'PHP'
<?php
namespace App\Exceptions;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
class Handler extends ExceptionHandler {
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
        'credit_card_number',
        'cvv',
    ];
}
PHP);

        $migrator = new ExceptionHandlerMigrator();

        ob_start();
        $result = $migrator->migrate($this->tmpDir);
        ob_end_clean();

        $this->assertTrue($result->success);
        // Default ones should be filtered out; custom ones remain
        $this->assertContains('credit_card_number', $result->dontFlash);
        $this->assertContains('cvv', $result->dontFlash);
        $this->assertNotContains('password', $result->dontFlash);
        $this->assertNotContains('current_password', $result->dontFlash);
    }

    public function test_extracts_custom_dont_report(): void
    {
        $this->writeHandler(<<<'PHP'
<?php
namespace App\Exceptions;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
class Handler extends ExceptionHandler {
    protected $dontReport = [
        \Illuminate\Auth\AuthenticationException::class,
        \App\Exceptions\BusinessRuleException::class,
    ];
}
PHP);

        $migrator = new ExceptionHandlerMigrator();

        ob_start();
        $result = $migrator->migrate($this->tmpDir);
        ob_end_clean();

        $this->assertTrue($result->success);
        // AuthenticationException is L11 default — remove
        $this->assertNotContains('Illuminate\\Auth\\AuthenticationException', $result->dontReport);
        $this->assertContains('App\\Exceptions\\BusinessRuleException', $result->dontReport);
    }

    public function test_detects_third_party_reporting_and_backs_up(): void
    {
        $this->writeHandler(<<<'PHP'
<?php
namespace App\Exceptions;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;
class Handler extends ExceptionHandler {
    public function report(Throwable $e): void {
        \Sentry\Laravel\Integration::captureUnhandledException($e);
        parent::report($e);
    }
}
PHP);

        $migrator = new ExceptionHandlerMigrator();

        ob_start();
        $result = $migrator->migrate($this->tmpDir);
        ob_end_clean();

        $this->assertTrue($result->success);
        $this->assertCount(1, $result->backupFiles);
        $warningItems = array_filter($result->manualReviewItems, fn($i) => $i->severity === 'warning');
        $this->assertNotEmpty($warningItems);
    }

    public function test_detects_should_report_override(): void
    {
        $this->writeHandler(<<<'PHP'
<?php
namespace App\Exceptions;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;
class Handler extends ExceptionHandler {
    public function shouldReport(Throwable $e): bool {
        return !($e instanceof \App\Exceptions\IgnoredException);
    }
}
PHP);

        $migrator = new ExceptionHandlerMigrator();

        ob_start();
        $result = $migrator->migrate($this->tmpDir);
        ob_end_clean();

        $errorItems = array_filter($result->manualReviewItems, fn($i) => $i->severity === 'error');
        $this->assertNotEmpty($errorItems);
    }

    public function test_extracts_instancof_render_branches(): void
    {
        $this->writeHandler(<<<'PHP'
<?php
namespace App\Exceptions;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;
use Symfony\Component\HttpFoundation\Response;
class Handler extends ExceptionHandler {
    public function render($request, Throwable $e): Response {
        if ($e instanceof \App\Exceptions\PaymentRequiredException) {
            return response()->json(['error' => 'payment_required'], 402);
        }
        return parent::render($request, $e);
    }
}
PHP);

        $migrator = new ExceptionHandlerMigrator();

        ob_start();
        $result = $migrator->migrate($this->tmpDir);
        ob_end_clean();

        $this->assertTrue($result->success);
        $this->assertCount(1, $result->renderClosures);
        $this->assertStringContainsString('PaymentRequiredException', $result->renderClosures[0]);
    }

    public function test_resolves_short_imported_class_names_in_dont_report(): void
    {
        $this->writeHandler(<<<'PHP'
<?php
namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use App\Exceptions\BusinessRuleException;

class Handler extends ExceptionHandler {
    protected $dontReport = [
        AuthenticationException::class,
        BusinessRuleException::class,
    ];
}
PHP);

        $migrator = new ExceptionHandlerMigrator();

        ob_start();
        $result = $migrator->migrate($this->tmpDir);
        ob_end_clean();

        $this->assertTrue($result->success);
        // AuthenticationException (short import) should still be recognized as L11 default
        $this->assertNotContains('Illuminate\\Auth\\AuthenticationException', $result->dontReport);
        $this->assertNotContains('AuthenticationException', $result->dontReport);
        // Custom exception should be resolved to its FQCN
        $this->assertContains('App\\Exceptions\\BusinessRuleException', $result->dontReport);
    }

    public function test_failure_result_on_unreadable_file(): void
    {
        $this->writeHandler('<?php this is not valid php {{{{ garbage ');

        $migrator = new ExceptionHandlerMigrator();
        $result   = $migrator->migrate($this->tmpDir);

        $this->assertFalse($result->success);
        $this->assertNotNull($result->errorMessage);
    }

    private function writeHandler(string $code): void
    {
        file_put_contents($this->tmpDir . '/app/Exceptions/Handler.php', $code);
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
