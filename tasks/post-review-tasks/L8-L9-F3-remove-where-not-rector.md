# L8→L9 F3: Remove WhereNotToWhereNotInRector — Unverified Claim

## Finding

`WhereNotToWhereNotInRector` claims `whereNot()` on `Rule::unique()`/`Rule::exists()` was renamed to `whereNotIn()` and the value argument must be wrapped in an array. The official Laravel 9 upgrade guide makes **no mention** of this change. The rule risks incorrectly renaming method calls and changing argument semantics.

## Severity

Medium — unverified claim, risk of incorrect transformation.

## Affected Files

- `src-container/Rector/Rules/L8ToL9/WhereNotToWhereNotInRector.php` — delete
- `tests/Unit/Rector/Rules/L8ToL9/WhereNotToWhereNotInRectorTest.php` — delete
- `tests/Unit/Rector/Rules/L8ToL9/Fixture/WhereNotToWhereNotIn/` — delete
- `rector-configs/rector.l8-to-l9.php` — remove import and registration
- `docker/hop-8-to-9/docs/breaking-changes.json` — remove `l9_rule_where_not_renamed` entry

## Requirement Violated

Rector rules must address real, documented breaking changes.

## Status

- [ ] Completed
