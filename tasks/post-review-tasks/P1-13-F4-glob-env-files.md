# P1-13-F4: Glob `.env*` Instead of Hardcoded List

**Severity:** Medium  
**Requirement:** TRD-CONFIG-002 ("all .env* files")  
**Finding:** `ConfigSnapshotManager::ENV_FILES` only lists 4 specific names. `.env.local`, `.env.production`, etc. are missed.

## Fix

Replace the hardcoded `ENV_FILES` constant with a `glob($root . '/.env*')` call in `collectFiles()`.
