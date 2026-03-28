<?php

declare(strict_types=1);

namespace Tests\Unit\Lumen;

use AppContainer\Lumen\LumenAuditReport;
use AppContainer\Lumen\LumenManualReviewItem;
use PHPUnit\Framework\TestCase;

final class LumenAuditReportTest extends TestCase
{
    public function testGeneratesAuditWithCategoriesAndSeverities(): void
    {
        $report = new LumenAuditReport();

        $report->addItems([
            LumenManualReviewItem::route('routes/web.php', 10, 'Unrecognised method'),
            LumenManualReviewItem::provider('bootstrap/app.php', 5, 'Constructor args'),
            LumenManualReviewItem::config('bootstrap/app.php', 20, 'Missing config'),
        ]);

        ob_start();
        $result = $report->generate('/workspace', ['routes_migrated' => 5]);
        $output = (string) ob_get_clean();

        self::assertSame(3, $result->totalManualReviewItems);
        self::assertSame(0, $result->errorCount);
        self::assertSame(2, $result->warningCount); // route + provider
        self::assertSame(1, $result->infoCount);     // config
        self::assertArrayHasKey('routes_migrated', $result->summary);

        // Verify JSON-ND event
        $event = json_decode(trim($output), true);
        self::assertSame('lumen_audit', $event['event']);
        self::assertSame(3, $event['total_items']);
    }

    public function testEmptyReportGeneratesZeroCounts(): void
    {
        $report = new LumenAuditReport();

        ob_start();
        $result = $report->generate('/workspace');
        ob_end_clean();

        self::assertSame(0, $result->totalManualReviewItems);
        self::assertSame(0, $result->errorCount);
    }

    public function testAddSingleItem(): void
    {
        $report = new LumenAuditReport();
        $report->addItem(LumenManualReviewItem::other('file.php', 1, 'test', 'error'));

        ob_start();
        $result = $report->generate('/workspace');
        ob_end_clean();

        self::assertSame(1, $result->totalManualReviewItems);
        self::assertSame(1, $result->errorCount);
    }
}
