# P1-22-F2: VerifierResult uses `readonly class` (PHP 8.2+) in src-container

**Source:** P1-22 Hardening review  
**Severity:** High  
**Requirement:** PHP 8.1 compatibility for hop-8-to-9 container  

## Problem

`src-container/Verification/VerifierResult.php` declares `final readonly class VerifierResult`. The `readonly class` modifier requires PHP 8.2+. The hop-8-to-9 container uses PHP 8.1.

## Fix

Change `final readonly class VerifierResult` to `final class VerifierResult`. Individual properties already have `readonly` modifiers (PHP 8.1 compatible).

## Validation

`testSrcContainerHasNoReadonlyClassDeclarations` must pass.
