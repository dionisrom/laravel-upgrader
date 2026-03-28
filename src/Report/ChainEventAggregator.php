<?php

declare(strict_types=1);

namespace App\Report;

/**
 * Merges per-hop JSON-ND event streams from a multi-hop chain run into a
 * single unified {@see ChainReport}.
 *
 * Each event in the unified stream is annotated with a "_hop" key containing
 * the hop key (e.g. "8->9") so consumers can filter by hop if needed.
 */
final class ChainEventAggregator
{
    /**
     * Aggregate event streams from all hops into a unified {@see ChainReport}.
     *
     * @param array<string, list<array<string, mixed>>> $hopStreams
     *   Ordered map of hop key (e.g. "8->9") to its list of decoded JSON-ND event arrays.
     *   Iteration order determines the hop order in the report.
     */
    public function aggregate(
        string $chainId,
        string $sourceVersion,
        string $targetVersion,
        array $hopStreams,
    ): ChainReport {
        $hopReports = [];
        $allEvents  = [];

        foreach ($hopStreams as $hopKey => $events) {
            [$from, $to] = $this->parseHopKey((string) $hopKey);

            $hopReports[] = new HopReport(
                fromVersion: $from,
                toVersion:   $to,
                hopKey:      (string) $hopKey,
                events:      $events,
                eventCount:  count($events),
            );

            foreach ($events as $event) {
                $allEvents[] = array_merge($event, ['_hop' => $hopKey]);
            }
        }

        return new ChainReport(
            chainId:       $chainId,
            sourceVersion: $sourceVersion,
            targetVersion: $targetVersion,
            hopReports:    $hopReports,
            allEvents:     $allEvents,
            totalEvents:   count($allEvents),
        );
    }

    /**
     * @return array{string, string}
     */
    private function parseHopKey(string $hopKey): array
    {
        $parts = explode('->', $hopKey, 2);

        if (count($parts) !== 2) {
            return [$hopKey, ''];
        }

        return [$parts[0], $parts[1]];
    }
}
