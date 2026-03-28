# PR-07: P1-03 applyDiffs Checkpoint and New-File Compliance

**Phase:** Post-Review  
**Priority:** Critical  
**Estimated Effort:** 1-2 days  
**Dependencies:** P1-03 (Workspace Manager)  
**Blocks:** Re-acceptance of P1-03

---

## Agent Persona

**Role:** PHP Orchestrator Engineer  
**Agent File:** `agents/php-orchestrator-engineer.agent.md`  
**Domain Knowledge Required:**
- Atomic file writes in PHP
- Unified diff application semantics
- Transform checkpoint persistence and resume safety
- JSON-ND event emission contracts

---

## Objective

Bring `WorkspaceManager::applyDiffs()` into compliance with the TRD by ensuring it can create new nested files safely and update `TransformCheckpoint` after each successful file application.

---

## Context from Review

### Source Findings

- `applyDiffs()` writes the temporary file before creating the parent directory, so diffs for new files in new directories can fail before the required workspace path exists.
- `WorkspaceManager` has no checkpoint dependency or `TransformCheckpoint` delegation, so it cannot satisfy the per-file checkpoint update requirement.
- Because checkpoint updates do not exist, the implementation also cannot guarantee the TRD requirement that failures leave the checkpoint in the last valid resumable state.

### Requirement Links

- TRD-RECTOR-006: update `TransformCheckpoint` with applied rules and new file hash after each file
- TRD-RECTOR-007: stop on write failure, emit `pipeline_error`, and leave checkpoint in the last valid state
- P1-03 acceptance criteria: `applyDiffs()` writes new content for each `FileDiff`, validates with `php -l`, and updates checkpoint per file

---

## Files Likely Touched

| File | Why |
|---|---|
| `src/Workspace/WorkspaceManager.php` | Fix parent-directory creation order and add checkpoint delegation |
| `src/Orchestrator/State/TransformCheckpoint.php` | Only if a small collaboration seam or compatibility adjustment is needed |
| `tests/Unit/Workspace/WorkspaceManagerTest.php` | Add regression tests for new-file diff application and checkpoint writes |

---

## Acceptance Criteria

- [ ] `applyDiffs()` can create a new file inside a previously missing nested directory
- [ ] `applyDiffs()` updates `TransformCheckpoint` after each successfully written file
- [ ] Stored checkpoint data includes the applied rules and the new file hash using relative paths
- [ ] If checkpoint persistence fails, processing halts and emits `pipeline_error`
- [ ] The previous checkpoint remains the last valid resumable state on failure

---

## Implementation Notes

- Preserve the current atomic temp-write then rename behavior
- Keep checkpoint writes atomic by delegating to `TransformCheckpoint`
- Prefer minimal API expansion over broad orchestrator changes