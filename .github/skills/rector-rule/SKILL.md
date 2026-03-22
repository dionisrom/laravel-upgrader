---
name: rector-rule
description: 'Scaffold and implement a new custom Rector rule for the Laravel Enterprise Upgrader. Use when: creating a Rector transformation rule for any version hop (L8→L9, L9→L10, PHP 8.x→8.y etc.) or package rule set (Spatie, Livewire, Sanctum, Filament). Produces: rule class, fixture test (.php.inc), test case, rector config registration. ALWAYS check upstream rector-laravel coverage before writing a new rule.'
argument-hint: 'Rule name and hop, e.g. "RemoveRouteNamespaceRector for L8→L9"'
---

# Rector Rule Authoring Workflow

## When to Use

- Creating a new Laravel hop transformation rule (L8→L9, L9→L10, L10→L11, etc.)
- Adding a package migration rule (Spatie, Livewire V2→V3, Sanctum, Filament, etc.)
- Writing a Silent Change detector for a PHP hop
- Gap-filling a transformation not covered by `driftingly/rector-laravel`

---

## Step 0: Check Upstream Coverage First

ALWAYS verify before writing a new rule. Creating a duplicate wastes effort and causes conflicts.

```bash
# Search rector-laravel for existing coverage of this transformation
grep -r "MethodName\|ClassName\|change description keywords" \
  vendor/driftingly/rector-laravel/src/ -l

# Browse rector-laravel rule sets for this Laravel version
ls vendor/driftingly/rector-laravel/src/Set/
```

Also check the [rector-laravel CHANGELOG](https://github.com/driftingly/rector-laravel/blob/main/CHANGELOG.md).

---

## Step 1: Create the Rule Class

Copy [assets/RuleTemplate.php](./assets/RuleTemplate.php) to:

```
src-container/Rector/Rules/{HopNamespace}/{RuleName}Rector.php
```

| Hop | Namespace |
|-----|-----------|
| L8→L9   | `L8ToL9`  |
| L9→L10  | `L9ToL10` |
| L10→L11 | `L10ToL11`|
| L11→L12 | `L11ToL12`|
| L12→L13 | `L12ToL13`|
| PHP 8.0→8.1 | `PhpHop80To81` |
| PHP 8.1→8.2 | `PhpHop81To82` |
| PHP 8.2→8.3 | `PhpHop82To83` |
| PHP 8.3→8.4 | `PhpHop83To84` |
| Package | `Package\{PackageName}` |

Fill in:
- Class name: `{RuleName}Rector` (must end in `Rector`)
- `getRuleDefinition()`: clear one-line description + concrete before/after `CodeSample`
- `getNodeTypes()`: use the **narrowest** matching node type (prefer `MethodCall` over `Expr`)
- `refactor()`: return `null` to skip, return modified `$node` to transform

---

## Step 2: Create Fixture Test File(s)

Copy [assets/FixtureTemplate.php.inc](./assets/FixtureTemplate.php.inc) to:

```
tests/Unit/Rector/Rules/{HopNamespace}/Fixture/{rule_name}.php.inc
```

Every rule needs **at minimum two fixtures**:

| Fixture file | Purpose |
|---|---|
| `{rule_name}.php.inc` | Happy path — input that IS transformed |
| `{rule_name}_skip.php.inc` | No-op — input that should NOT be changed |

The `-----` separator (5 dashes) divides before and after code in the fixture.

---

## Step 3: Create the Test Case

Copy [assets/TestCaseTemplate.php](./assets/TestCaseTemplate.php) to:

```
tests/Unit/Rector/Rules/{HopNamespace}/{RuleName}RectorTest.php
```

The test case uses `yieldFilesFromDirectory()` — all `.php.inc` files in `Fixture/` run automatically.

---

## Step 4: Register in Rector Config

Add the rule to `rector-configs/rector.{hop-slug}.php`:

```php
->withRules([
    // ...existing rules
    \App\Rector\Rules\L8ToL9\YourNewRector::class,
])
```

For package rules, register in `src/Package/Rules/{PackageName}/` and add to the version matrix in `config/package-rules/`.

---

## Step 5: Run Tests and Verify

```bash
# Run the rule's tests
vendor/bin/phpunit tests/Unit/Rector/Rules/{HopNamespace}/{RuleName}RectorTest.php -v

# Verify idempotency: apply rule, then apply again — output must be identical
vendor/bin/rector process --config=rector-configs/rector.{hop-slug}.php \
  tests/Unit/Rector/Rules/{HopNamespace}/Fixture/ --dry-run

# PHPStan must pass at level 8
vendor/bin/phpstan analyse \
  src-container/Rector/Rules/{HopNamespace}/{RuleName}Rector.php --level=8
```

---

## Package Rules: Extra Steps

Package rules activate only when the package is detected in `composer.lock`. They live in `src/Package/Rules/{PackageName}/` and extend `AbstractPackageRuleSet`.

1. Create rule class in `src/Package/Rules/{PackageName}/`
2. Add entry to version matrix YAML at `config/package-rules/{package-slug}.yaml`
3. The `PackageRuleActivator` picks it up automatically — no manual registration needed

---

## Quality Checklist

- [ ] Class name ends in `Rector`
- [ ] `getRuleDefinition()` has a concrete before/after `CodeSample` (not placeholder comments)
- [ ] Node type is the narrowest possible match
- [ ] Idempotent: running twice on the same file produces identical output
- [ ] Happy path fixture covers the real-world case
- [ ] No-op fixture verifies nothing changed for non-matching code
- [ ] PHPStan level 8 clean
- [ ] Registered in the appropriate `rector-configs/` file
- [ ] Package rules: only activates when package present in `composer.lock`
- [ ] Did NOT duplicate an upstream `rector-laravel` rule (Step 0 confirmed)
