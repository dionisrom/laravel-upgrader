<?php

declare(strict_types=1);

namespace App\Report;

/**
 * Unified report produced by {@see ChainEventAggregator} after aggregating all
 * hop event streams for a complete chain run.
 */
final readonly class ChainReport
{
    /**
     * @param list<HopReport>            $hopReports  Per-hop report sections, in hop order.
     * @param list<array<string, mixed>> $allEvents   All events from all hops merged, each
     *                                                 annotated with a "_hop" key.
     */
    public function __construct(
        public string $chainId,
        public string $sourceVersion,
        public string $targetVersion,
        public array $hopReports,
        public array $allEvents,
        public int $totalEvents,
    ) {}
}
