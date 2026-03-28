# L8→L9 F1: Remove ModelUnguardRector — Fabricated Claim, Dangerous

## Finding

`ModelUnguardRector` removes `Model::unguard()` and `Model::reguard()` calls entirely, claiming these were "removed" or "deprecated" in Laravel 9. **This is fabricated.** `Model::unguard()` exists through Laravel 13 and was never deprecated. Removing these calls silently breaks seeders and tests that rely on disabling mass-assignment protection.

## Severity

High — actively harmful transformation based on a false claim.

## Affected Files

- `src-container/Rector/Rules/L8ToL9/ModelUnguardRector.php` — delete
- `tests/Unit/Rector/Rules/L8ToL9/ModelUnguardRectorTest.php` — delete
- `tests/Unit/Rector/Rules/L8ToL9/Fixture/ModelUnguard/` — delete
- `tests/Unit/Rector/Rules/L8ToL9/config/` — remove related config
- `rector-configs/rector.l8-to-l9.php` — remove import and registration
- `docker/hop-8-to-9/docs/breaking-changes.json` — remove `l9_model_unguard_deprecated` entry

## Requirement Violated

Rector rules must address real, documented breaking changes. This rule addresses a non-existent change and causes data loss.

## Status

- [ ] Completed
