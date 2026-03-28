# P1-15-F8: StaticArtisanVerifier should not trigger autoloading

**Severity:** LOW  
**Requirement:** F-04, TRD-VERIFY-007  
**Source:** P1-15 review finding F8  

## Problem

`class_exists($class, true)` in `verifyProviders` and `verifyRouteControllers` triggers the autoloader, potentially loading application code. F-04 prohibits executing application code.

## Required Fix

Use `class_exists($class, false)` (no autoload) after loading the composer classmap separately, or parse the classmap file directly to check class existence without loading any application files.

## Files to Modify

- `src-container/Verification/StaticArtisanVerifier.php`
- `tests/Unit/Verification/StaticArtisanVerifierTest.php`
