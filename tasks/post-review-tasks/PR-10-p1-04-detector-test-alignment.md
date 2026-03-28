# PR-10: P1-04 detector test alignment

**Phase:** Post-Review  
**Priority:** High  
**Estimated Effort:** 0.5 day  
**Dependencies:** PR-09  
**Blocks:** Confidence that P1-04 remains compliant

---

## Agent Persona

**Role:** PHP Quality Assurance Engineer  
**Agent File:** `agents/php-qa-engineer.agent.md`  
**Domain Knowledge Required:**
- PHPUnit regression design
- Composer fixture modeling
- Requirement-driven test coverage

---

## Objective

Strengthen the P1-04 detector tests so they prove the actual task contract instead of passing with an incomplete Lumen package check and an incomplete fixture matrix.

---

## Context from Review

### Source Findings

- The current detector tests do not cover `laravel/lumen-framework` declared under `require-dev`, so the TRD-LUMEN-001 bug is not caught.
- The current VersionDetector tests do not satisfy the P1-04 acceptance requirement for fixture-based Laravel 8, Laravel 9, Lumen 8, and Lumen 9 lock-file coverage.
- No direct LumenDetector regression test exists to assert the emitted metadata for ambiguous detection.

### Requirement Links

- P1-04 acceptance criteria for fixture `composer.lock` files covering Laravel 8, 9, Lumen 8, and 9
- TRD-LUMEN-001 dual-check and ambiguous-warning behavior

---

## Files Likely Touched

| File | Why |
|---|---|
| `tests/Unit/Detector/FrameworkDetectorTest.php` | Add `require-dev` coverage |
| `tests/Unit/Detector/VersionDetectorTest.php` | Add missing Lumen fixture coverage |
| `tests/Unit/Lumen/LumenDetectorTest.php` | Add metadata regression coverage for ambiguous detection |
| `tests/Fixtures/` | Add missing Lumen 9 lock fixture |

---

## Acceptance Criteria

- [ ] Tests cover Lumen package detection via `require-dev`
- [ ] Tests cover fixture-based version detection for Laravel 8, Laravel 9, Lumen 8, and Lumen 9
- [ ] Tests cover `LumenDetector` ambiguous metadata when only the package half of the dual check is satisfied
- [ ] The new tests fail against the pre-fix implementation

---

## Implementation Notes

- Prefer small deterministic fixtures over large copied lock files
- Assert observable outputs, especially emitted JSON payload fields, not only return values