<?php

declare(strict_types=1);

namespace AppContainer\Detector;

final class DetectionResult
{
    public function __construct(
        public readonly string $framework,
        public readonly string $laravelVersion,
        public readonly string $phpConstraint,
        public readonly int $totalFiles,
        public readonly int $phpFiles,
        public readonly int $configFiles,
        public readonly int $routeFiles,
        public readonly int $migrationFiles,
        public readonly int $viewFiles,
    ) {
    }
}
