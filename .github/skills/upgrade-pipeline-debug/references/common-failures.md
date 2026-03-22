# Common Pipeline Failure Modes

Reference for the `upgrade-pipeline-debug` skill. Each section covers one failure pattern: symptoms, root cause, and the exact fix.

---

## Failure #1 — Rector Parse Error (Syntax in Source)

**Symptom:**
```json
{"type":"hop.failed","data":{"stage":"rector","exit_code":1}}
```
Rector log contains: `PhpParser\Error: Syntax error, unexpected T_...`

**Root Cause:** The source application has a PHP file with a syntax error before the hop begins. Rector cannot parse it.

**Fix:**
```bash
# Find the file with the syntax error
find /workspace -name "*.php" | xargs php -l 2>&1 | grep "Parse error"

# Fix the syntax error manually, then re-run
upgrader run --resume --workspace-id={id} --from-hop={hop}
```

---

## Failure #2 — Rector Rule Exception (Bug in Custom Rule)

**Symptom:**
```json
{"type":"hop.failed","data":{"stage":"rector","exit_code":1}}
```
Rector log contains: `Error: Call to a member function ... on null` from a class in `App\Rector\Rules\`.

**Root Cause:** A custom Rector rule has a null guard missing or an unhandled node shape.

**Fix:**
1. Note the rule class and file path from the stack trace.
2. Add the null guard or narrow the `getNodeTypes()` match.
3. Add a fixture test for the edge case that triggered the failure.
4. Rebuild the image and re-run the hop in isolation.

```bash
# Run the specific rule against the failing file only
vendor/bin/rector process \
    --config=rector-configs/rector.{hop}.php \
    --dry-run \
    --debug \
    /workspace/app/Path/To/FailingFile.php
```

---

## Failure #3 — Rector Timeout / OOM

**Symptom:** Container exits with code `137` (OOM killed) or `124` (timeout). No `rector.completed` event emitted.

**Root Cause:** Large codebase or a rule with O(n²) node traversal.

**Fix:**
1. Run Rector with `--paths` limited to one subdirectory at a time to identify the problematic directory.
2. If a specific file is causing OOM, add it to the `withSkipPath()` list in the rector config and document it as a manual-review item.
3. If the entire codebase is too large, raise the container memory limit:

```bash
docker run --rm --network=none \
    --memory=2g \
    -v /path/to/workspace:/workspace \
    upgrader/{hop}:latest
```

---

## Failure #4 — PHPStan New Errors After Rector (Real Regression)

**Symptom:**
```json
{"type":"hop.failed","data":{"stage":"phpstan","exit_code":1}}
```
PHPStan output shows type errors in files that Rector modified.

**Root Cause:** Rector applied a transformation that introduced a type mismatch. For example, a method return type was changed but callers were not updated.

**Fix:**
1. Extract the list of files Rector modified from the `rector.completed` event:
```bash
grep '"type":"rector.completed"' workspace/{id}/hop-{name}.jsonnd \
    | jq -r '.data.changed_files[]? // empty'
```
2. Cross-reference with PHPStan errors — the errors will be in modified files or their callers.
3. Either fix the Rector rule to also update callers, or add a second Rector pass.
4. If the error is in a vendor file or unfixable, add a PHPStan ignore:

```bash
# Generate a PHPStan baseline for just the new errors
vendor/bin/phpstan analyse /workspace --error-format=json \
    | jq '.files' > /tmp/phpstan-new-errors.json
