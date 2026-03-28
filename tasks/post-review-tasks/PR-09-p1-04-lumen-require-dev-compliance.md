# PR-09: P1-04 Lumen require-dev compliance

**Phase:** Post-Review  
**Priority:** High  
**Estimated Effort:** 0.5 day  
**Dependencies:** None  
**Blocks:** Confidence in Lumen auto-detection for P1-04 and P1-14

---

## Agent Persona

**Role:** Laravel Migration Specialist  
**Agent File:** `agents/laravel-migration-specialist.agent.md`  
**Domain Knowledge Required:**
- Composer manifest semantics
- Lumen detection rules
- JSON-ND warning and detection event behavior

---

## Objective

Bring the P1-04 Lumen detection flow into compliance with TRD-LUMEN-001 by honoring `laravel/lumen-framework` in either `require` or `require-dev`, and keep ambiguous detection metadata consistent with that rule.

---

## Context from Review

### Source Findings

- `FrameworkDetector::detect()` only checks `composer.json.require.laravel/lumen-framework`, so a workspace that declares the package in `require-dev` is misclassified.
- `LumenDetector::buildAmbiguousResult()` repeats the same incomplete package check, so even if framework detection is corrected, the emitted `lumen_detection` metadata can still report `has_package=false` incorrectly.

### Requirement Links

- TRD-LUMEN-001: detect `laravel/lumen-framework` in `composer.json` require or require-dev, plus the bootstrap pattern
- P1-04 acceptance criteria for dual-check Lumen detection and `lumen_ambiguous` warning behavior

---

## Files Likely Touched

| File | Why |
|---|---|
| `src-container/Detector/FrameworkDetector.php` | Expand package detection to `require-dev` |
| `src-container/Lumen/LumenDetector.php` | Keep ambiguous result metadata aligned with the same rule |

---

## Acceptance Criteria

- [ ] Lumen detection treats `laravel/lumen-framework` in `require` or `require-dev` as satisfying the package half of the dual check
- [ ] A workspace with `require-dev` plus the bootstrap pattern is detected as definitive Lumen
- [ ] A workspace with only `require-dev` emits `lumen_ambiguous`
- [ ] `LumenDetectionResult` and emitted `lumen_detection` payloads report `has_package=true` when the package is present in `require-dev`

---

## Implementation Notes

- Keep the package-detection logic centralized so the FrameworkDetector and LumenDetector cannot drift again
- Preserve existing JSON-ND output shape