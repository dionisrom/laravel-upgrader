# Dependency Safeguards in Laravel Upgrader

This document describes the dependency safeguard system that ensures no dependencies are removed or replaced without explicit approval, and that code using replaced packages is properly updated.

## Overview

The safeguard system consists of several components:

1. **DependencyChangeAuditor** — Tracks all dependency changes (additions, removals, updates, replacements)
2. **DependencyApprovalWorkflow** — Manages approval requirements for sensitive changes
3. **PackageReplacementValidator** — Validates that code using replaced packages is updated
4. **SafeDependencyUpgrader** — Wraps the standard upgrader with safeguards
5. **SafeLumenComposerManifestMigrator** — Wraps the Lumen migrator with safeguards

## Key Features

### 1. No Silent Removals

Dependencies are never removed without:
- Being tracked in the audit log
- Requiring explicit approval (configurable)
- Generating a clear report of what was removed

### 2. Package Replacement Validation

When a package is replaced (e.g., `facade/ignition` → `spatie/laravel-ignition`):
- The system checks if Rector rules exist for automatic code updates
- If no Rector rules exist, manual code updates are flagged
- The upgrade cannot proceed until code is updated or explicitly approved

### 3. Breaking Changes Integration

The `breaking-changes.json` schema has been extended with:
- `dependency_replacements` — Structured replacement definitions
- `dependency_removals` — Structured removal definitions
- Namespace mappings for code updates
- Rector rule references

## Usage

### Basic Usage

```php
use AppContainer\Composer\SafeDependencyUpgrader;
use AppContainer\Composer\CompatibilityChecker;
use AppContainer\Composer\ConflictResolver;

// Create the inner upgrader
$innerUpgrader = new DependencyUpgrader(
    new CompatibilityChecker(),
    new ConflictResolver(),
);

// Wrap with safeguards
$safeUpgrader = new SafeDependencyUpgrader(
    innerUpgrader: $innerUpgrader,
    compatibilityChecker: new CompatibilityChecker(),
    workspacePath: '/path/to/workspace',
    hop: 'hop-8-to-9',
    ignoreApprovals: false,  // Set true to skip approval checks
    ignoreCodeUpdates: false, // Set true to skip code update validation
);

// Run the upgrade
try {
    $result = $safeUpgrader->upgrade();
} catch (ApprovalRequiredException $e) {
    // Handle approval requirements
    foreach ($e->getRequiredApprovals() as $request) {
        echo $request->toArray();
    }
} catch (CodeUpdateRequiredException $e) {
    // Handle missing code updates
    foreach ($e->getCodeUpdateReports() as $report) {
        echo "Package: {$report['old_package']}\n";
        echo "Usages found: {$report['usages_found']}\n";
        echo "Rector rules available: " . ($report['rector_rules_available'] ? 'yes' : 'no') . "\n";
    }
}
```

### Manual Approval

```php
// Get the approval workflow
$workflow = $safeUpgrader->getApprovalWorkflow();

// Grant approval for a specific token
$workflow->grantApproval('sha256-token-here');

// Or grant multiple approvals at once
$workflow->grantApprovals(['token1', 'token2']);

// Check pending approvals
$pending = $workflow->getPendingApprovals();
```

### Generating Reports

```php
// Generate comprehensive report
$report = $safeUpgrader->generateReport();

// Report structure:
// [
//     'audit' => [
//         'summary' => [...],
//         'removals' => [...],
//         'replacements' => [...],
//         'unhandled_replacements' => [...],
//     ],
//     'approvals' => [
//         'granted_count' => 5,
//         'pending_count' => 2,
//         ...
//     ],
//     'replacements_needing_code_updates' => [...],
// ]
```

## Breaking Changes Schema

### Dependency Replacements

```json
{
  "dependency_replacements": [
    {
      "old_package": "facade/ignition",
      "old_constraint": "^2.0",
      "new_package": "spatie/laravel-ignition",
      "new_constraint": "^1.0",
      "reason": "facade/ignition is deprecated. Spatie Laravel Ignition is the official replacement.",
      "rector_rules": [],
      "code_changes_required": true,
      "namespace_mappings": [
        {
          "old": "Facade\\Ignition",
          "new": "Spatie\\LaravelIgnition"
        }
      ],
      "manual_review_required": true,
      "approval_required": true
    }
  ]
}
```

### Dependency Removals

