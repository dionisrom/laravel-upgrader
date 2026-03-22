# P1-08: Workspace Manager & Diff Generator

**Phase:** 1 — MVP  
**Priority:** Critical  
**Estimated Effort:** 4-5 days  
**Dependencies:** P1-01 (Project Scaffold), P1-06 (Rector Runner — provides FileDiff objects)  
**Blocks:** P1-10 (Orchestrator), P1-16 (Checkpoint System), P1-18 (Report Generator)  

---

## Agent Persona

**Role:** PHP Orchestrator Engineer  
**Agent File:** `agents/php-orchestrator-engineer.agent.md`  
**Domain Knowledge Required:**
- Filesystem operations in PHP (atomic writes, file locking, permissions)
- SHA-256 hashing for content-addressed workspaces
- Advisory locking with `flock()` in PHP
- Path normalisation for cross-platform support (Linux, macOS, WSL2)
- Understanding of Docker bind mount semantics

---

## Objective

Implement `WorkspaceManager.php` (workspace creation, isolation, diff application, write-back) and `DiffGenerator.php` (unified diff generation for reports) in `src/Workspace/`. The WorkspaceManager is the single authority that applies file changes — Rector never writes files directly.

---

## Context from PRD & TRD

### Workspace Isolation (F-07, TRD-REPO-003)

```php
$workspaceId = hash('sha256', $repoPath . $targetVersion . microtime(true));
$workspacePath = sys_get_temp_dir() . '/upgrader/' . $workspaceId;

$lockFile = sys_get_temp_dir() . '/upgrader/locks/' . hash('sha256', $repoPath) . '.lock';
$lock = fopen($lockFile, 'w');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    throw new ConcurrentUpgradeException("Another upgrade is running for this repository.");
}
```

### Diff Application (TRD-RECTOR-006, TRD-RECTOR-007)

After `RectorRunner` returns a `RectorResult`, `WorkspaceManager::applyDiffs()` MUST:
1. For each `FileDiff`, write the new file content
2. Verify written file is valid PHP via `php -l` before moving on
3. Update `TransformCheckpoint` with applied rules and new file hash
4. Emit a `file_changed` JSON-ND event per file

If any file write fails:
1. Do NOT continue to subsequent files
2. Emit a `pipeline_error` event
3. Leave checkpoint in last valid state (resumable)

### Write-Back Safety (TRD-ORCH-002)

The orchestrator MUST write-back the transformed workspace to the original repo path ONLY after ALL hops complete with verification passed. At no point during execution MUST the original repo be modified.

### Windows/WSL2 Support (F-09)

`WorkspaceManager.php` normalises all paths at construction (POSIX on Linux/macOS, WSL2-translated on Windows).

### Security (TRD-SEC-004)

- Workspace directories created with mode `0700`
- Report output directories created with mode `0755`

---

## Files to Create

| File | Location | Purpose |
|---|---|---|
| `WorkspaceManager.php` | `src/Workspace/` | Workspace creation, isolation, diff apply, write-back |
| `DiffGenerator.php` | `src/Workspace/` | Unified diff generation for reports |
| `ConcurrentUpgradeException.php` | `src/Workspace/` | Thrown when lock unavailable |
| `WorkspaceResult.php` | `src/Workspace/` | Value object for workspace operations |

---

## Acceptance Criteria

- [ ] Content-addressed workspace ID uses SHA-256 of `repoPath + targetVersion + microtime()`
- [ ] Advisory `flock(LOCK_EX | LOCK_NB)` prevents concurrent runs on same repo
- [ ] `ConcurrentUpgradeException` thrown with clear message when lock unavailable
- [ ] `applyDiffs()` writes files and verifies each with `php -l` before proceeding
- [ ] File write failure halts processing and leaves checkpoint in valid state
- [ ] `file_changed` JSON-ND event emitted per file
- [ ] Write-back only occurs after full verification passes
- [ ] Workspace directories created with `0700` permissions
- [ ] Path normalisation works on Linux, macOS, and WSL2
- [ ] Original repo is NEVER modified during processing
- [ ] `DiffGenerator` produces unified diff format for report consumption

---

## Implementation Notes

- Workspace is a full copy of the repository — not a symlink
- The workspace copy is what gets mounted into Docker containers
- `applyDiffs()` receives `RectorResult` from P1-06 and applies changes
- Checkpoint integration (P1-16) will call into workspace manager for file hashes
- Write-back is a separate explicit step called by the orchestrator (P1-10)
- Consider using `RecursiveDirectoryIterator` for workspace copy
- `php -l` verification per file uses `symfony/process` for subprocess
