<?php

declare(strict_types=1);

namespace AppContainer\SlimSkeleton;

final class RouteServiceProviderMigrationResult
{
    /**
     * @param string|null                    $webRoutes     path to web.php
     * @param string|null                    $apiRoutes     path to api.php
     * @param string|null                    $consoleRoutes path to console.php
     * @param SlimSkeletonManualReviewItem[] $manualReviewItems
     */
    public function __construct(
        public readonly bool $success,
        public readonly string|null $errorMessage,
        public readonly string|null $webRoutes,
        public readonly string|null $apiRoutes,
        public readonly string|null $consoleRoutes,
        public readonly array $manualReviewItems,
        public readonly bool $routeServiceProviderExists,
    ) {}

    /**
     * @param SlimSkeletonManualReviewItem[] $manualReviewItems
     */
    public static function success(
        string|null $webRoutes,
        string|null $apiRoutes,
        string|null $consoleRoutes,
        array $manualReviewItems,
    ): self {
        return new self(
            success: true,
            errorMessage: null,
            webRoutes: $webRoutes,
            apiRoutes: $apiRoutes,
            consoleRoutes: $consoleRoutes,
            manualReviewItems: $manualReviewItems,
            routeServiceProviderExists: true,
        );
    }

    public static function noRouteServiceProvider(): self
    {
        return new self(
            success: true,
            errorMessage: null,
            webRoutes: null,
            apiRoutes: null,
            consoleRoutes: null,
            manualReviewItems: [],
            routeServiceProviderExists: false,
        );
    }

    public static function failure(string $message): self
    {
        return new self(
            success: false,
            errorMessage: $message,
            webRoutes: null,
            apiRoutes: null,
            consoleRoutes: null,
            manualReviewItems: [],
            routeServiceProviderExists: false,
        );
    }
}
