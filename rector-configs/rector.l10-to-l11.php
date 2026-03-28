<?php

declare(strict_types=1);

// ============================================================
// Rector Config: hop-10-to-11
// Laravel 10 → Laravel 11 upgrade rules
//
// Includes:
//   - driftingly/rector-laravel LaravelSetList::LARAVEL_110
//
// Note: The slim skeleton migration (Kernel, Handler, Console)
// is handled by SlimSkeletonGenerator.php — NOT by Rector.
// Rector handles only AST-transformable code patterns here.
// ============================================================

use Rector\Config\RectorConfig;
use RectorLaravel\Set\LaravelSetList;

// Custom gap-fill rules
use AppContainer\Rector\Rules\L10ToL11\RateLimiterMigrationAuditor;

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
    $packageRules = $activator->activate($composerLockPath, '10-to-11');
}

$config = RectorConfig::configure()
    ->withPaths([$workspacePath]);

foreach (upgraderWorkspaceSkipPaths($workspacePath) as $skipPath) {
    $config = $config->withSkipPath($skipPath);
}

return $config

    // ── Upstream rector-laravel L11 set ───────────────────────────────────────
    ->withSets([
        LaravelSetList::LARAVEL_110,
    ])

    // ── Custom gap-fill rules ─────────────────────────────────────────────────
    // Note: PhpVersionGuard is a standalone script (not a Rector rule)
    // and runs as a pre-flight stage in entrypoint.sh
    ->withRules(array_merge([
        RateLimiterMigrationAuditor::class,
    ], $packageRules))

    ->withoutParallel()
    ->withImportNames(importDocBlockNames: false);
