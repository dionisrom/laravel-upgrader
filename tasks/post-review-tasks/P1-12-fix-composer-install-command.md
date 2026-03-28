# P1-12 Post-Review: Use `composer install` not `composer update`

**Severity:** High  
**Source:** P1-12 review  
**Violated:** TRD-COMP-003, CD-04  

## Problem

`DependencyUpgrader::runComposerInstall()` runs `composer update` but TRD requires `composer install --no-interaction --prefer-dist --no-scripts`.

## Fix

Change the Process command array to `['composer', 'install', '--no-interaction', '--prefer-dist', '--no-scripts']`.
