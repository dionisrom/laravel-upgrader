# P2-04-F4: Fix Stale composer.json in docker/hop-12-to-13/

**Severity:** LOW  
**Source:** P2-04 review finding F4  
**Violated:** Consistency — PHP and package versions mismatch the correct warmup file

## Problem

`docker/hop-12-to-13/composer.json` declares `"php": "^8.2"` and `"laravel/passport": "^12.0"`. The correct `composer.l13-warmup.json` has `"php": "^8.3"` and `"laravel/passport": "^13.0"`. The Dockerfile uses the correct file, but the stale `composer.json` creates confusion.

## Required

1. Update `docker/hop-12-to-13/composer.json` to match `composer.l13-warmup.json` for `php` (`^8.3`) and `laravel/passport` (`^13.0`)

## Validation

- Both files have consistent PHP and passport constraints
