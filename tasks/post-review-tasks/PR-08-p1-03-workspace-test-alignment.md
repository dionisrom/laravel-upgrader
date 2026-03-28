# PR-08: P1-03 Workspace Test Alignment

**Phase:** Post-Review  
**Priority:** High  
**Estimated Effort:** 1 day  
**Dependencies:** PR-06, PR-07  
**Blocks:** Confidence in P1-03 remaining green

---

## Agent Persona

**Role:** PHP Quality Assurance Engineer  
**Agent File:** `agents/php-qa-engineer.agent.md`  
**Domain Knowledge Required:**
- PHPUnit regression design
- Cross-platform filesystem test design
- Checkpoint and event-driven workflow testing

---

## Objective

Strengthen the P1-03 test suite so it proves the actual workspace requirements instead of only exercising the happy path and separator-normalization smoke cases.

---

## Context from Review

### Source Findings

- The current P1-03 tests do not cover the WSL2 translation requirement from F-09.
- The current tests do not cover applying a diff for a new file in a missing directory.
- The current tests do not verify that checkpoint state is written per file or that checkpoint failures halt processing.

### Requirement Links

- P1-03 acceptance criteria for WSL2 normalization and per-file checkpoint updates
- TRD-RECTOR-006 and TRD-RECTOR-007 for checkpoint and failure behavior

---

## Files Likely Touched

| File | Why |
|---|---|
| `tests/Unit/Workspace/WorkspaceManagerTest.php` | Add missing requirement-driven regression coverage |

---

## Acceptance Criteria

- [ ] Tests cover WSL2 translation semantics without depending on the host machine running Windows
- [ ] Tests cover successful creation of new nested files via `applyDiffs()`
- [ ] Tests cover checkpoint content after a successful file application
- [ ] Tests cover failure behavior when checkpoint persistence fails
- [ ] The new tests would fail against the pre-fix P1-03 implementation

---

## Implementation Notes

- Prefer deterministic unit tests over environment-dependent integration tests
- Assert observable behavior: written files, checkpoint contents, and emitted error outcomes
- Keep the tests fast and compatible with the existing PHPUnit suite