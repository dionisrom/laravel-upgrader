<?php

declare(strict_types=1);

namespace Tests\Unit\Report;

use AppContainer\Report\Formatters\AuditLogFormatter;
use AppContainer\Report\ReportData;
use PHPUnit\Framework\TestCase;

final class AuditLogFormatterTest extends TestCase
{
    private AuditLogFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new AuditLogFormatter();
    }

    public function testOutputIsJsonNd(): void
    {
        $events = [
            ['event' => 'rector_start', 'hop' => '8-to-9'],
            ['event' => 'rector_end', 'hop' => '8-to-9'],
        ];
        $data   = $this->makeData(auditEvents: $events);
        $output = $this->formatter->format($data);

        $lines = array_filter(explode("\n", $output), fn(string $l) => $l !== '');
        $this->assertCount(2, $lines);

        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            $this->assertNotNull($decoded, "Each line must be valid JSON: {$line}");
        }
    }

    public function testEventsEnrichedWithRunMetadata(): void
    {
        $events = [['event' => 'test_event']];
        $data   = $this->makeData(auditEvents: $events);
        $output = $this->formatter->format($data);

        $decoded = json_decode(trim($output), true);
        $this->assertSame('test-run-001', $decoded['run_id']);
        $this->assertSame('1.0.0', $decoded['host_version']);
        $this->assertSame('abc123', $decoded['repo_sha']);
    }

    public function testSensitiveFieldsStripped(): void
    {
        $events = [
            [
                'event'        => 'test',
                'source_code'  => '<?php echo "secret";',
                'file_contents' => 'secret data',
                'token'        => 'ghp_abc123',
                'access_token' => 'token123',
                'secret'       => 's3cret',
                'password'     => 'p@ss',
                'contents'     => 'file body',
            ],
        ];
        $data   = $this->makeData(auditEvents: $events);
        $output = $this->formatter->format($data);

        $decoded = json_decode(trim($output), true);
        $this->assertArrayNotHasKey('source_code', $decoded);
        $this->assertArrayNotHasKey('file_contents', $decoded);
        $this->assertArrayNotHasKey('token', $decoded);
        $this->assertArrayNotHasKey('access_token', $decoded);
        $this->assertArrayNotHasKey('secret', $decoded);
        $this->assertArrayNotHasKey('password', $decoded);
        $this->assertArrayNotHasKey('contents', $decoded);
        $this->assertSame('test', $decoded['event']);
    }

    public function testEmptyEventsProduceSummaryLine(): void
    {
        $data   = $this->makeData(auditEvents: []);
        $output = $this->formatter->format($data);

        $lines = array_filter(explode("\n", $output), fn(string $l) => $l !== '');
        $this->assertCount(1, $lines);

        $decoded = json_decode($lines[0], true);
        $this->assertSame('report_generated', $decoded['event']);
        $this->assertSame('test-run-001', $decoded['run_id']);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * @param list<array<string, mixed>> $auditEvents
     */
    private function makeData(array $auditEvents = []): ReportData
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
            manualReviewItems:   [],
            dependencyBlockers:  [],
            phpstanRegressions:  [],
            verificationResults: [],
            auditEvents:        $auditEvents,
            hasSyntaxError:      false,
            totalFilesScanned:   10,
            totalFilesChanged:   0,
        );
    }
}
