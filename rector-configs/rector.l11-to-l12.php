<?php

declare(strict_types=1);

// ============================================================
// Rector Config: hop-11-to-12
// Laravel 11 → Laravel 12 upgrade rules
//
// Includes:
//   - driftingly/rector-laravel LaravelSetList::LARAVEL_120
//   - Package-specific rules activated via PackageRuleActivator
// ============================================================

use Rector\Config\RectorConfig;
use RectorLaravel\Set\LaravelSetList;

// Custom gap-fill rules

use AppContainer\Rector\PackageRuleActivator;
use AppContainer\Rector\PackageVersionMatrix;

require_once __DIR__ . '/workspace-skip-paths.php';

$workspacePath = getenv('UPGRADER_WORKSPACE') ?: '/repo';

// ── Package-aware rule activation ─────────────────────────────────────────────
$packageRules = [];
$composerLockPath = $workspacePath . '/composer.lock';
$packageRulesConfigDir = getenv('UPGRADER_PACKAGE_RULES_DIR') ?: '/upgrader/docs';

if (is_file($composerLockPath) && is_dir($packageRulesConfigDir)) {
    $activator = new PackageRuleActivator(new PackageVersionMatrix($packageRulesConfigDir));
    $packageRules = $activator->activate($composerLockPath, '11-to-12');
}

$config = RectorConfig::configure()
    ->withPaths([$workspacePath]);

foreach (upgraderWorkspaceSkipPaths($workspacePath) as $skipPath) {
    $config = $config->withSkipPath($skipPath);
}

return $config

    // ── Upstream rector-laravel L12 set ───────────────────────────────────────
    ->withSets([
        LaravelSetList::LARAVEL_120,
    ])

    // ── Custom gap-fill rules (package rules only — L12 has minimal breaking changes) ──
    ->withRules($packageRules)

    ->withoutParallel()
    ->withImportNames(importDocBlockNames: false);
