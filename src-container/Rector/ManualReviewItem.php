<?php

declare(strict_types=1);

namespace AppContainer\Rector;

final readonly class ManualReviewItem
{
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        /** @var 'magic_method'|'macro'|'macroable_trait'|'dynamic_instantiation'|'dynamic_call' */
        public readonly string $pattern,
        public readonly string $detail,
    ) {
    }
}
