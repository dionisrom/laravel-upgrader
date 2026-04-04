<?php

declare(strict_types=1);

namespace AppContainer\Lumen;

use AppContainer\Composer\ChangeType;
use AppContainer\Composer\DependencyApprovalWorkflow;
use AppContainer\Composer\DependencyChangeAuditor;
use AppContainer\Composer\PackageReplacementValidator;

/**
 * SafeLumenComposerManifestMigrator — Enhanced Lumen migration with dependency safeguards.
 *
 * This class wraps the standard LumenComposerManifestMigrator and adds:
 * 1. Tracking of all package removals during Lumen→Laravel migration
 * 2. Approval requirements for non-trivial package removals
 * 3. Validation that code using removed packages is identified
 * 4. Comprehensive audit logging
 */
final class SafeLumenComposerManifestMigrator
{
    private const RESERVED_REQUIRE = [
        'php',
        'ext-json',
        'ext-mbstring',
        'ext-openssl',
        'laravel/framework',
        'laravel/lumen-framework',
    ];

    private DependencyChangeAuditor $auditor;
    private DependencyApprovalWorkflow $approvalWorkflow;
    private PackageReplacementValidator $replacementValidator;

    public function __construct(
        private readonly string $workspacePath,
        private readonly bool $ignoreApprovals = false,
        private readonly bool $strictMode = false,
    ) {
        $this->auditor = new DependencyChangeAuditor(
            approvalTokenFile: $workspacePath . '/.upgrader/dependency-approvals.json',
        );
        $this->auditor->loadApprovalTokens();

        $this->approvalWorkflow = new DependencyApprovalWorkflow(
            workspacePath: $workspacePath,
            approvalsFile: $workspacePath . '/.upgrader/approvals.json',
        );

        $this->replacementValidator = new PackageReplacementValidator(
            workspacePath: $workspacePath,
            packageRulesDir: '/config/package-rules',
        );
    }

