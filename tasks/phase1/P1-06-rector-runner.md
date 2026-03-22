# P1-06: Rector Runner & Config Builder

**Phase:** 1 — MVP  
**Priority:** Critical  
**Estimated Effort:** 5-6 days  
**Dependencies:** P1-01 (Project Scaffold), P1-05 (Breaking Change Registry)  
**Blocks:** P1-07 (Custom Rector Rules), P1-08 (Workspace Manager apply flow), P1-20 (Test Suite)  

---

## Agent Persona

**Role:** Rector/AST Transformation Engineer  
**Agent File:** `agents/rector-ast-engineer.agent.md`  
**Domain Knowledge Required:**
- Deep understanding of Rector's subprocess invocation model (`vendor/bin/rector`)
- Experience with `symfony/process` for subprocess management
- Understanding of PHP AST transformation concepts (nikic/php-parser)
- Knowledge of Rector's JSON output format and config file structure
- Awareness of why programmatic Rector invocation is NOT supported (F-01 CRITICAL finding)

---

## Objective

Implement `RectorRunner.php` (subprocess invocation) and `RectorConfigBuilder.php` (dynamic config generation) inside `src-container/Rector/`. These are the core transformation engine components that invoke Rector as a subprocess and parse its JSON output into structured value objects.

---

## Context from PRD & TRD

### CRITICAL — F-01: Rector Must Be Invoked as Subprocess

> Every production Rector integration (PHPStorm, Symfony Maker, Laravel Shift) shells out to `vendor/bin/rector` as a subprocess. Programmatic invocation via internal classes is NOT stable.

### RectorRunner (TRD §6.1 — TRD-RECTOR-001, TRD-RECTOR-002, TRD-RECTOR-003)

The exact subprocess command:

```php
$process = new Process([
    PHP_BINARY,
    'vendor/bin/rector',
    'process',
    $workspacePath,
    '--config=' . $configPath,
    '--dry-run',
    '--output-format=json',
    '--no-progress-bar',
    '--no-diffs',          // diffs are extracted from JSON, not stdout
]);
$process->setTimeout(600); // 10 minutes max
$process->run();
```

**Error Handling (TRD-RECTOR-002):**
- Non-zero exit code → capture stderr → emit `rector_error` JSON-ND event → throw `RectorExecutionException`

**JSON Output Shape (TRD-RECTOR-003):**

```typescript
interface RectorJsonOutput {
    file_diffs: FileDiff[];
    errors: RectorError[];
}
interface FileDiff {
    file: string;           // absolute path
    diff: string;           // unified diff string
    applied_rectors: string[]; // fully-qualified class names
}
```

### RectorConfigBuilder (TRD §6.2 — TRD-RECTOR-004, TRD-RECTOR-005)

Must generate a `rector.php` config file programmatically:
- `withPaths([$workspacePath])`
- `withSkipPath($workspacePath . '/.upgrader-state')` — never transform checkpoint files
- All rules from `driftingly/rector-laravel` applicable to the hop
- All custom rules from `Rector\Rules\{HopNamespace}\`

### Manual Review Detection (TRD-RECTOR-005)

The following patterns MUST be detected and emitted as `manual_review_required` events (never auto-transformed):
- `__call`, `__callStatic`, `__get`, `__set` magic methods
- `Macro::macro()` and `Macroable` trait usage
- Dynamic class instantiation: `new $className()`
- String-based method calls: `$obj->$methodName()`

### Value Objects

```php
final readonly class RectorResult {
    public function __construct(
        public array $fileDiffs,    // FileDiff[]
        public array $errors,       // RectorError[]
    ) {}
    
    public static function fromJson(string $json): self;
}

final readonly class FileDiff {
    public function __construct(
        public string $file,
        public string $diff,
        public array $appliedRectors,
    ) {}
}
```

---

## Files to Create

| File | Location | Purpose |
|---|---|---|
| `RectorRunner.php` | `src-container/Rector/` | Subprocess invocation via symfony/process |
| `RectorConfigBuilder.php` | `src-container/Rector/` | Dynamic rector.php config generation |
| `RectorResult.php` | `src-container/Rector/` | Value object for parsed JSON output |
| `FileDiff.php` | `src-container/Rector/` | Value object for individual file diff |
| `RectorExecutionException.php` | `src-container/Rector/` | Exception for Rector failures |
| `ManualReviewDetector.php` | `src-container/Rector/` | Detect magic methods, macros, dynamic calls |
| `rector.l8-to-l9.php` | `rector-configs/` | Base Rector config for L8→L9 hop |

---

## Acceptance Criteria

- [ ] `RectorRunner::run()` invokes Rector via `symfony/process` subprocess — NEVER programmatic
- [ ] JSON output parsed into `RectorResult` / `FileDiff` value objects
- [ ] Non-zero Rector exit emits `rector_error` event and throws `RectorExecutionException`
- [ ] Process timeout set to 600 seconds (10 minutes)
- [ ] `RectorConfigBuilder` generates valid `rector.php` with correct paths and skip paths
- [ ] `.upgrader-state` directory excluded from Rector processing
- [ ] `ManualReviewDetector` identifies magic methods, macros, and dynamic calls
- [ ] Manual review patterns emit structured events (not silently ignored)
- [ ] All classes are `final readonly` where appropriate
- [ ] Unit tests for `RectorResult::fromJson()` parsing

---

## Implementation Notes

- Rector writes nothing directly — it runs in `--dry-run` mode and outputs JSON
- `WorkspaceManager` (P1-08) is responsible for applying the diffs
- The `ManualReviewDetector` can use `nikic/php-parser` NodeVisitor pattern
- Config builder should support adding package-specific rule sets (future Phase 2)
- Emit JSON-ND events to stdout for all key operations (consumed by EventStreamer)
