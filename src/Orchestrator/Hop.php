<?php

declare(strict_types=1);

namespace App\Orchestrator;

final readonly class Hop
{
    public function __construct(
        public string $dockerImage,
        public string $fromVersion,
        public string $toVersion,
        public string $type,
        public ?string $phpBase,
    ) {}
}
