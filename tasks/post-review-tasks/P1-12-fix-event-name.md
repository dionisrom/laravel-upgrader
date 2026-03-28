# P1-12 Post-Review: Fix `composer_install_failed` event name

**Severity:** Medium  
**Source:** P1-12 review  
**Violated:** CD-05, TRD-COMP-003  

## Problem

Implementation emits `composer.failed` but task/TRD specifies `composer_install_failed`.

## Fix

Change the emit call in the catch block from `composer.failed` to `composer_install_failed`.
