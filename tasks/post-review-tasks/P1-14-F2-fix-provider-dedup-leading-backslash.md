# P1-14-F2: Fix built-in provider deduplication (leading backslash mismatch)

**Parent Task:** P1-14  
**Severity:** High  
**Requirement:** LM-04  

## Problem

`ProviderCallCollector` stores class names with leading `\` (e.g. `\Illuminate\Auth\AuthServiceProvider`). `LUMEN_BUILTIN_PROVIDERS` stores them without. The `in_array()` check on line ~82 never matches, so built-in Lumen providers are duplicated in the migrated `config/app.php`.

## Fix

Normalize the class name before comparison — either strip the leading `\` from the collector output or add it to the constant entries.

## Files

- `src-container/Lumen/ProvidersMigrator.php`
