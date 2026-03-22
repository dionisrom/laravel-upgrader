# P3-06: Silent Change Scanner

**Phase:** 3  
**Priority:** Must Have  
**Estimated Effort:** 8-10 days  
**Dependencies:** P3-02 (PHP hop containers), P1-15 (Verification Pipeline)  
**Blocks:** P3-08 (Combined Mode Testing)  

---

## Agent Persona

**Role:** Rector/AST Transformation Engineer  
**Agent File:** `agents/rector-ast-engineer.agent.md`  
**Domain Knowledge Required:**
- PHP silent behavior changes across 8.0→8.4 (changes invisible to AST but causing runtime failures)
- nikic/php-parser AST traversal for heuristic detection
- PHPStan custom rule authoring for null-to-non-nullable detection
- Dynamic property deprecation lifecycle (deprecated 8.2, error 8.4)
- TRD §24: Silent Change Scanner Architecture

---

## Objective

Build the `PhpSilentChangeScanner` that detects PHP breaking changes invisible to Rector's AST transformations. These are behavior changes that can't be auto-fixed — they must be flagged for human review with `REVIEW` severity.

---

## Context from PRD & TRD

### Functional Requirements (PRD §14.2)

| ID | Requirement | Priority |
|---|---|---|
| SC-01 | Detect null values passed to non-nullable parameters | Must Have |
| SC-02 | Detect dynamic property creation on non-`stdClass` objects | Must Have |
| SC-03 | Detect calls to removed/renamed internal functions per hop | Must Have |
| SC-04 | Detect implicitly nullable parameter declarations (`Type $x = null`) | Must Have |
| SC-05 | All findings flagged as `REVIEW` with code location, PHP doc ref, suggested fix | Must Have |
| SC-06 | Runs as separate stage after PHPStan in PHP hop verification pipeline | Must Have |

### Silent Changes by PHP Hop (PRD §14.3)

| PHP Hop | Key Silent Changes | Detection Method |
|---|---|---|
| 8.0→8.1 | Null passed to non-nullable params | PHPStan custom rule |
| 8.1→8.2 | Dynamic properties on non-stdClass | AST heuristic + `#[AllowDynamicProperties]` check |
| 8.2→8.3 | `utf8_encode()` / `utf8_decode()` removed | Function call scanner |
| 8.3→8.4 | Implicit nullable params (`Type $x = null`) | AST parameter signature scanner |
| 8.4→8.5 | TBD | — |

### Scanner Output Format

Each finding includes:
- File path and line number
- Code snippet (3 lines context)
- PHP documentation reference URL
- Severity: always `REVIEW`
- Suggested fix (human-readable)

---

## Files to Create

| File | Location | Purpose |
|---|---|---|
| `PhpSilentChangeScanner.php` | `src/Verification/` | Main scanner orchestrator |
| `NullToNonNullableDetector.php` | `src/Verification/SilentChange/` | SC-01: null passing detection |
| `DynamicPropertyDetector.php` | `src/Verification/SilentChange/` | SC-02: dynamic property detection |
| `RemovedFunctionDetector.php` | `src/Verification/SilentChange/` | SC-03: removed function calls |
| `ImplicitNullableDetector.php` | `src/Verification/SilentChange/` | SC-04: implicit nullable params |
| `SilentChangeFinding.php` | `src/Verification/SilentChange/` | Finding value object |
| `removed-functions.json` | `data/` | Per-version removed/renamed function registry |
| `PhpSilentChangeScannerTest.php` | `tests/Unit/Verification/` | Scanner orchestrator tests |
| `NullToNonNullableDetectorTest.php` | `tests/Unit/Verification/SilentChange/` | Detector tests |
| `DynamicPropertyDetectorTest.php` | `tests/Unit/Verification/SilentChange/` | Detector tests |
| `RemovedFunctionDetectorTest.php` | `tests/Unit/Verification/SilentChange/` | Detector tests |
| `ImplicitNullableDetectorTest.php` | `tests/Unit/Verification/SilentChange/` | Detector tests |

---

## Acceptance Criteria

- [ ] Null-to-non-nullable passing detected in ≥ 90% of test cases (PRD §17.2.5)
- [ ] Dynamic property creation on non-stdClass flagged
- [ ] `utf8_encode()`/`utf8_decode()` calls detected for 8.2→8.3 hop
- [ ] Implicit nullable parameters detected for 8.3→8.4 hop
- [ ] All findings include file, line, code snippet, PHP doc ref, and suggested fix
- [ ] All findings are `REVIEW` severity (never auto-applied)
- [ ] Scanner integrates into PHP hop verification pipeline (after PHPStan)
- [ ] Per-hop detection — only relevant checks run for each PHP hop
- [ ] False positive rate documented (target: < 10%)
