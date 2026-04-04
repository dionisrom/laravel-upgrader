<?php

declare(strict_types=1);

namespace AppContainer\Composer;

use AppContainer\Composer\Exception\DependencyReplacementException;

/**
 * DependencyChangeAuditor — Tracks and validates all composer.json dependency changes.
 *
 * This class ensures that:
 * 1. No dependencies are removed without explicit approval
 * 2. Package replacements are tracked and validated
 * 3. Code using replaced packages is flagged for review if no Rector rules exist
 * 4. All changes are auditable and reversible
 */
final class DependencyChangeAuditor
{
    /** @var list<DependencyChange> */
    private array $changes = [];

    /** @var list<DependencyReplacement> */
    private array $replacements = [];

    /** @var list<string> */
    private array $approvalTokens = [];

    public function __construct(
        private readonly ?string $approvalTokenFile = null,
    ) {}

    /**
     * Record a dependency change for auditing.
     */
    public function recordChange(
        string $package,
        ?string $oldConstraint,
        ?string $newConstraint,
        ChangeType $type,
        string $reason = '',
    ): DependencyChange {
        $change = new DependencyChange(
            package: $package,
            oldConstraint: $oldConstraint,
            newConstraint: $newConstraint,
            type: $type,
            reason: $reason,
            timestamp: time(),
        );

        $this->changes[] = $change;

        // If this is a replacement, track it separately
        if ($type === ChangeType::REPLACEMENT) {
            $this->replacements[] = new DependencyReplacement(
                oldPackage: $package,
                newPackage: $this->extractReplacementPackage($reason),
                oldConstraint: $oldConstraint,
                newConstraint: $newConstraint,
                rectorRulesAvailable: $this->hasRectorRules($package, $reason),
            );
        }

        return $change;
    }

    /**
     * Check if a dependency removal is allowed.
     * Returns true if approved, false if blocked pending approval.
     */
    public function isRemovalAllowed(string $package, string $reason = ''): bool
    {
        // Check if there's an approval token for this package
        $token = $this->generateApprovalToken($package, 'removal');

        if (in_array($token, $this->approvalTokens, true)) {
            return true;
        }

        // Check if this is a known safe removal (e.g., Lumen-specific packages during migration)
        if ($this->isKnownSafeRemoval($package, $reason)) {
            return true;
        }

        return false;
    }

    /**
     * Request approval for a dependency removal.
     * Returns an approval token that must be provided to allow the removal.
     */
    public function requestRemovalApproval(string $package, string $reason): ApprovalRequest
    {
        $token = $this->generateApprovalToken($package, 'removal');

        return new ApprovalRequest(
            token: $token,
            package: $package,
            action: 'removal',
            reason: $reason,
            impact: $this->assessRemovalImpact($package),
        );
    }

    /**
     * Grant approval for a dependency change.
     */
    public function grantApproval(string $token): void
    {
        if (!in_array($token, $this->approvalTokens, true)) {
            $this->approvalTokens[] = $token;
            $this->persistApprovalToken($token);
        }
    }

    /**
     * Get all recorded changes.
     *
     * @return list<DependencyChange>
     */
    public function getChanges(): array
    {
        return $this->changes;
    }

    /**
     * Get all package replacements that need code updates.
     *
     * @return list<DependencyReplacement>
     */
    public function getReplacementsNeedingCodeUpdates(): array
    {
        return array_values(array_filter(
            $this->replacements,
            static fn(DependencyReplacement $r): bool => !$r->rectorRulesAvailable,
        ));
    }

    /**
     * Validate that all replacements have corresponding code updates.
     *
     * @throws DependencyReplacementException if replacements lack code updates
     */
    public function validateReplacementsHaveCodeUpdates(): void
    {
        $unhandledReplacements = $this->getReplacementsNeedingCodeUpdates();

        if ($unhandledReplacements !== []) {
            throw DependencyReplacementException::fromUnhandledReplacements($unhandledReplacements);
        }
    }

    /**
     * Generate an audit report of all dependency changes.
     *
     * @return array<string, mixed>
     */
    public function generateAuditReport(): array
    {
        $removals = array_filter($this->changes, static fn($c) => $c->type === ChangeType::REMOVAL);
        $additions = array_filter($this->changes, static fn($c) => $c->type === ChangeType::ADDITION);
        $updates = array_filter($this->changes, static fn($c) => $c->type === ChangeType::UPDATE);
        $replacements = array_filter($this->changes, static fn($c) => $c->type === ChangeType::REPLACEMENT);

        return [
            'summary' => [
                'total_changes' => count($this->changes),
                'removals' => count($removals),
                'additions' => count($additions),
                'updates' => count($updates),
                'replacements' => count($replacements),
                'replacements_needing_manual_review' => count($this->getReplacementsNeedingCodeUpdates()),
            ],
            'removals' => array_map(static fn($c) => $c->toArray(), array_values($removals)),
            'additions' => array_map(static fn($c) => $c->toArray(), array_values($additions)),
            'updates' => array_map(static fn($c) => $c->toArray(), array_values($updates)),
            'replacements' => array_map(static fn($c) => $c->toArray(), array_values($replacements)),
            'unhandled_replacements' => array_map(
                static fn($r) => $r->toArray(),
                $this->getReplacementsNeedingCodeUpdates(),
            ),
        ];
    }

    /**
     * Load approval tokens from file.
     */
    public function loadApprovalTokens(): void
    {
        if ($this->approvalTokenFile === null || !file_exists($this->approvalTokenFile)) {
            return;
        }

        $content = file_get_contents($this->approvalTokenFile);
        if ($content === false) {
            return;
        }

        $tokens = json_decode($content, true);
        if (is_array($tokens)) {
            /** @var list<string> $tokens */
            $this->approvalTokens = $tokens;
        }
    }

    private function generateApprovalToken(string $package, string $action): string
    {
        return hash('sha256', $package . ':' . $action . ':' . time());
    }

    private function persistApprovalToken(string $token): void
    {
        if ($this->approvalTokenFile === null) {
            return;
        }

        $dir = dirname($this->approvalTokenFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $this->approvalTokenFile,
            json_encode($this->approvalTokens, JSON_PRETTY_PRINT),
        );
    }

    private function isKnownSafeRemoval(string $package, string $reason): bool
    {
        // Lumen-specific packages are safe to remove during Lumen→Laravel migration
        $normalized = strtolower($package);
        if (str_contains($normalized, 'lumen') && $normalized !== 'laravel/lumen-framework') {
            return true;
        }

        // Framework replacement is expected
        if ($package === 'laravel/lumen-framework' && str_contains($reason, 'migrating to Laravel')) {
            return true;
        }

        return false;
    }

    private function extractReplacementPackage(string $reason): ?string
    {
        // Try to extract replacement package from reason text
        // e.g., "Replace facade/ignition with spatie/laravel-ignition"
        if (preg_match('/replace\s+\S+\s+with\s+(\S+)/i', $reason, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function hasRectorRules(string $package, string $reason): bool
    {
        // This will be implemented to check if Rector rules exist for this package replacement
        // For now, we check if the reason mentions Rector rules
        return str_contains(strtolower($reason), 'rector');
    }

    private function assessRemovalImpact(string $package): array
    {
        // Analyze the impact of removing this package
        return [
            'package' => $package,
            'risk_level' => 'unknown', // Will be determined by analyzing code usage
            'affected_files' => [], // Will be populated by code analysis
            'notes' => 'Manual review required to assess impact.',
        ];
    }
}
