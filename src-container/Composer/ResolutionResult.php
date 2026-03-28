<?php

declare(strict_types=1);

namespace AppContainer\Composer;

final class ResolutionResult
{
    /**
     * @param DependencyBlocker[] $applied
     * @param DependencyBlocker[] $bypassed
     */
    public function __construct(
        public readonly array $applied,
        public readonly array $bypassed,
    ) {}
}
