# P2-06-F3: Document TRD §18 return type divergence

**Source:** P2-06 review finding #3 (MEDIUM)  
**Requirement:** TRD §18 PackageRuleActivator signature

## Problem

TRD §18 specifies `activate()` returns `RectorConfig[]`. Implementation returns `list<string>` (file paths). This is architecturally correct for subprocess invocation but undocumented.

## Fix

Add a brief note in the `PackageRuleActivator` class docblock explaining the deliberate divergence from TRD §18 and why file paths are preferred over in-process config loading.

## Files to change

- `src/Package/PackageRuleActivator.php`
