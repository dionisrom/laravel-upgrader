<?php

declare(strict_types=1);

namespace App\Orchestrator;

/**
 * Immutable value object returned by {@see ChainRunner::run()}.
 */
final readonly class ChainRunResult
{
    /**
     * @param list<Hop>                                 $hops
     * @param array<string, list<array<string, mixed>>> $hopEvents Hop key => event list.
     */
    public function __construct(
        public string $chainId,
        public string $sourceVersion,
        public string $targetVersion,
        public array $hops,
        public array $hopEvents,
        public string $workspacePath,
        public ?string $reportHtmlPath = null,
        public ?string $reportJsonPath = null,
    ) {}
}
