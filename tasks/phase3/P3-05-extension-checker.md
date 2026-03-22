# P3-05: Extension Compatibility Checker

**Phase:** 3  
**Priority:** Must Have  
**Estimated Effort:** 8-10 days  
**Dependencies:** P3-02 (PHP hop containers), P1-04 (Detection/Inventory Scanner)  
**Blocks:** P3-08 (Combined Mode Testing)  

---

## Agent Persona

**Role:** PHP Orchestrator Engineer  
**Agent File:** `agents/php-orchestrator-engineer.agent.md`  
**Domain Knowledge Required:**
- PHP extension ecosystem (PECL, bundled extensions, custom compiled)
- `composer.json` `ext-*` requirement parsing
- Extension PHP version compatibility tracking
- Fail-fast blocker patterns — stop before transforms begin
- TRD §23: Extension Compatibility Architecture

---

## Objective

Build the `ExtensionCompatibilityChecker` that runs at inventory stage (before any transforms). It parses `ext-*` requirements from `composer.json`, checks each against a bundled compatibility matrix, and flags PECL extensions without confirmed target PHP support as **BLOCKER** and custom compiled extensions as **HARD STOP**.

---

## Context from PRD & TRD

### Functional Requirements (PRD §13.2)

| ID | Requirement | Priority |
|---|---|---|
| EC-01 | Parse all `ext-*` requirements from `composer.json` | Must Have |
| EC-02 | Check each extension against bundled PHP version compatibility matrix | Must Have |
| EC-03 | PECL extensions with no confirmed target PHP support → **BLOCKER** | Must Have |
| EC-04 | Custom compiled extensions → **HARD STOP** requiring manual verification | Must Have |
| EC-05 | Extension blockers surfaced in dashboard and CLI before any transform | Must Have |
| EC-06 | Extension compatibility matrix bundled in each PHP hop image (no network) | Must Have |
| EC-07 | `--skip-extension-check` flag bypasses with explicit acknowledgement | Should Have |

### Blocker Behaviour Flow (PRD §13.3)

```
1. ExtensionCompatibilityChecker runs at inventory stage (before transforms)
2. For each ext-* in composer.json:
   a. Check bundled compatibility matrix
   b. PECL extension + no confirmed support → BLOCKER (stops execution)
   c. Custom compiled extension → HARD STOP (stops execution)
   d. Known compatible → passes silently
3. Blockers surfaced with:
   - Extension name and version
   - Last known supported PHP version
   - Link to extension's release notes
   - Suggested action (update / find alternative / confirm manually)
```

### Bundled Matrix Format

```json
{
  "ext-imagick": {
    "type": "pecl",
    "php_support": {"8.0": true, "8.1": true, "8.2": true, "8.3": true, "8.4": "unconfirmed"},
    "notes": "Check pecl.php.net for latest support"
  },
  "ext-redis": {
    "type": "pecl",
    "php_support": {"8.0": true, "8.1": true, "8.2": true, "8.3": true, "8.4": true}
  }
}
```

---

## Files to Create

| File | Location | Purpose |
|---|---|---|
| `ExtensionCompatibilityChecker.php` | `src/Verification/` | Core checker logic |
| `ExtensionCompatibilityMatrix.php` | `src/Verification/` | Matrix loader and query |
| `ExtensionBlocker.php` | `src/Verification/` | Blocker/HardStop value objects |
| `ExtensionReport.php` | `src/Verification/` | Extension check results |
| `extension-compat-matrix.json` | `data/` | Bundled compatibility matrix |
| `ExtensionCompatibilityCheckerTest.php` | `tests/Unit/Verification/` | Checker tests |
| `ExtensionCompatibilityMatrixTest.php` | `tests/Unit/Verification/` | Matrix query tests |

---

## Acceptance Criteria

- [ ] Parses all `ext-*` from `composer.json` correctly
- [ ] PECL extensions without confirmed target PHP support flagged as BLOCKER
- [ ] Custom compiled extensions flagged as HARD STOP
- [ ] Known-compatible extensions pass silently
- [ ] Blocker output includes extension name, last supported version, suggested action
- [ ] `--skip-extension-check` bypasses with explicit acknowledgement
- [ ] Compatibility matrix bundled in each PHP hop image
- [ ] Matrix easily updatable (JSON file, not hardcoded)
- [ ] Dashboard and CLI both surface blockers before transforms
