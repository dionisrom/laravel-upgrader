<?php

declare(strict_types=1);

namespace Tests\Unit\Report;

use AppContainer\Report\Formatters\HtmlFormatter;
use AppContainer\Report\ConfidenceScorer;
use AppContainer\Report\ReportData;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HtmlFormatterTest extends TestCase
{
    private string $stubAssetsDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a temp directory with stub diff2html assets.
        $this->stubAssetsDir = sys_get_temp_dir() . '/html-formatter-test-assets-' . uniqid('', true);
        mkdir($this->stubAssetsDir, 0755, true);
        file_put_contents($this->stubAssetsDir . '/diff2html.min.css', '/* stub css */');
        file_put_contents($this->stubAssetsDir . '/diff2html.min.js',  '/* stub js */');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        @unlink($this->stubAssetsDir . '/diff2html.min.css');
        @unlink($this->stubAssetsDir . '/diff2html.min.js');
        @rmdir($this->stubAssetsDir);
    }

    public function testFormatContainsDiff2HtmlCss(): void
    {
        $formatter = new HtmlFormatter($this->stubAssetsDir, new ConfidenceScorer());
        $output    = $formatter->format($this->makeData());
        $this->assertStringContainsString('/* stub css */', $output);
    }

    public function testFormatContainsDiff2HtmlJs(): void
    {
        $formatter = new HtmlFormatter($this->stubAssetsDir, new ConfidenceScorer());
        $output    = $formatter->format($this->makeData());
        $this->assertStringContainsString('/* stub js */', $output);
    }

    public function testNoCdnLinks(): void
    {
        $formatter = new HtmlFormatter($this->stubAssetsDir, new ConfidenceScorer());
        $output    = $formatter->format($this->makeData());
        $this->assertStringNotContainsString('cdn.jsdelivr.net', $output);
        $this->assertStringNotContainsString('unpkg.com', $output);
        $this->assertStringNotContainsString('cdnjs.cloudflare.com', $output);
    }

    public function testConfidenceBadgePresent(): void
    {
        $formatter = new HtmlFormatter($this->stubAssetsDir, new ConfidenceScorer());
        $output    = $formatter->format($this->makeData());
        $this->assertStringContainsString('Confidence', $output);
        $this->assertMatchesRegularExpression('/\d+%/', $output, 'Score percentage must appear in output');
    }

    public function testMissingCssThrowsRuntimeException(): void
    {
        $emptyDir = sys_get_temp_dir() . '/html-formatter-test-missing-' . uniqid('', true);
        mkdir($emptyDir, 0755, true);

        try {
            $formatter = new HtmlFormatter($emptyDir, new ConfidenceScorer());
            $this->expectException(RuntimeException::class);
            $formatter->format($this->makeData());
        } finally {
            @rmdir($emptyDir);
        }
    }

    public function testFileDiffSectionRendered(): void
    {
        $diffs = [
            ['file' => 'app/Foo.php', 'diff' => "@@ -1,1 +1,1 @@\n-old\n+new", 'rules' => ['BC-001']],
        ];
        $formatter = new HtmlFormatter($this->stubAssetsDir, new ConfidenceScorer());
        $output    = $formatter->format($this->makeData(fileDiffs: $diffs));
        $this->assertStringContainsString('app/Foo.php', $output);
        $this->assertStringContainsString('BC-001', $output);
    }

    public function testPerFileConfidenceBadgeRendered(): void
    {
        $diffs = [
            ['file' => 'app/Clean.php', 'diff' => "@@ -1,1 +1,1 @@\n-old\n+new", 'rules' => ['RULE-1']],
        ];
        $formatter = new HtmlFormatter($this->stubAssetsDir, new ConfidenceScorer());
        $output    = $formatter->format($this->makeData(fileDiffs: $diffs));
        $this->assertStringContainsString('100%', $output);
        $this->assertStringContainsString('High', $output);
    }

    public function testBcPrefixItemRenderedAsBlocker(): void
    {
        $data = new ReportData(
            repoName:            'acme/app',
            fromVersion:         '8',
            toVersion:           '9',
            runId:               'test-run-001',
            repoSha:             'abc123',
            hostVersion:         '1.0.0',
            timestamp:           '2024-01-01T00:00:00Z',
            fileDiffs:           [],
            manualReviewItems:   [
                ['id' => 'BC-001', 'automated' => false, 'reason' => 'Method removed', 'files' => ['app/Foo.php']],
            ],
            dependencyBlockers:  [],
            phpstanRegressions:  [],
            verificationResults: [],
            auditEvents:        [],
            hasSyntaxError:      false,
            totalFilesScanned:   10,
            totalFilesChanged:   1,
        );
        $formatter = new HtmlFormatter($this->stubAssetsDir, new ConfidenceScorer());
        $output    = $formatter->format($data);
        $this->assertStringContainsString('BLOCKER', $output);
        $this->assertStringContainsString('blocker', $output);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * @param list<array{file: string, diff: string, rules: list<string>}> $fileDiffs
     */
    private function makeData(array $fileDiffs = []): ReportData
    {
        return new ReportData(
            repoName:            'acme/app',
            fromVersion:         '8',
            toVersion:           '9',
            runId:               'test-run-001',
            repoSha:             'abc123',
            hostVersion:         '1.0.0',
            timestamp:           '2024-01-01T00:00:00Z',
            fileDiffs:           $fileDiffs,
            manualReviewItems:   [],
            dependencyBlockers:  [],
            phpstanRegressions:  [],
            verificationResults: [],
            auditEvents:         [],
            hasSyntaxError:      false,
            totalFilesScanned:   10,
            totalFilesChanged:   1,
        );
    }
}
