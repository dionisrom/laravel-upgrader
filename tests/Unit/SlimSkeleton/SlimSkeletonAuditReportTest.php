<?php

declare(strict_types=1);

namespace Tests\Unit\SlimSkeleton;

use AppContainer\SlimSkeleton\SlimSkeletonAuditReport;
use AppContainer\SlimSkeleton\SlimSkeletonManualReviewItem;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AppContainer\SlimSkeleton\SlimSkeletonAuditReport
 */
final class SlimSkeletonAuditReportTest extends TestCase
{
    public function test_generate_emits_slim_skeleton_audit_event(): void
    {
        $report = new SlimSkeletonAuditReport();

        ob_start();
        $result = $report->generate('/workspace/app');
        $output = ob_get_clean() ?: '';

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame('slim_skeleton_audit', $decoded['event']);
        $this->assertSame(0, $decoded['total_items']);
    }

    public function test_counts_by_severity(): void
    {
        $report = new SlimSkeletonAuditReport();

        $report->addItem(SlimSkeletonManualReviewItem::kernel('/f', 0, 'error item', 'error'));
        $report->addItem(SlimSkeletonManualReviewItem::kernel('/f', 0, 'warning item', 'warning'));
        $report->addItem(SlimSkeletonManualReviewItem::kernel('/f', 0, 'info item', 'info'));

        ob_start();
        $result = $report->generate('/workspace');
        ob_end_clean();

        $this->assertSame(3, $result->totalManualReviewItems);
        $this->assertSame(1, $result->errorCount);
        $this->assertSame(1, $result->warningCount);
        $this->assertSame(1, $result->infoCount);
    }

    public function test_add_items_array(): void
    {
        $report = new SlimSkeletonAuditReport();

        $items = [
            SlimSkeletonManualReviewItem::exceptionHandler('/f', 1, 'desc1'),
            SlimSkeletonManualReviewItem::providers('/f', 2, 'desc2'),
        ];

        $report->addItems($items);

        ob_start();
        $result = $report->generate('/workspace');
        ob_end_clean();

        $this->assertSame(2, $result->totalManualReviewItems);
    }

    public function test_summary_contains_workspace(): void
    {
        $report = new SlimSkeletonAuditReport();

        ob_start();
        $result = $report->generate('/my/workspace', ['extra' => 'value']);
        ob_end_clean();

        $this->assertSame('/my/workspace', $result->summary['workspace']);
        $this->assertSame('value', $result->summary['extra']);
    }
}
