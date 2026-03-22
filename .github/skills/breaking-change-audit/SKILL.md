---
name: breaking-change-audit
description: 'Research and document all breaking changes for a Laravel or PHP version hop in the Laravel Enterprise Upgrader. Use when: starting work on a new hop container, populating breaking-changes.json, identifying which changes need custom Rector rules versus existing rector-laravel upstream coverage. Produces a complete breaking-changes.json and a rule-mapping checklist of auto-fixable vs manual-review items.'
argument-hint: 'Version hop to audit (e.g. "Laravel 9→10" or "PHP 8.2→8.3")'
---

# Breaking Change Audit Workflow

## When to Use

- Beginning work on any new hop container before writing a single Rector rule
- Populating `docker/{hop-name}/docs/breaking-changes.json` from scratch
- Building the implementation checklist that feeds the `rector-rule` skill
- Determining whether a given behavioral change is auto-fixable or requires human review

---

## Information Sources

See [references/sources.md](./references/sources.md) for the canonical per-hop research source list.

---

## Procedure

### Step 1 — Gather Official Sources

**Laravel version hops (L8→L9, L9→L10, L10→L11, L11→L12, L12→L13):**

1. Official upgrade guide: `https://laravel.com/docs/{TARGET_VERSION}/upgrade`
2. Framework CHANGELOG: `https://github.com/laravel/framework/blob/v{TARGET_MAJOR}.0/CHANGELOG.md`
3. rector-laravel changelog: `https://github.com/driftingly/rector-laravel/blob/main/CHANGELOG.md`
4. Laracasts upgrade thread for community-discovered edge cases

**PHP version hops (8.0→8.1, 8.1→8.2, 8.2→8.3, 8.3→8.4, 8.4→8.5-beta):**

1. PHP migration guide: `https://www.php.net/manual/en/migration{NN}.php` (e.g., `migration81`)
2. PHP RFC tracker: `https://wiki.php.net/rfc` — search accepted RFCs for the target version
3. Rector LevelSetList coverage: inspect `vendor/rector/rector/rules/` locally

---

### Step 2 — Enumerate All Breaking Changes

For each source, extract every change that could break a running application. Cast the net wide — include:

- Method signature changes (renamed, removed, added required parameters)
- Class/namespace removals or renames
- Config key renames or new required keys
- Behavioral changes (same input, different output)
- Return type changes (especially nullable → non-nullable)
- Exception type changes
- Deprecations that become hard errors in the target version

Assign a provisional `severity` to each entry as you go:
- `error` — application will fail at runtime if not addressed
- `warning` — likely to cause incorrect behavior in some scenarios
- `info` — deprecation notice or documentation-only change

---

### Step 3 — Check rector-laravel Upstream Coverage

Before marking any change as requiring a **new custom rule**, verify whether rector-laravel already ships it:

```bash
# List all rules rector-laravel ships for the target Laravel set
grep -r "class.*Rector" vendor/driftingly/rector-laravel/src/ --include="*.php" -l

# Search by keyword (method name, class name, constant)
grep -r "AuthenticateMiddleware\|RouteNamespace\|dispatchNow" \
    vendor/driftingly/rector-laravel/src/ -l

# Check which set list covers the target version
cat vendor/driftingly/rector-laravel/src/Set/LaravelSetList.php
```

Mark each change with one of:

| Coverage | Meaning |
|---|---|
| `upstream-full` | rector-laravel fully handles it — no custom rule needed |
| `upstream-partial` | rector-laravel handles common case, custom rule needed for edge cases |
| `custom-needed` | No upstream coverage — custom rule required |
| `not-automatable` | Cannot be fixed by AST transformation — manual review required |

---

### Step 4 — Classify Each Change

For every breaking change, fill in all classification fields:

| Field | Values |
|---|---|
| `severity` | `error` / `warning` / `info` |
| `category` | `api` / `config` / `namespace` / `behavior` / `removal` / `type-system` / `exception` |
| `auto_fixable` | `true` / `false` |
| `rector_rule` | Fully-qualified class name if custom rule needed; upstream rule name if covered; `null` if manual |

#### Auto-fixability Decision Tree

```
Is the change visible to a PHP AST parser?
  NO  → auto_fixable: false (silent behavior change)
  YES → Can Rector apply the fix deterministically without business-logic context?
          YES → Is the transformation idempotent (running twice = same result)?
                  YES → auto_fixable: true
                  NO  → auto_fixable: false (complex, likely not worth automating)
          NO  → auto_fixable: false (requires human judgment — set category: behavior)
```

**Always `auto_fixable: false`:**
- Config file *value* changes (keys are fine, values require human review)
- Behavioral differences in edge cases only (e.g., null handling changes)
- Database query behavior changes
- Authentication/authorization flow changes
- Changes that depend on the specific app's configuration

---

### Step 5 — Produce breaking-changes.json

Write to `docker/{hop-name}/docs/breaking-changes.json`. Follow the schema:

```json
{
  "hop": "hop-9-to-10",
  "from": "9",
  "to": "10",
  "generated": "2025-01-01",
  "sources": [
    "https://laravel.com/docs/10/upgrade",
    "https://github.com/driftingly/rector-laravel/blob/main/CHANGELOG.md"
  ],
  "changes": [
    {
      "id": "BC-001",
      "title": "Short descriptive title",
      "description": "What changed, why it breaks, what must be done.",
      "severity": "error",
      "category": "api",
      "auto_fixable": true,
      "upstream_coverage": "custom-needed",
      "rector_rule": "App\\Rector\\Rules\\L9ToL10\\ExampleRector",
      "affected_files": "app/**/*.php",
      "before": "// example before code",
      "after": "// example after code",
      "references": [
        "https://laravel.com/docs/10/upgrade#relevant-section"
      ]
    }
  ]
}
```

IDs must be sequential and stable (`BC-001`, `BC-002`, …). Do not renumber existing IDs.

---

### Step 6 — Produce the Rule Mapping Checklist

After the full audit, generate a Markdown summary table for use as the implementation checklist. Save it to `docker/{hop-name}/docs/audit-summary.md`:

```markdown
# Hop {HOP_NAME} — Breaking Change Audit Summary

| ID | Title | Severity | Auto-fixable | Coverage | Custom Rule Class |
|---|---|---|---|---|---|
| BC-001 | Route namespace removal | error | yes | upstream-partial | L9ToL10\RemoveRouteNamespaceRector |
| BC-002 | Eloquent mass assignment | error | no | not-automatable | — |
| BC-003 | Queue job batching change | warning | yes | upstream-full | — |
```

This table becomes the ordered checklist for the `rector-rule` skill.

---

## Severity Reference

| Severity | When to Use | Example |
|---|---|---|
| `error` | App fails to boot or crashes at runtime | Method removed, class renamed |
| `warning` | App runs but produces incorrect results in some cases | Null handling difference |
| `info` | Only affects developer experience or deprecated usage | IDE helper type change |

---

## Quality Checklist

- [ ] All official upgrade guide sections reviewed
- [ ] rector-laravel upstream coverage checked for each `auto_fixable: true` item
- [ ] Every entry has a stable `id`, `severity`, `category`, and `auto_fixable`
- [ ] `breaking-changes.json` is valid JSON (no trailing commas)
- [ ] `audit-summary.md` generated and committed alongside the JSON
- [ ] Changes that are `not-automatable` have clear human instructions in `description`
- [ ] All `references` URLs are reachable
