<?php

/**
 * Stub implementations of Laravel helper functions for use when including
 * config files outside of a running Laravel application (e.g. inside the
 * upgrader Docker container).
 *
 * This file is require_once'd by ConfigMigrator before every include of a
 * config/*.php file.  Functions are guarded by function_exists() so they do
 * not conflict if the process later bootstraps a real Laravel app.
 */

if (!function_exists('env')) {
    /**
     * @param  mixed $default
     * @return mixed
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);
        return $value !== false ? $value : $default;
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return '/storage' . ($path !== '' ? '/' . $path : '');
    }
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return '' . ($path !== '' ? '/' . $path : '');
    }
}

if (!function_exists('public_path')) {
    function public_path(string $path = ''): string
    {
        return '/public' . ($path !== '' ? '/' . $path : '');
    }
}

if (!function_exists('resource_path')) {
    function resource_path(string $path = ''): string
    {
        return '/resources' . ($path !== '' ? '/' . $path : '');
    }
}

if (!function_exists('app')) {
    /**
     * @return mixed
     */
    function app(string $abstract = null): mixed
    {
        return null;
    }
}

if (!function_exists('config')) {
    /**
     * @param  mixed $default
     * @return mixed
     */
    function config(string $key = null, mixed $default = null): mixed
    {
        return $default;
    }
}
