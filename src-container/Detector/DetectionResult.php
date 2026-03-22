<?php

declare(strict_types=1);

namespace AppContainer\Detector;

final readonly class DetectionResult
{
    public function __construct(
        public string $framework,
        public string $laravelVersion,
        public string $phpConstraint,
        public int $totalFiles,
        public int $phpFiles,
        public int $configFiles,
        public int $routeFiles,
        public int $migrationFiles,
        public int $viewFiles,
    ) {
    }
}
