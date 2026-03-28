# P1-14-F5: Duplicate `lumen_feature_disabled` events for Eloquent

**Parent Task:** P1-14  
**Severity:** Low  

## Problem

Both `FacadeBootstrapMigrator::migrate()` and `EloquentBootstrapDetector::detect()` independently detect `->withEloquent(` absence and emit `lumen_feature_disabled` with `feature=eloquent`. This produces duplicate JSON-ND events.

## Fix

Either: (a) have `EloquentBootstrapDetector` skip the `lumen_feature_disabled` event and only emit `lumen_eloquent_detection`, or (b) have `FacadeBootstrapMigrator` only handle facades and delegate eloquent to the detector.

## Files

- `src-container/Lumen/FacadeBootstrapMigrator.php`
- `src-container/Lumen/EloquentBootstrapDetector.php`
