# Post-Review: P1-05 — Fix package-compatibility.json schema

**Source:** P1-05 Breaking Change Registry review  
**Severity:** HIGH  
**Violated:** TRD §8.2 / TRD-COMP-001 — `package-compatibility.json` must use `l9_support` / `l10_support` keys  

## Problem

Every package entry uses `"support": true` instead of the TRD-specified keys `"l9_support"` and `"l10_support"`. `DependencyUpgrader` (TRD-COMP-001 step 5) reads `"l9_support": false` to flag blockers — the current key name silently bypasses all blocker detection.

## Fix

1. Replace `"support"` key with `"l9_support"` and add `"l10_support"` per TRD §8.2.
2. Set `l10_support` based on whether the package version also supports L10.
