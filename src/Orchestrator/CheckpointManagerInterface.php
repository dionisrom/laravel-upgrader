<?php

declare(strict_types=1);

namespace App\Orchestrator;

interface CheckpointManagerInterface
{
    public function isCompleted(Hop $hop): bool;

    public function markCompleted(Hop $hop): void;
}
