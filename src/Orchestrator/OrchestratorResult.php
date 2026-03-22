<?php

declare(strict_types=1);

namespace App\Orchestrator;

final readonly class OrchestratorResult
{
    /**
     * @param list<Hop>                          $hops
     * @param list<array<string, mixed>>         $events
     */
    public function __construct(
        public bool $success,
        public string $runId,
        public array $hops,
        public array $events = [],
    ) {}
}
