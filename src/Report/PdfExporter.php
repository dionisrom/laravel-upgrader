<?php

declare(strict_types=1);

namespace App\Report;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Exports a self-contained HTML report to PDF.
 *
 * Attempts wkhtmltopdf first; falls back to headless Chromium/Chrome.
 * Both tools must be installed and accessible via PATH on the host system.
 *
 * @see https://wkhtmltopdf.org
 * @see https://developers.google.com/web/updates/2017/04/headless-chrome
 */
class PdfExporter
{
    private const WKHTMLTOPDF_ARGS = [
        '--page-size', 'A4',
        '--orientation', 'Landscape',
        '--margin-top', '10mm',
        '--margin-bottom', '10mm',
        '--margin-left', '10mm',
        '--margin-right', '10mm',
        '--encoding', 'UTF-8',
        '--enable-local-file-access',
        '--disable-javascript',
        '--quiet',
    ];

    private const CHROME_CANDIDATES = [
        'chromium-browser',
        'chromium',
        'google-chrome',
        'google-chrome-stable',
        'chrome',
    ];

    private const CHROME_ARGS = [
        '--headless',
        '--disable-gpu',
        '--no-sandbox',
        '--disable-dev-shm-usage',
        '--run-all-compositor-stages-before-draw',
        '--virtual-time-budget=5000',
    ];

    public function __construct(
        private readonly int $timeoutSeconds = 120,
    ) {}

    /**
     * Export the HTML file at $htmlPath to a PDF at $pdfPath.
     *
     * @throws RuntimeException if neither wkhtmltopdf nor Chrome is available,
     *                          or if the export process fails.
     */
    public function export(string $htmlPath, string $pdfPath): void
    {
        if (!file_exists($htmlPath)) {
            throw new RuntimeException("HTML file not found: {$htmlPath}");
        }

        if ($this->commandExists('wkhtmltopdf')) {
            $this->exportWithWkhtmltopdf($htmlPath, $pdfPath);
            return;
        }

        $chromeBin = $this->findChrome();
        if ($chromeBin !== null) {
            $this->exportWithChrome($chromeBin, $htmlPath, $pdfPath);
            return;
        }

        throw new RuntimeException(
            'No PDF export tool available. Install wkhtmltopdf or Chromium/Chrome.',
        );
    }

    /**
     * Detect whether a PDF tool is available without actually running an export.
     */
    public function isAvailable(): bool
    {
        return $this->commandExists('wkhtmltopdf') || $this->findChrome() !== null;
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    protected function exportWithWkhtmltopdf(string $htmlPath, string $pdfPath): void
    {
        $cmd = array_merge(
            ['wkhtmltopdf'],
            self::WKHTMLTOPDF_ARGS,
            [$htmlPath, $pdfPath],
        );

        $this->runProcess($cmd, 'wkhtmltopdf');
    }

    protected function exportWithChrome(string $chromeBin, string $htmlPath, string $pdfPath): void
    {
        $fileUri = $this->buildFileUri($htmlPath);

        $cmd = array_merge(
            [$chromeBin],
            self::CHROME_ARGS,
            ["--print-to-pdf={$pdfPath}", $fileUri],
        );

        $this->runProcess($cmd, 'Chrome');
    }

    /**
     * Build a valid file:// URI from an absolute filesystem path.
     *
     * On Windows, paths like C:\Users\foo\report.html become file:///C:/Users/foo/report.html.
     * On Unix, /home/foo/report.html becomes file:///home/foo/report.html.
     */
    protected function buildFileUri(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);

        // Absolute Unix paths already start with /
        if (str_starts_with($normalized, '/')) {
            return 'file://' . $normalized;
        }

        // Windows absolute paths like C:/... need an extra /
        return 'file:///' . $normalized;
    }

    /**
     * @param list<string> $cmd
     */
    protected function runProcess(array $cmd, string $toolName): void
    {
        $process = new Process($cmd, timeout: $this->timeoutSeconds);
        $process->run();

        if (!$process->isSuccessful()) {
            $stderr = trim($process->getErrorOutput());
            throw new RuntimeException(
                "{$toolName} PDF export failed (exit {$process->getExitCode()}): {$stderr}",
            );
        }
    }

    protected function commandExists(string $command): bool
    {
        $checkCmd = PHP_OS_FAMILY === 'Windows'
            ? new Process(['where', $command])
            : new Process(['which', $command]);

        $checkCmd->run();
        return $checkCmd->isSuccessful();
    }

    protected function findChrome(): ?string
    {
        foreach (self::CHROME_CANDIDATES as $candidate) {
            if ($this->commandExists($candidate)) {
                return $candidate;
            }
        }
        return null;
    }
}
