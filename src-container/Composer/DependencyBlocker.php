<?php

declare(strict_types=1);

namespace AppContainer\Composer;

final readonly class DependencyBlocker
{
    public function __construct(
        public readonly string $package,
        public readonly string $severity,
        public readonly string $reason,
        public readonly string|null $recommendedVersion,
    ) {}
}
