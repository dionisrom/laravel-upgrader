<?php

declare(strict_types=1);

// ============================================================
// Package Rector Config: spatie/laravel-medialibrary V9 → V10
// Hop: 9-to-10
// ============================================================

use AppContainer\Rector\Rules\Package\Spatie\HasMediaTraitRector;
use Rector\Config\RectorConfig;

require_once __DIR__ . '/../workspace-skip-paths.php';

$config = RectorConfig::configure()
    ->withPaths(['/workspace']);

foreach (upgraderWorkspaceSkipPaths('/workspace') as $skipPath) {
    $config = $config->withSkipPath($skipPath);
}

return $config
    ->withRules([
        HasMediaTraitRector::class,
    ]);
