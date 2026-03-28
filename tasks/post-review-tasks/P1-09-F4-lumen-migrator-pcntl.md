# P1-09-F4: Install pcntl Extension in Lumen-Migrator Dockerfile

**Severity:** LOW  
**Source:** P1-09 Docker Image review  

## Problem

The lumen-migrator Dockerfile does not install the pcntl PHP extension, unlike all hop-* containers. The hardening test `testAllHopDockerfilesInstallPcntlExtension` uses a `hop-*` glob and misses this.

## Required

1. Add `docker-php-ext-install pcntl` to the lumen-migrator Dockerfile base stage
2. Optionally extend the hardening test to also check lumen-migrator

## Acceptance

- Lumen-migrator Dockerfile installs pcntl
