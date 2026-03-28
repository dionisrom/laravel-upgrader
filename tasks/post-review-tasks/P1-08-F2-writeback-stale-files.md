# P1-08-F2: writeBack must remove stale files from original repo

**Severity:** HIGH  
**Source:** P1-08 post-review  
**Requirement:** TRD-ORCH-002 — original repo must reflect the transformed workspace state exactly

## Problem

`writeBack()` calls `copyDirectory()` which only copies files present in the workspace to the original repo. Files that were deleted during transformation remain as stale artifacts.

## Fix

Before copying workspace contents back, remove files in the original repo that do not exist in the workspace (preserving `.git/` and other VCS directories). Add a test that:
1. Creates a repo with files A and B
2. Creates workspace, deletes file B in workspace
3. Calls writeBack
4. Asserts file B no longer exists in original repo
