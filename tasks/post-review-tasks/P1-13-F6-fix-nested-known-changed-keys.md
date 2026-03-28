# P1-13-F6: Fix Nested knownChangedKeys Propagation in ConfigMerger

**Severity:** Low  
**Requirement:** CM-06 (correct merge behavior)  
**Finding:** `ConfigMerger::merge()` recurses with `$knownChangedKeys = []`, meaning nested scalar changes are never applied.

## Fix

Support dot-notation or nested key paths in knownChangedKeys, or pass down the relevant sub-keys when recursing. Current callers that pass changes as new keys (not overwrites) are unaffected, but future migrations relying on nested scalar overwrites would silently fail.