```

---

## Failure #5 — PHPStan False Positive After Rector

**Symptom:** PHPStan reports an error, but the code is semantically correct. Common with framework magic (Eloquent, Facades).

**Root Cause:** PHPStan stubs for the framework are outdated or missing for the target Laravel version.

**Fix:**
1. Check if the error is known in `phpstan-laravel` stubs:
```bash
composer show --all larastan/larastan | grep versions
```
2. Upgrade `larastan/larastan` to a version that covers the target Laravel version.
3. If the stub doesn't exist yet, add a `phpstan-ignore-next-line` with a tracking comment:
```php
/** @phpstan-ignore-next-line -- Larastan stubs pending for L{VER}, tracked in issue #NNN */
$result = SomeFacade::methodAddedInNewVersion();
```
4. Document all suppressed errors in `docker/{hop-name}/docs/known-phpstan-suppressions.md`.

---

## Failure #6 — Rector Produced Invalid PHP Syntax

**Symptom:** `hop.failed` with `stage: php_lint`. `php -l` reports a parse error on a file Rector modified.

**Root Cause:** A Rector rule's AST transformation is generating an invalid node or is not correctly re-printing the AST. This is a bug in the rule.

**Fix:**
1. Find the file that failed the lint:
```bash
find /workspace -name "*.php" | xargs php -l 2>&1 | grep "Parse error"
```
2. Run `git diff` in the workspace to see what Rector changed.
3. Open the rule's fixture test and add a case covering the broken code shape.
4. Fix the AST transformation in the rule — common causes:
   - Using `new Node\Identifier(...)` where `new Node\Name(...)` is needed
   - Forgetting to set `->setAttribute('startLine', ...)` on synthetic nodes (usually not required with modern Rector)
   - Returning a `Node` of a different type than the one matched

---

## Failure #7 — Composer Version Conflict

**Symptom:** `hop.failed` with `stage: composer`. Composer output shows an incompatible constraint.

**Root Cause:** The application's `composer.json` has a package that is not compatible with the target Laravel version.

**This is expected behavior** — it is a manual-review item. The pipeline correctly reports it.

**Fix:**
1. Read the Composer error from the event log:
```bash
grep '"type":"composer.completed"' workspace/{id}/hop-{name}.jsonnd | jq '.data.error'
```
2. Identify the conflicting package.
3. Update `composer.json` to the compatible version range.
4. Check [Packagist](https://packagist.org) or the package's changelog for compatibility matrices.
5. If no compatible version exists, document it in the upgrade report as a blocker.

---

## Failure #8 — Composer Timeout / Network (--network=none)

**Symptom:** Composer hangs or fails with `Could not connect to packagist.org`.

**Root Cause:** Composer tried to contact the network, but containers run with `--network=none`.

**Fix:** Composer must always run **outside** the hop container (on the host or in a separate network-enabled container). If a hop is running Composer internally, that is an architectural violation.

Correct flow:
1. Composer update runs **before** the hop containers start, in a pre-hop phase with network access.
2. Hop containers receive the already-updated `vendor/` directory via the mounted workspace.
3. If Composer is needed mid-chain, use the orchestrator's `ComposerUpgrader` service (which runs with network access on the host).

---

## Failure #9 — Verification Step Failure (Artisan Bootstrap)

**Symptom:** `hop.failed` with `stage: verification`. `php artisan` exits non-zero.

**Root Cause:** After the hop, the application can no longer bootstrap. Common causes:
- A service provider references a class that was renamed but not all call sites updated
- `config/` file has a key that no longer exists in the new framework version
- A facade alias is missing

**Fix:**
```bash
# Run artisan directly in the debug container to see the full error
docker run --rm -it \
    --network=none \
    -v /path/to/workspace:/workspace \
    --entrypoint /bin/sh \
    upgrader/{hop}:latest

# Inside container:
cd /workspace && php artisan --version 2>&1
php artisan config:cache 2>&1
```

Read the full stack trace. The failure is usually a missing `use` import or a renamed class not covered by any Rector rule — add a new rule for it.

---

## Failure #10 — Checkpoint Corruption / Resume Failure

**Symptom:** `--resume` flag is ignored, or the run starts from the beginning of the chain.

**Root Cause:** The checkpoint file is missing, has an invalid JSON structure, or has a `status` field that doesn't match what `ChainRunner` expects.

**Fix:**
```bash
# Validate the checkpoint JSON
cat workspace/{id}/checkpoint.json | jq . > /dev/null && echo "Valid JSON" || echo "INVALID JSON"

# Check the expected status values
cat workspace/{id}/checkpoint.json | jq '.hop_statuses'
```

See [checkpoint-structure.md](./checkpoint-structure.md) for the full schema. Manually set the failed hop's status back to `pending` to retry it:

```bash
# Edit the checkpoint to retry a specific hop
jq '.hop_statuses["hop-9-to-10"] = "pending"' workspace/{id}/checkpoint.json \
    > /tmp/checkpoint-fixed.json && mv /tmp/checkpoint-fixed.json workspace/{id}/checkpoint.json
```

---

## Failure #11 — Container Image Not Found

**Symptom:** `docker run` fails with `Unable to find image 'upgrader/hop-X-to-Y:latest' locally`.

**Root Cause:** The hop container image has not been built or has not been pushed to the registry the orchestrator is configured to pull from.

**Fix:**
```bash
# Build locally
docker build -t upgrader/hop-{name}:latest ./docker/hop-{name}/

# Or pull from registry
docker pull {registry}/upgrader/hop-{name}:{version}

# Check the image map in the orchestrator config
cat config/hop-images.json
```
