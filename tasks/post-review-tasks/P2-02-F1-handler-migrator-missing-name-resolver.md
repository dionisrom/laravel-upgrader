# P2-02-F1: ExceptionHandlerMigrator Missing NameResolver

**Severity:** High  
**Requirement:** SK-02, TRD-P2SLIM-001  
**File:** `src-container/SlimSkeleton/ExceptionHandlerMigrator.php`

## Problem

The `ExceptionHandlerMigrator` does not add `PhpParser\NodeVisitor\NameResolver` to its traverser (lines 73-75). The `HandlerVisitor::resolveClassConst()` uses `$node->class->toString()` which returns the short (unresolved) name when use-imports are present.

This causes the L11 default diff for `$dontReport` to fail to match framework exceptions when they are imported via `use` statements, producing false-positive entries in the migration output.

## Fix

1. Add `$traverser->addVisitor(new \PhpParser\NodeVisitor\NameResolver());` before the HandlerVisitor.
2. Update `resolveClassConst()` to read the `resolvedName` attribute (same pattern as KernelVisitor).
3. Add a test with a Handler fixture that uses short `use`-imported class names to verify correct FQCN resolution.
