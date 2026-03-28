<?php

declare(strict_types=1);

namespace AppContainer\Lumen;

final class FacadeBootstrapResult
{
    public function __construct(
        public readonly bool $facadesEnabled,
        public readonly bool $eloquentEnabled,
        public readonly bool $facadeCallFound,
        public readonly bool $eloquentCallFound,
    ) {}

    public static function fromDetection(bool $facadeFound, bool $eloquentFound): self
    {
        return new self(
            facadesEnabled: $facadeFound,
            eloquentEnabled: $eloquentFound,
            facadeCallFound: $facadeFound,
            eloquentCallFound: $eloquentFound,
        );
    }
}
