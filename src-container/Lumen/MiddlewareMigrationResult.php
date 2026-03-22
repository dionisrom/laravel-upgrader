<?php

declare(strict_types=1);

namespace AppContainer\Lumen;

final readonly class MiddlewareMigrationResult
{
    /**
     * @param string[] $globalMiddleware   middleware added to $middleware in Kernel
     * @param string[] $routeMiddleware    middleware added to $routeMiddleware in Kernel
     * @param LumenManualReviewItem[] $manualReviewItems
     */
    public function __construct(
        public readonly bool $success,
        public readonly int $migratedCount,
        public readonly array $globalMiddleware,
        public readonly array $routeMiddleware,
        public readonly array $manualReviewItems,
        public readonly string|null $errorMessage,
    ) {}

    /**
     * @param string[] $global
     * @param string[] $route
     * @param LumenManualReviewItem[] $items
     */
    public static function success(array $global, array $route, array $items = []): self
    {
        return new self(
            success: true,
            migratedCount: count($global) + count($route),
            globalMiddleware: $global,
            routeMiddleware: $route,
            manualReviewItems: $items,
            errorMessage: null,
        );
    }

    public static function failure(string $message): self
    {
        return new self(
            success: false,
            migratedCount: 0,
            globalMiddleware: [],
            routeMiddleware: [],
            manualReviewItems: [],
            errorMessage: $message,
        );
    }
}
