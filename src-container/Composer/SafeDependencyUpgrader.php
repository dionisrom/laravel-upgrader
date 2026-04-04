<?php

declare(strict_types=1);

namespace AppContainer\Composer;

use AppContainer\Composer\Exception\ApprovalRequiredException;
use AppContainer\Composer\Exception\CodeUpdateRequiredException;
use AppContainer\Composer\Exception\DependencyBlockerException;

/**
 * SafeDependencyUpgrader — Enhanced DependencyUpgrader with dependency safeguards.
 *
 * This class wraps the standard DependencyUpgrader and adds:
 * 1. Tracking of all dependency changes
 * 2. Approval requirements for removals and replacements
 * 3. Validation that code using replaced packages is updated
 * 4. Comprehensive audit logging
 */
final class SafeDependencyUpgrader
{
    private DependencyChangeAuditor $auditor;
    private DependencyApprovalWorkflow $approvalWorkflow;
    private PackageReplacementValidator $replacementValidator;

    public function __construct(
        private readonly DependencyUpgrader $innerUpgrader,
        private readonly CompatibilityChecker $compatibilityChecker,
        private readonly string $workspacePath,
        private readonly ?string $hop = null,
        private readonly bool $ignoreApprovals = false,
        private readonly bool $ignoreCodeUpdates = false,
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
     * Perform a safe upgrade with all safeguards enabled.
     *
     * @throws ApprovalRequiredException if approval is required but not granted
     * @throws CodeUpdateRequiredException if code updates are required but not completed
     * @throws DependencyBlockerException if critical blockers exist
     */
    public function upgrade(bool $ignoreBlockers = false): UpgradeResult
    {
        $composerJsonPath = $this->workspacePath . '/composer.json';

        // 1. Read current state
        $composerData = $this->readComposerJson($composerJsonPath);
        $originalRequire = $composerData['require'] ?? [];
        $originalRequireDev = $composerData['require-dev'] ?? [];

        // 2. Load breaking changes to understand planned replacements
        $plannedReplacements = $this->loadPlannedReplacements();
        $plannedRemovals = $this->loadPlannedRemovals();

        // 3. Check for removals that need approval
        $this->validatePlannedRemovals($originalRequire, $originalRequireDev, $plannedRemovals);

        // 4. Check for replacements that need code updates
        $this->validatePlannedReplacements($plannedReplacements);

        // 5. Run the actual upgrade
        $result = $this->innerUpgrader->upgrade($this->workspacePath, $ignoreBlockers);

        // 6. Read updated state and track changes
        $updatedComposerData = $this->readComposerJson($composerJsonPath);
        $this->trackChanges($originalRequire, $originalRequireDev, $updatedComposerData);

        // 7. Validate that all changes are accounted for
        if (!$this->ignoreApprovals) {
            $this->approvalWorkflow->validateAllApprovals($this->auditor->getChanges());
        }

        // 8. Validate code updates for replacements
        if (!$this->ignoreCodeUpdates) {
            $this->auditor->validateReplacementsHaveCodeUpdates();
        }

        // 9. Generate and emit audit report
        $auditReport = $this->auditor->generateAuditReport();
        $this->emit('dependency.audit_report', $auditReport);

        return $result;
    }

    /**
     * Get the dependency change auditor for inspection.
     */
    public function getAuditor(): DependencyChangeAuditor
    {
        return $this->auditor;
    }

    /**
     * Get the approval workflow for manual approval operations.
     */
    public function getApprovalWorkflow(): DependencyApprovalWorkflow
    {
        return $this->approvalWorkflow;
    }

    /**
     * Get the replacement validator for code update checks.
     */
    public function getReplacementValidator(): PackageReplacementValidator
    {
        return $this->replacementValidator;
    }

    /**
     * Generate a comprehensive report of all dependency changes and required actions.
     *
     * @return array<string, mixed>
     */
    public function generateReport(): array
    {
        return [
            'audit' => $this->auditor->generateAuditReport(),
            'approvals' => $this->approvalWorkflow->generateApprovalReport(),
            'replacements_needing_code_updates' => array_map(
                static fn($r) => $r->toArray(),
                $this->auditor->getReplacementsNeedingCodeUpdates(),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readComposerJson(string $path): array
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("composer.json not found at: {$path}");
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Could not read composer.json at: {$path}");
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON in composer.json: ' . json_last_error_msg());
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('composer.json root must be a JSON object.');
        }

        return $decoded;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadPlannedReplacements(): array
    {
        if ($this->hop === null) {
            return [];
        }

        $breakingChangesPath = "/docs/breaking-changes.json";
        if (!file_exists($breakingChangesPath)) {
            return [];
        }

        $content = file_get_contents($breakingChangesPath);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['dependency_replacements'])) {
            return [];
        }

        /** @var list<array<string, mixed>> $replacements */
        $replacements = $data['dependency_replacements'];
        return $replacements;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadPlannedRemovals(): array
    {
        if ($this->hop === null) {
            return [];
        }

        $breakingChangesPath = "/docs/breaking-changes.json";
        if (!file_exists($breakingChangesPath)) {
            return [];
        }

        $content = file_get_contents($breakingChangesPath);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['dependency_removals'])) {
            return [];
        }

        /** @var list<array<string, mixed>> $removals */
        $removals = $data['dependency_removals'];
        return $removals;
    }

