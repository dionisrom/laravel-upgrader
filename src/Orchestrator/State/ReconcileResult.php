<?php

declare(strict_types=1);

namespace App\Orchestrator\State;

final readonly class ReconcileResult
{
    /**
     * @param list<string> $pendingRules     Rules still to run
     * @param list<string> $skippedRules     Rules already completed
     * @param list<string> $modifiedFiles    Files whose hash changed since checkpoint
     */
    public function __construct(
        public array $pendingRules,
        public array $skippedRules,
        public array $modifiedFiles,
        public bool $hasModifiedFiles,
    ) {}
}
