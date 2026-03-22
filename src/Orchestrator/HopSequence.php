<?php

declare(strict_types=1);

namespace App\Orchestrator;

final readonly class HopSequence
{
    /**
     * @param list<Hop> $hops
     */
    public function __construct(public array $hops) {}
}
