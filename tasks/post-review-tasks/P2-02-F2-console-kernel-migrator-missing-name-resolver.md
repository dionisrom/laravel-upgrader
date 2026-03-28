# P2-02-F2: ConsoleKernelMigrator Missing NameResolver

**Severity:** High  
**Requirement:** SK-03  
**File:** `src-container/SlimSkeleton/ConsoleKernelMigrator.php`

## Problem

The `ConsoleKernelMigrator` does not add `PhpParser\NodeVisitor\NameResolver` to its traverser. `ConsoleKernelVisitor::extractClassNames()` uses `$class->toString()` which returns the unresolved short name when use-imports are present.

This produces invalid (non-FQCN) entries in `$commandClasses`, which would generate broken `withCommands()` output in bootstrap/app.php.

## Fix

1. Add `$traverser->addVisitor(new \PhpParser\NodeVisitor\NameResolver());` before the ConsoleKernelVisitor.
2. Update `extractClassNames()` to read the `resolvedName` attribute.
3. Add a test with a Console Kernel fixture using short `use`-imported command class names.
