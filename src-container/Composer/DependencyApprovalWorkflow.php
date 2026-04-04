<?php

declare(strict_types=1);

namespace AppContainer\Composer;

use AppContainer\Composer\Exception\ApprovalRequiredException;

/**
 * DependencyApprovalWorkflow — Manages the approval workflow for dependency changes.
 *
 * This class handles:
 * 1. Detecting when approval is required
 * 2. Generating approval requests
 * 3. Persisting and loading approvals
 * 4. Blocking upgrades until approvals are granted
 * 5. Providing clear information about what needs approval
 */
final class DependencyApprovalWorkflow
{
    /** @var list<ApprovalRequest> */
    private array $pendingApprovals = [];

    /** @var list<string> */
    private array $grantedTokens = [];

    public function __construct(
        private readonly string $workspacePath,
        private readonly ?string $approvalsFile = null,
    ) {
        $this->loadGrantedApprovals();
    }

    /**
     * Check if approval is required for a dependency change.
     */
    public function isApprovalRequired(DependencyChange $change): bool
    {
        // Removals always require approval unless explicitly granted
        if ($change->type === ChangeType::REMOVAL) {
            return !$this->isApproved($change);
        }

        // Replacements require approval if configured
        if ($change->type === ChangeType::REPLACEMENT) {
            return !$this->isApproved($change);
        }

        return false;
    }

    /**
     * Request approval for a dependency change.
     *
     * @throws ApprovalRequiredException if approval is required but not granted
     */
    public function requireApproval(DependencyChange $change): void
    {
        if (!$this->isApprovalRequired($change)) {
            return;
        }

        $request = $this->createApprovalRequest($change);
        $this->pendingApprovals[] = $request;

        throw ApprovalRequiredException::fromRequest($request);
    }

    /**
     * Grant approval for a specific token.
     */
    public function grantApproval(string $token): void
    {
        if (!in_array($token, $this->grantedTokens, true)) {
            $this->grantedTokens[] = $token;
            $this->persistApprovals();
        }
    }

    /**
     * Grant approval for multiple tokens at once.
     *
     * @param list<string> $tokens
     */
    public function grantApprovals(array $tokens): void
    {
        foreach ($tokens as $token) {
            if (!in_array($token, $this->grantedTokens, true)) {
                $this->grantedTokens[] = $token;
            }
        }
        $this->persistApprovals();
    }

    /**
     * Check if a change has been approved.
     */
    public function isApproved(DependencyChange $change): bool
    {
        $token = $this->generateToken($change);
        return in_array($token, $this->grantedTokens, true);
    }

    /**
     * Get all pending approval requests.
     *
     * @return list<ApprovalRequest>
     */
    public function getPendingApprovals(): array
    {
        return $this->pendingApprovals;
    }

    /**
     * Get all granted approval tokens.
     *
     * @return list<string>
     */
    public function getGrantedTokens(): array
    {
        return $this->grantedTokens;
    }

    /**
     * Generate an interactive approval prompt for CLI usage.
     */
    public function generateInteractivePrompt(ApprovalRequest $request): string
    {
        $lines = [
            "",
            "═══════════════════════════════════════════════════════════════",
            "  DEPENDENCY CHANGE REQUIRES APPROVAL",
            "═══════════════════════════════════════════════════════════════",
            "",
            "  Package:    {$request->package}",
            "  Action:     {$request->action}",
            "",
            "  Reason:",
            "    {$request->reason}",
            "",
        ];

        if (!empty($request->impact)) {
            $lines[] = "  Impact Assessment:";
            foreach ($request->impact as $key => $value) {
                if (is_array($value)) {
                    $lines[] = "    {$key}: " . json_encode($value);
                } else {
                    $lines[] = "    {$key}: {$value}";
                }
            }
            $lines[] = "";
        }

        $lines[] = "  Approval Token: {$request->token}";
        $lines[] = "";
        $lines[] = "  To approve this change, run:";
        $lines[] = "    upgrader approve {$request->token}";
        $lines[] = "";
        $lines[] = "  Or add the token to your approvals file:";
        $lines[] = "    echo '\"{$request->token}\"' >> .upgrader/approvals.json";
        $lines[] = "";
        $lines[] = "═══════════════════════════════════════════════════════════════";
        $lines[] = "";

        return implode("\n", $lines);
    }

    /**
     * Generate a summary report of all approvals.
     *
     * @return array<string, mixed>
     */
    public function generateApprovalReport(): array
    {
        return [
            'workspace' => $this->workspacePath,
            'approvals_file' => $this->approvalsFile,
            'granted_count' => count($this->grantedTokens),
            'pending_count' => count($this->pendingApprovals),
            'granted_tokens' => $this->grantedTokens,
            'pending_requests' => array_map(
                static fn(ApprovalRequest $r): array => $r->toArray(),
                $this->pendingApprovals,
            ),
        ];
    }

    /**
     * Validate that all required approvals have been granted.
     *
     * @param list<DependencyChange> $changes
     * @throws ApprovalRequiredException if any approvals are missing
     */
    public function validateAllApprovals(array $changes): void
    {
        $missingApprovals = [];

        foreach ($changes as $change) {
            if ($this->isApprovalRequired($change) && !$this->isApproved($change)) {
                $missingApprovals[] = $this->createApprovalRequest($change);
            }
        }

        if ($missingApprovals !== []) {
            throw ApprovalRequiredException::fromMultipleRequests($missingApprovals);
        }
    }

    private function createApprovalRequest(DependencyChange $change): ApprovalRequest
    {
        $token = $this->generateToken($change);
        $action = match ($change->type) {
            ChangeType::REMOVAL => 'removal',
            ChangeType::REPLACEMENT => 'replacement',
            ChangeType::ADDITION => 'addition',
            ChangeType::UPDATE => 'update',
        };

        $impact = [
            'old_constraint' => $change->oldConstraint,
            'new_constraint' => $change->newConstraint,
            'change_type' => $change->type->value,
        ];

        return new ApprovalRequest(
            token: $token,
            package: $change->package,
            action: $action,
            reason: $change->reason,
            impact: $impact,
        );
    }

    private function generateToken(DependencyChange $change): string
    {
        $data = sprintf(
            '%s:%s:%s:%s',
            $change->package,
            $change->type->value,
            $change->oldConstraint ?? 'null',
            $change->newConstraint ?? 'null',
        );

        return hash('sha256', $data);
    }

    private function loadGrantedApprovals(): void
    {
        $file = $this->getApprovalsFilePath();

        if (!file_exists($file)) {
            return;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return;
        }

        if (isset($data['tokens']) && is_array($data['tokens'])) {
            /** @var list<string> $tokens */
            $tokens = $data['tokens'];
            $this->grantedTokens = $tokens;
        }
    }

    private function persistApprovals(): void
    {
        $file = $this->getApprovalsFilePath();
        $dir = dirname($file);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $data = [
            'tokens' => $this->grantedTokens,
            'last_updated' => time(),
        ];

        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
    }

    private function getApprovalsFilePath(): string
    {
        return $this->approvalsFile ?? $this->workspacePath . '/.upgrader/approvals.json';
    }
}