    /**
     * Migrate composer.json from Lumen to Laravel with safeguards.
     *
     * @throws \RuntimeException on migration failure
     * @throws \AppContainer\Composer\Exception\ApprovalRequiredException if approval required
     */
    public function migrate(string $sourceWorkspace, string $targetWorkspace): LumenComposerMigrationResult
    {
        $sourcePath = $sourceWorkspace . '/composer.json';
        $targetPath = $targetWorkspace . '/composer.json';

        $source = $this->readComposerJson($sourcePath);
        $target = $this->readComposerJson($targetPath);

        $manualReviewItems = [];
        $removedPackages = [];
        $packagesNeedingApproval = [];

        // Copy basic metadata
        $target['name'] = $source['name'] ?? ($target['name'] ?? 'upgrader/lumen-migrated-app');
        $target['description'] = $source['description'] ?? ($target['description'] ?? 'Migrated from Lumen');
        $target['type'] = $source['type'] ?? ($target['type'] ?? 'project');
        $target['repositories'] = $source['repositories'] ?? ($target['repositories'] ?? []);
        $target['minimum-stability'] = $source['minimum-stability'] ?? ($target['minimum-stability'] ?? 'stable');
        $target['prefer-stable'] = $source['prefer-stable'] ?? ($target['prefer-stable'] ?? true);

        // Merge autoload and other sections
        $target['autoload'] = $this->mergeAssoc(
            $target['autoload'] ?? [],
            $source['autoload'] ?? [],
        );
        $target['autoload-dev'] = $this->mergeAssoc(
            $target['autoload-dev'] ?? [],
            $source['autoload-dev'] ?? [],
        );
        $target['scripts'] = $this->mergeAssoc(
            $target['scripts'] ?? [],
            $source['scripts'] ?? [],
        );
        $target['config'] = $this->mergeAssoc(
            $target['config'] ?? [],
            $source['config'] ?? [],
        );

        $sourceRequire = is_array($source['require'] ?? null) ? $source['require'] : [];
        $sourceRequireDev = is_array($source['require-dev'] ?? null) ? $source['require-dev'] : [];
        $targetRequire = is_array($target['require'] ?? null) ? $target['require'] : [];
        $targetRequireDev = is_array($target['require-dev'] ?? null) ? $target['require-dev'] : [];

        // Preserve PHP version
        if (isset($sourceRequire['php'])) {
            $targetRequire['php'] = $sourceRequire['php'];
        }

        // Process require section with safeguards
        foreach ($sourceRequire as $package => $constraint) {
            if (in_array($package, self::RESERVED_REQUIRE, true)) {
                continue;
            }

            if ($this->shouldSkipLumenSpecificPackage($package)) {
                // Record the removal
                $this->auditor->recordChange(
                    package: $package,
                    oldConstraint: $constraint,
                    newConstraint: null,
                    type: ChangeType::REMOVAL,
                    reason: "Lumen-specific package removed during Lumen→Laravel migration",
                );

                // Check if this package is used in code
                $usages = $this->replacementValidator->findPackageUsages($package);

                if ($usages !== [] && $this->strictMode) {
                    // In strict mode, require approval for packages with usages
                    $packagesNeedingApproval[] = [
                        'package' => $package,
                        'usages' => $usages,
                        'reason' => "Package {$package} has " . count($usages) . " usages in codebase",
                    ];
                }

                $removedPackages[] = $package;
                $manualReviewItems[] = LumenManualReviewItem::other(
                    'composer.json',
                    0,
                    sprintf('Skipped Lumen-specific Composer package %s during migration.', $package),
                    'warning',
                    'Review the package for a Laravel-compatible replacement before deployment.',
                );
                continue;
            }

            $targetRequire[$package] = $constraint;
        }

        // Process require-dev section
        foreach ($sourceRequireDev as $package => $constraint) {
            if ($package === 'laravel/lumen-framework') {
                continue;
            }

            $targetRequireDev[$package] = $constraint;
        }

        // Remove lumen-framework (this is expected)
        unset($targetRequire['laravel/lumen-framework'], $targetRequireDev['laravel/lumen-framework']);

        // Record the framework replacement
        $this->auditor->recordChange(
            package: 'laravel/lumen-framework',
            oldConstraint: $sourceRequire['laravel/lumen-framework'] ?? '^8.0',
            newConstraint: 'laravel/framework',
            type: ChangeType::REPLACEMENT,
            reason: "Migrating from Lumen framework to Laravel framework",
        );

        // Check if approvals are needed
        if (!$this->ignoreApprovals && $packagesNeedingApproval !== []) {
            foreach ($packagesNeedingApproval as $pending) {
                $change = $this->auditor->recordChange(
                    package: $pending['package'],
                    oldConstraint: $sourceRequire[$pending['package']] ?? null,
                    newConstraint: null,
                    type: ChangeType::REMOVAL,
                    reason: $pending['reason'],
                );
                $this->approvalWorkflow->requireApproval($change);
            }
        }

        $target['require'] = $targetRequire;
        $target['require-dev'] = $targetRequireDev;

        $this->writeComposerJson($targetPath, $target);

        $lockPath = $targetWorkspace . '/composer.lock';
        if (file_exists($lockPath)) {
            unlink($lockPath);
        }

        // Generate audit report
        $auditReport = $this->auditor->generateAuditReport();

        echo json_encode([
            'event' => 'lumen_composer_manifest_migrated',
            'ts' => time(),
            'target' => $targetPath,
            'removed_packages' => $removedPackages,
            'audit_report' => $auditReport,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        return new LumenComposerMigrationResult($removedPackages, $manualReviewItems);
    }

    /**
     * Get the dependency change auditor.
     */
    public function getAuditor(): DependencyChangeAuditor
    {
        return $this->auditor;
    }

    /**
     * Get the approval workflow.
     */
    public function getApprovalWorkflow(): DependencyApprovalWorkflow
    {
        return $this->approvalWorkflow;
    }

    /**
     * Generate a comprehensive migration report.
     *
     * @return array<string, mixed>
     */
    public function generateReport(): array
    {
        return [
            'audit' => $this->auditor->generateAuditReport(),
            'approvals' => $this->approvalWorkflow->generateApprovalReport(),
        ];
    }

    private function shouldSkipLumenSpecificPackage(string $package): bool
    {
        $normalized = strtolower($package);

        return $normalized !== 'laravel/lumen-framework'
            && str_contains($normalized, 'lumen');
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overlay
     * @return array<string, mixed>
     */
    private function mergeAssoc(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            if (is_array($value) && is_array($base[$key] ?? null)) {
                $base[$key] = $this->mergeAssoc($base[$key], $value);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    private function readComposerJson(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Failed to read composer manifest: {$path}");
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Invalid composer manifest: {$path}");
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function writeComposerJson(string $path, array $manifest): void
    {
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode migrated composer manifest.');
        }

        if (file_put_contents($path, $json . "\n") === false) {
            throw new \RuntimeException("Failed to write migrated composer manifest: {$path}");
        }
    }
}
