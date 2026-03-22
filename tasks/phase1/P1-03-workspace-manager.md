# P1-03: Workspace Manager

**Phase:** 1 — MVP  
**Priority:** Must Have  
**Estimated Effort:** 3-4 days  
**Dependencies:** P1-01 (Project Scaffold)  
**Blocks:** P1-06 (Rector Runner), P1-08 (State/Checkpoint), P1-19 (CLI Commands)  

---

## Agent Persona

**Role:** PHP Orchestrator Engineer  
**Agent File:** `agents/php-orchestrator-engineer.agent.md`

---

## Objective

Implement the workspace management system that handles creating isolated workspace copies, applying diffs from Rector, path normalization (including WSL2), and ensuring the original repository is never modified during the upgrade process.

---

## Context from PRD & TRD

### Workspace Isolation (TRD §2.3, F-07, F-09)

```php
// Content-addressed workspace ID
$workspaceId = hash('sha256', $repoPath . $targetVersion . microtime(true));
$workspacePath = sys_get_temp_dir() . '/upgrader/' . $workspaceId;
```

### Diff Application (TRD-RECTOR-006)

After `RectorRunner` returns a `RectorResult`, `WorkspaceManager::applyDiffs()` MUST:
1. For each `FileDiff`, write the new file content derived from applying the diff
2. Verify the written file is valid PHP via `php -l` before moving on
3. Update `TransformCheckpoint` with the rule(s) applied and new file hash
4. Emit a `file_changed` JSON-ND event per file

### Error Handling (TRD-RECTOR-007)

If any individual file write fails (disk full, permissions):
1. Do NOT continue to subsequent files
2. Emit a `pipeline_error` event
3. Leave the checkpoint in the last valid state (resumable)

### Write-Back Safety (TRD-ORCH-002)

The orchestrator MUST write-back the transformed workspace to the original repo path ONLY after ALL hops complete with `$passed === true`. Original repo is NEVER modified during execution.

### Windows/WSL2 Support (F-09)

`WorkspaceManager.php` normalises all paths at construction time (POSIX on Linux/macOS, WSL2-translated on Windows).

### Security (TRD-SEC-004)

Workspace directories MUST be created with mode `0700`. Report output directories with mode `0755`.

### Key Classes

```php
namespace App\Workspace;

class WorkspaceManager {
    public function createWorkspace(string $repoPath, string $targetVersion): string;
    public function applyDiffs(string $workspacePath, RectorResult $result): ApplyResult;
    public function writeBack(string $workspacePath, string $originalRepoPath): void;
    public function cleanup(string $workspacePath): void;
}

class DiffGenerator {
    public function generateUnifiedDiff(string $originalContent, string $newContent): string;
}
```

---

## Acceptance Criteria

- [ ] `WorkspaceManager::createWorkspace()` creates content-addressed workspace directory
- [ ] Directory permissions set to `0700`
- [ ] Path normalization works on Linux, macOS, and Windows (WSL2)
- [ ] `applyDiffs()` writes new content for each `FileDiff` from Rector
- [ ] Each written file validated with `php -l` before proceeding
- [ ] File write failure halts further writes and emits error event
- [ ] Checkpoint updated per file (delegates to TransformCheckpoint)
- [ ] `writeBack()` only copies verified workspace to original path
- [ ] `cleanup()` removes temporary workspace directory
- [ ] `DiffGenerator` produces unified diff format
- [ ] Original repo remains untouched during entire operation
- [ ] Unit tests for path normalization across OS variants
