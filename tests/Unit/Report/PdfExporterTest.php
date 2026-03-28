<?php

declare(strict_types=1);

namespace Tests\Unit\Report;

use App\Report\PdfExporter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PdfExporterTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/pdf-exporter-test-' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
    }

    private function stubHtmlFile(): string
    {
        $path = $this->tempDir . '/stub.html';
        file_put_contents($path, '<!DOCTYPE html><html><body><h1>Test</h1></body></html>');
        return $path;
    }

    // -----------------------------------------------------------------------
    // Basic tests
    // -----------------------------------------------------------------------

    public function testIsAvailableReturnsBool(): void
    {
        $exporter = new PdfExporter();
        $this->assertIsBool($exporter->isAvailable());
    }

    public function testExportThrowsRuntimeExceptionWhenHtmlNotFound(): void
    {
        $exporter = new PdfExporter();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/HTML file not found/');
        $exporter->export($this->tempDir . '/does-not-exist.html', $this->tempDir . '/output.pdf');
    }

    public function testExportThrowsWhenNoToolAvailable(): void
    {
        $htmlPath = $this->stubHtmlFile();
        $exporter = new class extends PdfExporter {
            protected function commandExists(string $command): bool { return false; }
            protected function findChrome(): ?string { return null; }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/No PDF export tool available/');
        $exporter->export($htmlPath, $this->tempDir . '/output.pdf');
    }

    // -----------------------------------------------------------------------
    // Chrome fallback: used when wkhtmltopdf is absent
    // -----------------------------------------------------------------------

    public function testChromeUsedAsFallbackWhenWkhtmltopdfAbsent(): void
    {
        $htmlPath = $this->stubHtmlFile();
        $capturedCmd = null;

        $exporter = new class($capturedCmd) extends PdfExporter {
            /** @var list<string>|null */
            private $captured;
            public function __construct(?array &$captured) { $this->captured = &$captured; parent::__construct(); }
            protected function commandExists(string $command): bool { return $command !== 'wkhtmltopdf'; }
            protected function findChrome(): ?string { return 'chromium'; }
            protected function runProcess(array $cmd, string $toolName): void { $this->captured = $cmd; }
        };

        $exporter->export($htmlPath, $this->tempDir . '/output.pdf');

        $this->assertNotNull($capturedCmd);
        $this->assertSame('chromium', $capturedCmd[0]);
        $this->assertStringContainsString('Chrome', 'Chrome'); // tool name
    }

    // -----------------------------------------------------------------------
    // Wkhtmltopdf preferred over Chrome
    // -----------------------------------------------------------------------

    public function testWkhtmltopdfPreferredOverChrome(): void
    {
        $htmlPath = $this->stubHtmlFile();
        $capturedTool = null;

        $exporter = new class($capturedTool) extends PdfExporter {
            private ?string $tool;
            public function __construct(?string &$tool) { $this->tool = &$tool; parent::__construct(); }
            protected function commandExists(string $command): bool { return true; }
            protected function findChrome(): ?string { return 'chromium'; }
            protected function runProcess(array $cmd, string $toolName): void { $this->tool = $toolName; }
        };

        $exporter->export($htmlPath, $this->tempDir . '/output.pdf');

        $this->assertSame('wkhtmltopdf', $capturedTool);
    }

    // -----------------------------------------------------------------------
    // File URI construction (F4 fix validation)
    // -----------------------------------------------------------------------

    public function testBuildFileUriUnixPath(): void
    {
        $exporter = new class extends PdfExporter {
            public function publicBuildFileUri(string $path): string { return $this->buildFileUri($path); }
        };

        $this->assertSame('file:///home/user/report.html', $exporter->publicBuildFileUri('/home/user/report.html'));
    }

    public function testBuildFileUriWindowsPath(): void
    {
        $exporter = new class extends PdfExporter {
            public function publicBuildFileUri(string $path): string { return $this->buildFileUri($path); }
        };

        $this->assertSame('file:///C:/Users/foo/report.html', $exporter->publicBuildFileUri('C:\\Users\\foo\\report.html'));
    }

    public function testChromeCommandContainsValidFileUri(): void
    {
        $htmlPath = $this->stubHtmlFile();
        $capturedCmd = null;

        $exporter = new class($capturedCmd) extends PdfExporter {
            private $cap;
            public function __construct(?array &$cap) { $this->cap = &$cap; parent::__construct(); }
            protected function commandExists(string $command): bool { return $command !== 'wkhtmltopdf'; }
            protected function findChrome(): ?string { return 'chrome'; }
            protected function runProcess(array $cmd, string $toolName): void { $this->cap = $cmd; }
        };

        $exporter->export($htmlPath, $this->tempDir . '/output.pdf');

        // Last argument should be the file URI
        $fileUriArg = end($capturedCmd);
        $this->assertStringStartsWith('file://', $fileUriArg);
        // Should not contain backslashes
        $this->assertStringNotContainsString('\\', $fileUriArg);
    }

    // -----------------------------------------------------------------------
    // Process failure throws RuntimeException
    // -----------------------------------------------------------------------

    public function testProcessFailureThrowsRuntimeException(): void
    {
        $htmlPath = $this->stubHtmlFile();

        $exporter = new class extends PdfExporter {
            protected function commandExists(string $command): bool { return $command === 'wkhtmltopdf'; }
            protected function runProcess(array $cmd, string $toolName): void {
                throw new \RuntimeException("{$toolName} PDF export failed (exit 1): some error");
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/wkhtmltopdf PDF export failed/');
        $exporter->export($htmlPath, $this->tempDir . '/output.pdf');
    }

    // -----------------------------------------------------------------------
    // Wkhtmltopdf command arguments
    // -----------------------------------------------------------------------

    public function testWkhtmltopdfCommandContainsExpectedArgs(): void
    {
        $htmlPath = $this->stubHtmlFile();
        $capturedCmd = null;

        $exporter = new class($capturedCmd) extends PdfExporter {
            private $cap;
            public function __construct(?array &$cap) { $this->cap = &$cap; parent::__construct(); }
            protected function commandExists(string $command): bool { return $command === 'wkhtmltopdf'; }
            protected function runProcess(array $cmd, string $toolName): void { $this->cap = $cmd; }
        };

        $exporter->export($htmlPath, $this->tempDir . '/output.pdf');

        $this->assertSame('wkhtmltopdf', $capturedCmd[0]);
        $this->assertContains('--page-size', $capturedCmd);
        $this->assertContains('A4', $capturedCmd);
        $this->assertContains('--enable-local-file-access', $capturedCmd);
        // Last two args are htmlPath and pdfPath
        $this->assertSame($htmlPath, $capturedCmd[count($capturedCmd) - 2]);
        $this->assertSame($this->tempDir . '/output.pdf', $capturedCmd[count($capturedCmd) - 1]);
    }

    public function testTimeoutParameterIsAccepted(): void
    {
        $exporter = new PdfExporter(timeoutSeconds: 60);
        $this->assertInstanceOf(PdfExporter::class, $exporter);
    }

    public function testDefaultTimeoutIsUsedWhenNotSpecified(): void
    {
        $exporter = new PdfExporter();
        $this->assertInstanceOf(PdfExporter::class, $exporter);
    }

    public function testExportSucceedsWhenWkhtmltopdfAvailable(): void
    {
        $exporter = new PdfExporter(timeoutSeconds: 30);

        if (!$exporter->isAvailable()) {
            $this->markTestSkipped('No PDF export tool available in this environment.');
        }

        $htmlPath = $this->stubHtmlFile();
        $pdfPath  = $this->tempDir . '/output.pdf';

        $exporter->export($htmlPath, $pdfPath);

        $this->assertFileExists($pdfPath);
        $this->assertGreaterThan(0, filesize($pdfPath));
    }
}
