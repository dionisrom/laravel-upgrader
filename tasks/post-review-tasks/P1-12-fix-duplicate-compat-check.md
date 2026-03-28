# P1-12 Post-Review: Eliminate duplicate compatibility check

**Severity:** Low  
**Source:** P1-12 review  

## Problem

`upgrade()` calls `compatibilityChecker->check()` for each package twice: once during blocker collection and once during version bumping.

## Fix

Cache the compatibility results from the first loop and reuse them in the version-bump loop.
