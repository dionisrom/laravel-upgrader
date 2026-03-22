# syntax=docker/dockerfile:1
# ============================================================
# Hop Container: hop-8-to-9
# Laravel 8 → Laravel 9 upgrade pipeline
# Base PHP: 8.1-cli-alpine
# ============================================================

# ─── Stage 1: Base ────────────────────────────────────────────────────────────
FROM php:8.1-cli-alpine AS base

RUN apk add --no-cache \
    bash \
    git \
    unzip \
    && rm -rf /var/cache/apk/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ─── Stage 2: Dependencies (cached layer) ─────────────────────────────────────
FROM base AS deps

WORKDIR /upgrader

COPY docker/hop-8-to-9/composer.hop-8-to-9.json ./composer.json
COPY docker/hop-8-to-9/composer.hop-8-to-9.lock ./composer.lock

RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --optimize-autoloader \
    --prefer-dist

# ─── Stage 3: Runtime ─────────────────────────────────────────────────────────
FROM base AS runtime

# Non-root user
RUN adduser -D -u 1000 upgrader

WORKDIR /upgrader

# Vendor from deps stage
COPY --from=deps /upgrader/vendor /upgrader/vendor

# Container-side application source
COPY src-container/ /upgrader/src/

# Rector config
COPY rector-configs/rector.l8-to-l9.php /upgrader/rector.php

# Bundled knowledge base (no network at runtime)
COPY docker/hop-8-to-9/docs/ /upgrader/docs/

# Inline Diff2Html assets (no CDN)
COPY assets/diff2html.min.css /upgrader/assets/diff2html.min.css
COPY assets/diff2html.min.js  /upgrader/assets/diff2html.min.js

# rector-laravel fork for fallback
COPY vendor-patches/rector-laravel-fork/ /upgrader/vendor-patches/rector-laravel-fork/

# Entrypoint
COPY docker/hop-8-to-9/entrypoint.sh /upgrader/entrypoint.sh
RUN chmod +x /upgrader/entrypoint.sh

USER upgrader

# Workspace is mounted at runtime by the host orchestrator
WORKDIR /workspace

ENTRYPOINT ["/upgrader/entrypoint.sh"]
