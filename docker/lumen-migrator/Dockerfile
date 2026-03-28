# syntax=docker/dockerfile:1
# ============================================================
# Hop Container: lumen-migrator
# Lumen → Laravel migration pipeline
# Base PHP: 8.1-cli-alpine
# ============================================================

# ─── Stage 1: Base ────────────────────────────────────────────────────────────
FROM php:8.1-cli-alpine AS base

RUN apk add --no-cache \
    bash \
    git \
    unzip \
    && docker-php-ext-install pcntl \
    && rm -rf /var/cache/apk/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ─── Stage 2: Dependencies (cached layer) ─────────────────────────────────────
FROM base AS deps

WORKDIR /upgrader

COPY docker/lumen-migrator/composer.lumen-migrator.json ./composer.json
COPY docker/lumen-migrator/composer.lumen-migrator.lock ./composer.lock

RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --optimize-autoloader \
    --prefer-dist

# ─── Stage 3: laravel-cache (pre-warm Composer for target project) ────────────
FROM deps AS laravel-cache

WORKDIR /tmp/warmup

# Populates COMPOSER_CACHE_DIR with L9 package zips + metadata so that
# DependencyUpgrader can run `composer install --prefer-dist` offline
# at runtime when the container has --network=none.
COPY docker/lumen-migrator/composer.lumen-warmup.json ./composer.json
RUN COMPOSER_CACHE_DIR=/laravel-composer-cache \
    composer install \
        --no-scripts \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --quiet

# ─── Stage 4: Runtime ─────────────────────────────────────────────────────────
FROM base AS runtime

# Non-root user
RUN adduser -D -u 1000 upgrader

WORKDIR /upgrader

# Vendor from deps stage
COPY --from=deps /upgrader/vendor /upgrader/vendor

# Container-side application source
COPY src-container/ /upgrader/src/

# Inline Diff2Html assets (no CDN)
COPY assets/diff2html.min.css /upgrader/assets/diff2html.min.css
COPY assets/diff2html.min.js  /upgrader/assets/diff2html.min.js

# Entrypoint
COPY docker/lumen-migrator/entrypoint.sh /upgrader/entrypoint.sh
RUN chmod +x /upgrader/entrypoint.sh

# Pre-warmed Composer cache — allows offline composer install with --network=none
COPY --from=laravel-cache --chown=upgrader:upgrader \
    /laravel-composer-cache /home/upgrader/.composer/cache

USER upgrader

# Workspace is mounted at runtime by the host orchestrator
WORKDIR /workspace

ENTRYPOINT ["/upgrader/entrypoint.sh"]
