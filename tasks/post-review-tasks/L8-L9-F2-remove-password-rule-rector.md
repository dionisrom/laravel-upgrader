# L8→L9 F2: Remove PasswordRuleRector — Fabricated Claim

## Finding

`PasswordRuleRector` claims to rename `requireLetters()→letters()`, `requireMixedCase()→mixedCase()`, etc. **These `require*` methods never existed** in `Illuminate\Validation\Rules\Password`. The class was introduced in Laravel 8.39 with `letters()`, `mixedCase()`, `numbers()`, `symbols()`, `uncompromised()` from the start. The official L9 upgrade guide's "password Rule" section refers to renaming the `password` validation rule string to `current_password`, not method renames.

## Severity

Low — dead code (rule would never match real code since source methods don't exist).

## Affected Files

- `src-container/Rector/Rules/L8ToL9/PasswordRuleRector.php` — delete
- `tests/Unit/Rector/Rules/L8ToL9/PasswordRuleRectorTest.php` — delete
- `tests/Unit/Rector/Rules/L8ToL9/Fixture/PasswordRule/` — delete
- `rector-configs/rector.l8-to-l9.php` — remove import and registration
- `docker/hop-8-to-9/docs/breaking-changes.json` — remove `l9_password_rule_methods_renamed` entry

## Requirement Violated

Rector rules must address real, documented breaking changes.

## Status

- [ ] Completed
