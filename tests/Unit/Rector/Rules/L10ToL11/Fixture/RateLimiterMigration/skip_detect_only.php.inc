<?php

// RateLimiterMigrationAuditor: detect-only rule — no code transformation occurs.
// Detects RateLimiter::for() calls that must be manually migrated.

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;

RateLimiter::for('api', function ($request) {
    return Limit::perMinute(60)->by($request->ip());
});