    /**
     * @param array<string, string> $originalRequire
     * @param array<string, string> $originalRequireDev
     * @param list<array<string, mixed>> $plannedRemovals
     */
    private function validatePlannedRemovals(
        array $originalRequire,
        array $originalRequireDev,
        array $plannedRemovals,
    ): void {
        if ($this->ignoreApprovals) {
            return;
        }

        foreach ($plannedRemovals as $removal) {
            $package = $removal['package'] ?? '';
            $approvalRequired = $removal['approval_required'] ?? true;

            if (!$approvalRequired) {
                continue;
            }

            // Check if this package is actually present
            if (!isset($originalRequire[$package]) && !isset($originalRequireDev[$package])) {
                continue;
            }

            // Record the planned removal
            $change = $this->auditor->recordChange(
                package: $package,
                oldConstraint: $originalRequire[$package] ?? $originalRequireDev[$package] ?? null,
                newConstraint: null,
                type: ChangeType::REMOVAL,
                reason: $removal['reason'] ?? "Package {$package} scheduled for removal",
            );

            // Check if approved
            if (!$this->approvalWorkflow->isApproved($change)) {
                $this->approvalWorkflow->requireApproval($change);
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $plannedReplacements
     */
    private function validatePlannedReplacements(array $plannedReplacements): void
    {
        if ($this->ignoreCodeUpdates) {
            return;
        }

        foreach ($plannedReplacements as $replacement) {
            $oldPackage = $replacement['old_package'] ?? '';
            $newPackage = $replacement['new_package'] ?? null;
            $codeChangesRequired = $replacement['code_changes_required'] ?? false;
            $rectorRules = $replacement['rector_rules'] ?? [];

            if (!$codeChangesRequired) {
                continue;
            }

            // Create a replacement record
            $depReplacement = new DependencyReplacement(
                oldPackage: $oldPackage,
                newPackage: $newPackage,
                oldConstraint: $replacement['old_constraint'] ?? null,
                newConstraint: $replacement['new_constraint'] ?? null,
                rectorRulesAvailable: !empty($rectorRules),
            );

            // Check if code updates are complete
            if (!$this->replacementValidator->isCodeUpdateComplete($depReplacement)) {
                $report = $this->replacementValidator->generateCodeUpdateReport($depReplacement);
                throw CodeUpdateRequiredException::fromUnhandledReplacements(
                    [$depReplacement],
                    [$report],
                );
            }
        }
    }

    /**
     * @param array<string, string> $originalRequire
     * @param array<string, string> $originalRequireDev
     * @param array<string, mixed> $updatedComposerData
     */
    private function trackChanges(
        array $originalRequire,
        array $originalRequireDev,
        array $updatedComposerData,
    ): void {
        $updatedRequire = $updatedComposerData['require'] ?? [];
        $updatedRequireDev = $updatedComposerData['require-dev'] ?? [];

        // Track removals from require
        foreach ($originalRequire as $package => $constraint) {
            if (!isset($updatedRequire[$package])) {
                $this->auditor->recordChange(
                    package: $package,
                    oldConstraint: $constraint,
                    newConstraint: null,
                    type: ChangeType::REMOVAL,
                    reason: "Package {$package} removed during upgrade",
                );
            }
        }

        // Track removals from require-dev
        foreach ($originalRequireDev as $package => $constraint) {
            if (!isset($updatedRequireDev[$package])) {
                $this->auditor->recordChange(
                    package: $package,
                    oldConstraint: $constraint,
                    newConstraint: null,
                    type: ChangeType::REMOVAL,
                    reason: "Package {$package} removed from require-dev during upgrade",
                );
            }
        }

        // Track additions and updates
        foreach ($updatedRequire as $package => $constraint) {
            if (!isset($originalRequire[$package])) {
                $this->auditor->recordChange(
                    package: $package,
                    oldConstraint: null,
                    newConstraint: $constraint,
                    type: ChangeType::ADDITION,
                    reason: "Package {$package} added during upgrade",
                );
            } elseif ($originalRequire[$package] !== $constraint) {
                $this->auditor->recordChange(
                    package: $package,
                    oldConstraint: $originalRequire[$package],
                    newConstraint: $constraint,
                    type: ChangeType::UPDATE,
                    reason: "Package {$package} updated from {$originalRequire[$package]} to {$constraint}",
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function emit(string $type, array $data): void
    {
        echo json_encode(['type' => $type, 'data' => $data], JSON_UNESCAPED_SLASHES) . "\n";
    }
}
