# P1-14-F3: Fix middleware manual review items silently dropped

**Parent Task:** P1-14  
**Severity:** High  
**Requirement:** LM-05  

## Problem

`MiddlewareMigrator.php` line ~71 uses `$manualReview + $collector->manualReviewItems` (array union). For numerically-indexed arrays, `+` keeps left-side keys and drops right-side duplicates. Should use `array_merge()`.

## Fix

Replace `$manualReview + $collector->manualReviewItems` with `array_merge($manualReview, $collector->manualReviewItems)`.

## Files

- `src-container/Lumen/MiddlewareMigrator.php`
