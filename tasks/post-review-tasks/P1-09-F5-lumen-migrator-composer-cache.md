# P1-09-F5: Add Pre-warmed Composer Cache to Lumen-Migrator

**Severity:** LOW  
**Source:** P1-09 Docker Image review  

## Problem

The lumen-migrator Dockerfile has no `laravel-cache` build stage. If any stage needs Composer under `--network=none`, it will fail.

## Required

1. Add a `laravel-cache` build stage similar to hop-8-to-9
2. Create a `composer.lumen-warmup.json` with the Laravel 9 dependencies needed for Lumen migration
3. Copy the pre-warmed cache into the runtime stage

## Acceptance

- Lumen-migrator has a pre-warmed Composer cache stage
- The warmup JSON is committed
