<?php

declare(strict_types=1);

namespace AppContainer\SlimSkeleton;

final class SlimSkeletonManualReviewItem
{
    public function __construct(
        public readonly string $category,
        public readonly string $file,
        public readonly int $line,
        public readonly string $description,
        public readonly string $severity,
        public readonly string|null $suggestion,
    ) {}

    public static function kernel(string $file, int $line, string $description, string $severity = 'warning', string|null $suggestion = null): self
    {
        return new self('kernel', $file, $line, $description, $severity, $suggestion);
    }

    public static function exceptionHandler(string $file, int $line, string $description, string $severity = 'warning', string|null $suggestion = null): self
    {
        return new self('exception_handler', $file, $line, $description, $severity, $suggestion);
    }

    public static function consoleKernel(string $file, int $line, string $description, string $severity = 'info', string|null $suggestion = null): self
    {
        return new self('console_kernel', $file, $line, $description, $severity, $suggestion);
    }

    public static function providers(string $file, int $line, string $description, string $severity = 'info', string|null $suggestion = null): self
    {
        return new self('providers', $file, $line, $description, $severity, $suggestion);
    }

    public static function routes(string $file, int $line, string $description, string $severity = 'warning', string|null $suggestion = null): self
    {
        return new self('routes', $file, $line, $description, $severity, $suggestion);
    }

    public static function config(string $file, int $line, string $description, string $severity = 'info', string|null $suggestion = null): self
    {
        return new self('config', $file, $line, $description, $severity, $suggestion);
    }

    public static function other(string $file, int $line, string $description, string $severity = 'info', string|null $suggestion = null): self
    {
        return new self('other', $file, $line, $description, $severity, $suggestion);
    }
}
