<?php

declare(strict_types=1);

namespace Tests\Unit\Report;

use App\Report\ChainEventAggregator;
use App\Report\ChainReport;
use App\Report\HopReport;
use PHPUnit\Framework\TestCase;

final class ChainEventAggregatorTest extends TestCase
{
    private ChainEventAggregator $aggregator;

    protected function setUp(): void
    {
        $this->aggregator = new ChainEventAggregator();
    }

    // -------------------------------------------------------------------------
    // Return type and structure
    // -------------------------------------------------------------------------

    public function testAggregateReturnsChainReportInstance(): void
    {
        $report = $this->aggregator->aggregate('chain-1', '8', '13', []);

        self::assertInstanceOf(ChainReport::class, $report);
    }

    public function testAggregatePreservesChainMetadata(): void
    {
        $report = $this->aggregator->aggregate('my-chain-id', '9', '12', []);

        self::assertSame('my-chain-id', $report->chainId);
        self::assertSame('9', $report->sourceVersion);
        self::assertSame('12', $report->targetVersion);
    }

    // -------------------------------------------------------------------------
    // Empty streams
    // -------------------------------------------------------------------------

    public function testAggregateEmptyStreamsProducesEmptyReport(): void
    {
        $report = $this->aggregator->aggregate('chain-1', '8', '9', []);

        self::assertSame([], $report->hopReports);
        self::assertSame([], $report->allEvents);
        self::assertSame(0, $report->totalEvents);
    }

    // -------------------------------------------------------------------------
    // Single hop
    // -------------------------------------------------------------------------

    public function testAggregateSingleHopProducesOneHopReport(): void
    {
        $events = [
            ['event' => 'stage_start', 'ts' => 1001],
            ['event' => 'pipeline_complete', 'passed' => true, 'ts' => 1002],
        ];

        $report = $this->aggregator->aggregate('chain-1', '8', '9', ['8->9' => $events]);

        self::assertCount(1, $report->hopReports);

        $hopReport = $report->hopReports[0];
        self::assertInstanceOf(HopReport::class, $hopReport);
        self::assertSame('8', $hopReport->fromVersion);
        self::assertSame('9', $hopReport->toVersion);
        self::assertSame('8->9', $hopReport->hopKey);
        self::assertCount(2, $hopReport->events);
        self::assertSame(2, $hopReport->eventCount);
    }

    public function testAggregateSingleHopAnnotatesEventsWithHopKey(): void
    {
        $events = [
            ['event' => 'stage_start', 'ts' => 1001],
        ];

        $report = $this->aggregator->aggregate('chain-1', '8', '9', ['8->9' => $events]);

        self::assertCount(1, $report->allEvents);
        self::assertSame('8->9', $report->allEvents[0]['_hop'] ?? null);
    }

    // -------------------------------------------------------------------------
    // Multiple hops
    // -------------------------------------------------------------------------

    public function testAggregateMultipleHopsProducesCorrectHopReportCount(): void
    {
        $streams = [
            '8->9'   => [['event' => 'pipeline_complete', 'passed' => true, 'ts' => 1]],
            '9->10'  => [['event' => 'pipeline_complete', 'passed' => true, 'ts' => 2]],
            '10->11' => [['event' => 'pipeline_complete', 'passed' => true, 'ts' => 3]],
        ];

        $report = $this->aggregator->aggregate('chain-1', '8', '11', $streams);

        self::assertCount(3, $report->hopReports);
    }

    public function testAggregateMergesAllEventsInOrder(): void
    {
        $streams = [
            '8->9'  => [
                ['event' => 'a', 'ts' => 1],
                ['event' => 'b', 'ts' => 2],
            ],
            '9->10' => [
                ['event' => 'c', 'ts' => 3],
            ],
        ];

        $report = $this->aggregator->aggregate('chain-1', '8', '10', $streams);

        self::assertSame(3, $report->totalEvents);
        self::assertSame(3, count($report->allEvents));

        // Events from 8->9 come first, then 9->10
        self::assertSame('a', $report->allEvents[0]['event'] ?? null);
        self::assertSame('b', $report->allEvents[1]['event'] ?? null);
        self::assertSame('c', $report->allEvents[2]['event'] ?? null);
    }

    public function testAggregateAnnotatesEachEventWithCorrectHopKey(): void
    {
        $streams = [
            '8->9'  => [['event' => 'x', 'ts' => 1]],
            '9->10' => [['event' => 'y', 'ts' => 2]],
        ];

        $report = $this->aggregator->aggregate('chain-1', '8', '10', $streams);

        self::assertSame('8->9', $report->allEvents[0]['_hop'] ?? null);
        self::assertSame('9->10', $report->allEvents[1]['_hop'] ?? null);
    }

    public function testAggregateTotalEventCountMatchesSumOfAllHopEvents(): void
    {
        $streams = [
            '8->9'   => array_fill(0, 3, ['event' => 'x', 'ts' => 1]),
            '9->10'  => array_fill(0, 5, ['event' => 'y', 'ts' => 2]),
            '10->11' => array_fill(0, 2, ['event' => 'z', 'ts' => 3]),
        ];

        $report = $this->aggregator->aggregate('chain-1', '8', '11', $streams);

        self::assertSame(10, $report->totalEvents);
    }

    // -------------------------------------------------------------------------
    // HopReport version parsing from hop key
    // -------------------------------------------------------------------------

    public function testAggregateHopReportVersionsParsedFromKey(): void
    {
        $streams = ['10->13' => [['event' => 'x', 'ts' => 0]]];

        $report = $this->aggregator->aggregate('chain-1', '10', '13', $streams);

        self::assertSame('10', $report->hopReports[0]->fromVersion);
        self::assertSame('13', $report->hopReports[0]->toVersion);
    }

    public function testAggregateHopReportPreservesOriginalHopKey(): void
    {
        $streams = ['12->13' => [['event' => 'hop_complete', 'ts' => 0]]];

        $report = $this->aggregator->aggregate('chain-1', '12', '13', $streams);

        self::assertSame('12->13', $report->hopReports[0]->hopKey);
    }

    // -------------------------------------------------------------------------
    // Original hop events are not mutated
    // -------------------------------------------------------------------------

    public function testOriginalHopEventsAreNotMutatedByAnnotation(): void
    {
        $originalEvent = ['event' => 'x', 'ts' => 1];
        $streams       = ['8->9' => [$originalEvent]];

        $this->aggregator->aggregate('chain-1', '8', '9', $streams);

        // The original array must be unchanged (no _hop key leaked in).
        self::assertArrayNotHasKey('_hop', $originalEvent);
    }

    // -------------------------------------------------------------------------
    // Full L8->L13 aggregation smoke test
    // -------------------------------------------------------------------------

    public function testAggregateFullL8ToL13Chain(): void
    {
        $streams = [];

        foreach (['8->9', '9->10', '10->11', '11->12', '12->13'] as $key) {
            $streams[$key] = [
                ['event' => 'stage_start',       'ts'     => 1],
                ['event' => 'pipeline_complete',  'passed' => true, 'ts' => 2],
            ];
        }

        $report = $this->aggregator->aggregate('full-chain', '8', '13', $streams);

        self::assertSame('full-chain', $report->chainId);
        self::assertSame('8', $report->sourceVersion);
        self::assertSame('13', $report->targetVersion);
        self::assertCount(5, $report->hopReports);
        self::assertSame(10, $report->totalEvents);
    }
}
