<?php

declare(strict_types=1);

namespace App\Orchestrator;

interface EventConsumerInterface
{
    /**
     * @param array<string, mixed> $event
     */
    public function consume(array $event): void;
}
