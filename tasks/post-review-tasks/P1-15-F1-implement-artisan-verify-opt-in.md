# P1-15-F1: Implement --with-artisan-verify opt-in

**Severity:** HIGH  
**Requirement:** VP-10, TRD-VERIFY-008  
**Source:** P1-15 review finding F1  

## Problem

`withArtisanVerify` flag exists in `VerificationContext` but no verifier reads or acts on it. The opt-in artisan verification commands (`php artisan config:cache --quiet`, `php artisan route:list --json > /dev/null`) are never executed. No tests cover this feature.

## Required Fix

Add artisan verification logic to `StaticArtisanVerifier` (or a separate verifier) that:

1. Checks `$ctx->withArtisanVerify` flag
2. Runs `php artisan config:cache --quiet` and `php artisan route:list --json`
3. Reports failures as **warnings only** (advisory, not blocking) per TRD-VERIFY-008
4. Emits results through the event system

## Files to Modify

- `src-container/Verification/StaticArtisanVerifier.php`
- `tests/Unit/Verification/StaticArtisanVerifierTest.php`

## Acceptance Criteria

- [ ] When `withArtisanVerify` is true, artisan commands execute after static checks
- [ ] Artisan failures produce warnings, not errors (non-blocking)
- [ ] When `withArtisanVerify` is false (default), artisan commands do not run
- [ ] Tests cover both opt-in and opt-out paths
