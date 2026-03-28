<?php

declare(strict_types=1);

namespace AppContainer\Lumen;

final class LumenManualReviewItem
{
    /**
     * @param 'route'|'provider'|'middleware'|'exception_handler'|'facade'|'config'|'eloquent'|'other' $category
     * @param 'error'|'warning'|'info' $severity
     */
    public function __construct(
        public readonly string $category,
        public readonly string $file,
        public readonly int $line,
        public readonly string $description,
        public readonly string $severity,
        public readonly string|null $suggestion,
    ) {}

    public static function route(string $file, int $line, string $description, string|null $suggestion = null): self
    {
        return new self('route', $file, $line, $description, 'warning', $suggestion);
    }

    public static function provider(string $file, int $line, string $description, string|null $suggestion = null): self
    {
        return new self('provider', $file, $line, $description, 'warning', $suggestion);
    }

    public static function middleware(string $file, int $line, string $description, string|null $suggestion = null): self
    {
        return new self('middleware', $file, $line, $description, 'warning', $suggestion);
    }

    public static function exceptionHandler(string $file, int $line, string $description, string|null $suggestion = null): self
    {
        return new self('exception_handler', $file, $line, $description, 'warning', $suggestion);
    }

    public static function config(string $file, int $line, string $description, string|null $suggestion = null): self
    {
        return new self('config', $file, $line, $description, 'info', $suggestion);
    }

    /**
     * @param 'error'|'warning'|'info' $severity
     */
    public static function other(string $file, int $line, string $description, string $severity = 'info', string|null $suggestion = null): self
    {
        return new self('other', $file, $line, $description, $severity, $suggestion);
    }
}
