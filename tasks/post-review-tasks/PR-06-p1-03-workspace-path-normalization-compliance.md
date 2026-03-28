# PR-06: P1-03 Workspace Path Normalization Compliance

**Phase:** Post-Review  
**Priority:** High  
**Estimated Effort:** 1 day  
**Dependencies:** P1-03 (Workspace Manager)  
**Blocks:** Re-acceptance of P1-03

---

## Agent Persona

**Role:** PHP Orchestrator Engineer  
**Agent File:** `agents/php-orchestrator-engineer.agent.md`  
**Domain Knowledge Required:**
- Cross-platform path normalization in PHP
- Windows and WSL2 filesystem semantics
- Safe test seams for OS-sensitive behavior

---

## Objective

Bring `WorkspaceManager` path handling into compliance with the Phase 1 Windows support decision: path normalization must support WSL2-style translation instead of only replacing path separators.

---

## Context from Review

### Source Findings

- `WorkspaceManager::normalizePath()` only replaces backslashes on Windows and never translates drive-letter paths into WSL2 mount paths.
- The P1-03 tests cover separator normalization but do not prove WSL2 translation behavior.

### Requirement Links

- P1-03 acceptance criteria: path normalization works on Linux, macOS, and Windows (WSL2)
- PRD F-09: `WorkspaceManager.php` normalises all paths at construction time (POSIX on Linux/macOS, WSL2-translated on Windows)

---

## Files Likely Touched

| File | Why |
|---|---|
| `src/Workspace/WorkspaceManager.php` | Add WSL2-capable path normalization and a testable seam |
| `tests/Unit/Workspace/WorkspaceManagerTest.php` | Add regression tests for Windows/WSL2 path normalization expectations |

---

## Acceptance Criteria

- [ ] `WorkspaceManager` supports WSL2-style normalization for Windows drive-letter paths
- [ ] Path normalization remains safe for Linux and macOS paths
- [ ] The normalization behavior is testable without depending on the host OS changing under PHPUnit
- [ ] P1-03 tests fail if the implementation regresses back to separator-only normalization

---

## Implementation Notes

- Fix the behavior in `WorkspaceManager`, not only in the tests
- Prefer a small constructor seam for host path normalization over reflection-heavy tests
- Do not break existing Linux/macOS path handling