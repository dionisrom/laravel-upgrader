<?php

declare(strict_types=1);

namespace Tests\Unit\Report;

use AppContainer\Report\Formatters\JsonFormatter;
use AppContainer\Report\ConfidenceScorer;
use AppContainer\Report\ReportData;
use PHPUnit\Framework\TestCase;

final class JsonFormatterTest extends TestCase
{
    private JsonFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new JsonFormatter(new ConfidenceScorer());
    }

    public function testFormatProducesValidJson(): void
    {
        $data   = $this->makeData();
        $output = $this->formatter->format($data);
        $decoded = json_decode($output, true);
        $this->assertNotNull($decoded, 'Output must be valid JSON');
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
    }

    public function testFormatContainsRequiredTopLevelKeys(): void
    {
        $data    = $this->makeData();
        $output  = $this->formatter->format($data);
        $decoded = json_decode($output, true);

        $required = ['schema_version', 'run_id', 'repo', 'upgrade', 'confidence', 'summary',
                     'manual_review_items', 'dependency_blockers', 'verification_results'];

        foreach ($required as $key) {
            $this->assertArrayHasKey($key, $decoded, "Missing key: {$key}");
        }
    }

    public function testSchemaVersionIsOne(): void
    {
        $data    = $this->makeData();
        $output  = $this->formatter->format($data);
        $decoded = json_decode($output, true);
        $this->assertSame('1', $decoded['schema_version']);
    }

    public function testManualReviewItemsDoNotContainCodeSnippets(): void
    {
        $items = [
            ['id' => 'BC-001', 'automated' => false, 'reason' => 'Incompatible signature', 'files' => ['app/Foo.php']],
        ];
        $data    = $this->makeData(manualReviewItems: $items);
        $output  = $this->formatter->format($data);
        $decoded = json_decode($output, true);

        // Each manual review item should only have: id, reason, files, severity.
        foreach ($decoded['manual_review_items'] as $item) {
            $this->assertArrayHasKey('id', $item);
            $this->assertArrayHasKey('reason', $item);
            $this->assertArrayHasKey('files', $item);
            $this->assertArrayHasKey('severity', $item);
            $this->assertArrayNotHasKey('automated', $item, 'Automated flag must be stripped');
        }
    }

    public function testFilesAreRelativePaths(): void
    {
        $items = [
            ['id' => 'RULE-1', 'automated' => false, 'reason' => 'Needs review', 'files' => ['app/Foo.php']],
        ];
        $data    = $this->makeData(manualReviewItems: $items);
        $output  = $this->formatter->format($data);
        $decoded = json_decode($output, true);

        foreach ($decoded['manual_review_items'] as $item) {
            foreach ($item['files'] as $file) {
                // Absolute paths start with / or drive letters like C:
                $this->assertStringNotContainsString(':\\', $file, 'Must be relative path');
                $this->assertStringNotContainsString('/home/', $file, 'Must be relative path');
            }
        }
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
            totalFilesChanged:   2,
        );
    }
}
