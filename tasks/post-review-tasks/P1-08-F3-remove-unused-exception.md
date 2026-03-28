# P1-08-F3: Remove unused DiffApplyException

**Severity:** MEDIUM  
**Source:** P1-08 post-review  

## Problem

`DiffApplyException` exists but is never referenced. Dead code.

## Fix

Delete `src/Workspace/Exception/DiffApplyException.php`.
