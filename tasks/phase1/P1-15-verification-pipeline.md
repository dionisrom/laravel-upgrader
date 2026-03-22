# P1-15: Verification Pipeline

**Phase:** 1 — MVP  
**Priority:** Critical  
**Estimated Effort:** 6-7 days  
**Dependencies:** P1-01 (Project Scaffold), P1-06 (Rector Runner — for post-transform verification)  
**Blocks:** P1-10 (Orchestrator — uses verification result to gate write-back), P1-18 (Report)  

---

## Agent Persona

**Role:** PHP Quality Assurance Engineer  
**Agent File:** `agents/php-qa-engineer.agent.md`  
**Domain Knowledge Required:**
- PHPStan configuration, baseline management, and parallel execution
- `nikic/php-parser` for static analysis of PHP config and route files
- `php -l` syntax checking and parallel process management
- Composer validation and autoload verification
- Understanding of why artisan boot is unsafe in containers (F-04)
- Laravel config structure (`config/*.php`), route files, and service providers

---

## Objective

Implement the complete verification pipeline: `VerificationPipeline.php` and all five verifiers (`SyntaxVerifier`, `PhpStanVerifier`, `StaticArtisanVerifier`, `ComposerVerifier`, `ClassResolutionVerifier`). Verification is the safety gate — no code writes back to the original repo unless all verifiers pass.

---

## Context from PRD & TRD

### Pipeline Composition (TRD §11.1 — TRD-VERIFY-001)

Execute in this EXACT order. A failing verifier halts the pipeline:

```
1. SyntaxVerifier          (php -l on all PHP files)
2. ComposerVerifier        (composer validate + composer install)
3. ClassResolutionVerifier (all use statements resolve)
4. PhpStanVerifier         (baseline delta; --parallel)
5. StaticArtisanVerifier   (config + route AST parsing; no app boot)
```

### Verifier Interface (TRD-VERIFY-002)

```php
interface VerifierInterface {
    public function verify(string $workspacePath, VerificationContext $ctx): VerifierResult;
}

final readonly class VerifierResult {
    public function __construct(
        public bool   $passed,
        public string $verifierName,
        public int    $issueCount,
        public array  $issues,      // VerificationIssue[]
        public float  $durationSeconds,
    ) {}
}
```

### SyntaxVerifier (TRD-VERIFY-003)

Run `php -l` for every `.php` file in parallel (max 8 concurrent). ANY syntax error = pipeline failure. Zero tolerance.

### PhpStanVerifier (TRD-VERIFY-004, TRD-VERIFY-005, F-12)

```bash
vendor/bin/phpstan analyse {workspacePath} --level=3 --no-progress --error-format=json --parallel --memory-limit=1G
```

Baseline workflow:
1. No baseline exists → run on original code BEFORE transforms, write baseline
2. After transforms → run again, compare error count
3. `post > pre` → `phpstan_regression` event → FAIL
4. Baseline persists across `--resume` runs

### StaticArtisanVerifier (TRD-VERIFY-006, TRD-VERIFY-007, F-04)

Uses `nikic/php-parser` to statically verify:
- **Config validation:** Parse `config/*.php`, ensure returns PHP array literal
- **Route validation:** Parse route files, verify controller class references resolve
- **Provider validation:** For each provider in `config/app.php`, verify `class_exists()`

MUST NOT instantiate any class, boot any container, or execute application code.

### Opt-in Artisan (TRD-VERIFY-008)

`--with-artisan-verify` runs AFTER static verification:
```bash
php artisan config:cache --quiet
php artisan route:list --json > /dev/null
```
Failures are advisory only (warning, not blocking).

### PRD Requirements

| ID | Requirement |
|---|---|
| VP-01 | `php -l` syntax check on every transformed file |
| VP-02 | PHPStan baseline on original code before transforms |
| VP-03 | PHPStan error count must not increase |
| VP-04 | PHPStan with `--parallel`; baseline cached |
| VP-05–07 | Static config, route, provider validation (no app boot) |
| VP-08 | `composer validate` + `composer install` in container |
| VP-09 | All `use` statements resolve to existing classes |
| VP-10 | `--with-artisan-verify` opt-in |
| VP-11 | `--skip-phpstan` with acknowledgement |
| VP-12 | Verification failure blocks write-back to original repo |

---

## Files to Create

| File | Location | Purpose |
|---|---|---|
| `VerificationPipeline.php` | `src-container/Verification/` | Pipeline orchestrator |
| `VerifierInterface.php` | `src-container/Verification/` | Interface for all verifiers |
| `VerifierResult.php` | `src-container/Verification/` | Result value object |
| `VerificationContext.php` | `src-container/Verification/` | Context value object |
| `VerificationIssue.php` | `src-container/Verification/` | Individual issue value object |
| `SyntaxVerifier.php` | `src-container/Verification/` | php -l parallel checker |
| `PhpStanVerifier.php` | `src-container/Verification/` | PHPStan baseline delta checker |
| `StaticArtisanVerifier.php` | `src-container/Verification/` | AST-based config/route/provider check |
| `ComposerVerifier.php` | `src-container/Verification/` | composer validate + install |
| `ClassResolutionVerifier.php` | `src-container/Verification/` | use statement resolution |

---

## Acceptance Criteria

- [ ] Verifiers execute in exact specified order
- [ ] Failing verifier halts pipeline (subsequent verifiers don't run)
- [ ] `SyntaxVerifier` runs `php -l` in parallel (max 8 concurrent)
- [ ] Any syntax error = immediate pipeline failure
- [ ] PHPStan baseline established on original code before transforms
- [ ] PHPStan error count increase = `phpstan_regression` event + failure
- [ ] PHPStan baseline cached to `/output/phpstan-baseline.json`
- [ ] PHPStan runs with `--parallel` and `--memory-limit=1G`
- [ ] `StaticArtisanVerifier` uses `nikic/php-parser` only — NO app boot
- [ ] Config files verified as valid PHP array returns
- [ ] Route controller references verified via `class_exists()`
- [ ] Provider classes verified via `class_exists()` 
- [ ] `--with-artisan-verify` runs artisan commands (advisory, not blocking)
- [ ] `--skip-phpstan` requires explicit acknowledgement
- [ ] Each verifier emits `verification_result` JSON-ND event
- [ ] Verification failure blocks write-back to original repo

---

## Implementation Notes

- `SyntaxVerifier` should use a process pool pattern (symfony/process)
- PHPStan baseline caching is essential for `--resume` performance
- `StaticArtisanVerifier` is pure static analysis — this is a safety-critical design decision (F-04)
- `ClassResolutionVerifier` needs composer autoload map to resolve classes
- Consider that some verifiers can run in parallel (but pipeline spec says sequential)
- Each verifier's duration is tracked for performance profiling
