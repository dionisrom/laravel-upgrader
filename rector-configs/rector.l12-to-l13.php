<?php

declare(strict_types=1);

// ============================================================
// Rector Config: hop-12-to-13
// Laravel 12 → Laravel 13 upgrade rules
//
// Includes:
//   - driftingly/rector-laravel LaravelSetList::LARAVEL_130
//   - Package-specific rules activated via PackageRuleActivator
// ============================================================

use Rector\Config\RectorConfig;
use RectorLaravel\Set\LaravelSetList;

use AppContainer\Rector\PackageRuleActivator;
use AppContainer\Rector\PackageVersionMatrix;
use AppContainer\Rector\Rules\L12ToL13\DeprecatedApiRemoverRector;

require_once __DIR__ . '/workspace-skip-paths.php';

$workspacePath = getenv('UPGRADER_WORKSPACE') ?: '/repo';

// ── Package-aware rule activation ─────────────────────────────────────────────
$packageRules = [];
$composerLockPath = $workspacePath . '/composer.lock';
$packageRulesConfigDir = getenv('UPGRADER_PACKAGE_RULES_DIR') ?: '/upgrader/docs';

if (is_file($composerLockPath) && is_dir($packageRulesConfigDir)) {
    $activator = new PackageRuleActivator(new PackageVersionMatrix($packageRulesConfigDir));
    $packageRules = $activator->activate($composerLockPath, '12-to-13');
}

$config = RectorConfig::configure()
    ->withPaths([$workspacePath]);

foreach (upgraderWorkspaceSkipPaths($workspacePath) as $skipPath) {
    $config = $config->withSkipPath($skipPath);
}

return $config

    // ── Upstream rector-laravel L13 set ───────────────────────────────────────
    ->withSets([
        LaravelSetList::LARAVEL_130,
    ])

    // ── Custom gap-fill rules ───────────────────────────────────────────────
    // Note: PhpVersionGuard is a standalone script (not a Rector rule)
    // and runs as a pre-flight stage in entrypoint.sh
    ->withRules(array_merge(
        [DeprecatedApiRemoverRector::class],
        $packageRules,
    ))

    ->withParallel()
    ->withImportNames(importDocBlockNames: false);
