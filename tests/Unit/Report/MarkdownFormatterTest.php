<?php

declare(strict_types=1);

namespace Tests\Unit\Report;

use AppContainer\Report\ConfidenceScorer;
use AppContainer\Report\Formatters\MarkdownFormatter;
use AppContainer\Report\ReportData;
use PHPUnit\Framework\TestCase;

final class MarkdownFormatterTest extends TestCase
{
    private MarkdownFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new MarkdownFormatter(new ConfidenceScorer());
    }

    public function testFormatContainsHeader(): void
    {
        $data   = $this->makeData();
        $output = $this->formatter->format($data);
        $this->assertStringContainsString('# Manual Review Required', $output);
        $this->assertStringContainsString('acme/app', $output);
        $this->assertStringContainsString('L8→L9', $output);
    }

    public function testManualReviewItemsAppear(): void
    {
        $items = [
            ['id' => 'BC-001', 'automated' => false, 'reason' => 'Incompatible method signature', 'files' => ['app/Foo.php']],
        ];
        $data   = $this->makeData(manualReviewItems: $items);
        $output = $this->formatter->format($data);

        $this->assertStringContainsString('BC-001', $output);
        $this->assertStringContainsString('app/Foo.php', $output);
        $this->assertStringContainsString('Incompatible method signature', $output);
    }

    public function testBlockersAppearBeforeWarnings(): void
    {
        $items = [
            ['id' => 'WARN-1',  'automated' => false, 'reason' => 'Soft deprecation', 'files' => ['app/A.php']],
            ['id' => 'BC-001',  'automated' => false, 'reason' => 'Incompatible API', 'files' => ['app/B.php']],
        ];
        $data   = $this->makeData(manualReviewItems: $items);
        $output = $this->formatter->format($data);

        $blockerPos  = strpos($output, 'Blockers');
        $warningPos  = strpos($output, 'Warnings');

        $this->assertNotFalse($blockerPos);
        $this->assertNotFalse($warningPos);
        $this->assertLessThan($warningPos, $blockerPos, 'Blockers section must appear before Warnings');
    }

    public function testEmptyReportGreeting(): void
    {
        $data   = $this->makeData();
        $output = $this->formatter->format($data);
        $this->assertStringContainsString('No manual review required', $output);
    }

    public function testCodeSnippetRenderedWhenPresent(): void
    {
        $items = [
            [
                'id' => 'BC-002',
                'automated' => false,
                'reason' => 'Incompatible call',
                'files' => ['app/Bar.php'],
                'snippet' => '$model->getOriginal()',
            ],
        ];
        $data   = $this->makeData(manualReviewItems: $items);
        $output = $this->formatter->format($data);

        $this->assertStringContainsString('```php', $output);
        $this->assertStringContainsString('$model->getOriginal()', $output);
    }

    public function testNoSnippetBlockWhenSnippetAbsent(): void
    {
        $items = [
            ['id' => 'WARN-1', 'automated' => false, 'reason' => 'Soft deprecation', 'files' => ['app/A.php']],
        ];
        $data   = $this->makeData(manualReviewItems: $items);
        $output = $this->formatter->format($data);

        $this->assertStringNotContainsString('```php', $output);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * @param list<array{id: string, automated: bool, reason: string, files: list<string>}> $manualReviewItems
     */
    private function makeData(array $manualReviewItems = []): ReportData
    {
        return new ReportData(
            repoName:            'acme/app',
            fromVersion:         '8',
            toVersion:           '9',
            runId:               'test-run-001',
            repoSha:             'abc123',
            hostVersion:         '1.0.0',
            timestamp:           '2024-01-01T00:00:00Z',
            fileDiffs:           [],
            manualReviewItems:   $manualReviewItems,
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
