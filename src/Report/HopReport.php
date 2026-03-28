<?php

declare(strict_types=1);

namespace App\Report;

/**
 * Immutable report data for a single hop, produced by {@see ChainEventAggregator}.
 */
final readonly class HopReport
{
    /**
     * @param list<array<string, mixed>> $events All events emitted during this hop.
     */
    public function __construct(
        public string $fromVersion,
        public string $toVersion,
        public string $hopKey,
        public array $events,
        public int $eventCount,
    ) {}
}