```json
{
  "dependency_removals": [
    {
      "package": "fruitcake/laravel-cors",
      "constraint": "^2.0|^3.0",
      "reason": "Laravel 9 ships with CORS support built-in via config/cors.php.",
      "replacement": "laravel/framework (built-in)",
      "approval_required": true,
      "safe_if_unused": true,
      "affects_lumen_only": false
    }
  ]
}
```

## Configuration

### Approval File Format

Approvals are stored in `.upgrader/approvals.json`:

```json
{
  "tokens": [
    "sha256-token-1",
    "sha256-token-2"
  ],
  "last_updated": 1704067200
}
```

### Environment Variables

- `UPGRADER_IGNORE_APPROVALS=1` — Skip approval requirements
- `UPGRADER_IGNORE_CODE_UPDATES=1` — Skip code update validation
- `UPGRADER_STRICT_MODE=1` — Require approval for any package with code usages

## CLI Integration

### Approval Commands

```bash
# Approve a specific change
upgrader approve <token>

# Approve all pending changes (use with caution)
upgrader approve-all

# List pending approvals
upgrader approvals --pending

# Show audit report
upgrader audit
```

### Example Workflow

```bash
# Run upgrade (will fail if approvals needed)
upgrader upgrade --from=8 --to=9

# If approvals needed, you'll see:
# Approval required for removal of package 'fruitcake/laravel-cors'
# Reason: Laravel 9 ships with CORS support built-in
# Token: abc123...
# Run 'upgrader approve abc123...' to approve.

# Approve the change
upgrader approve abc123...

# Re-run upgrade
upgrader upgrade --from=8 --to=9
```

## Error Handling

### ApprovalRequiredException

Thrown when a dependency change requires approval but hasn't been granted.

```php
try {
    $safeUpgrader->upgrade();
} catch (ApprovalRequiredException $e) {
    // Get all required approvals
    foreach ($e->getRequiredApprovals() as $request) {
        echo "Package: {$request->package}\n";
        echo "Action: {$request->action}\n";
        echo "Reason: {$request->reason}\n";
        echo "Token: {$request->token}\n";
    }
}
```

### CodeUpdateRequiredException

Thrown when a package replacement requires code updates but none were found.

```php
try {
    $safeUpgrader->upgrade();
} catch (CodeUpdateRequiredException $e) {
    foreach ($e->getCodeUpdateReports() as $report) {
        echo "Package {$report['old_package']} requires code updates:\n";
        echo "Found {$report['usages_found']} usages\n";
        
        if ($report['rector_rules_available']) {
            echo "Rector rules available:\n";
            foreach ($report['rector_rules'] as $rule) {
                echo "  - $rule\n";
            }
        } else {
            echo "Manual updates required:\n";
            foreach ($report['usages'] as $usage) {
                echo "  {$usage['file']}:{$usage['line']}\n";
            }
        }
    }
}
```

## Best Practices

1. **Always review removals** — Even if the system allows automatic removal, review the audit report
2. **Run Rector before approval** — If Rector rules are available, run them before approving
3. **Test after replacement** — Package replacements often have subtle behavioral differences
4. **Keep approvals minimal** — Only approve specific changes, not all pending changes
5. **Document manual updates** — When you manually update code, document what changed

## Integration with CI/CD

### GitHub Actions Example

```yaml
name: Upgrade Check

on:
  pull_request:
    paths:
      - 'composer.json'

jobs:
  upgrade-check:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      
      - name: Run upgrade check
        run: |
          upgrader upgrade --dry-run --from=8 --to=9
      
      - name: Check for required approvals
        run: |
          if upgrader approvals --pending | grep -q "pending"; then
            echo "::error::Pending approvals required"
            upgrader approvals --pending
            exit 1
          fi
```

## Troubleshooting

### "Approval token not found"

The token is generated based on package name, change type, and constraints. If any of these change, you'll need a new token.

### "Code usages found but package was removed"

This means the package is still referenced in your code. Either:
1. Restore the package
2. Update the code to not use the package
3. Grant manual approval if the usages are in dead code

### "Rector rules available but code not updated"

Make sure Rector is configured correctly and the rules are being applied. Check the Rector output for errors.

## Future Enhancements

1. **Interactive approval mode** — Prompt for approval during upgrade
2. **Automatic Rector execution** — Run available Rector rules automatically
3. **Usage impact analysis** — More sophisticated code usage detection
4. **Package deprecation warnings** — Warn about packages scheduled for removal
5. **Approval delegation** — Support for team-based approval workflows
