<?php

declare(strict_types=1);

namespace AppContainer\SlimSkeleton;

final class ProvidersBootstrapResult
{
    /**
     * @param string[]                       $providers          FQCN list for bootstrap/providers.php
     * @param SlimSkeletonManualReviewItem[] $manualReviewItems
     */
    public function __construct(
        public readonly bool $success,
        public readonly string|null $errorMessage,
        public readonly array $providers,
        public readonly array $manualReviewItems,
        public readonly bool $configAppExists,
    ) {}

    /**
     * @param string[]                       $providers
     * @param SlimSkeletonManualReviewItem[] $manualReviewItems
     */
    public static function success(array $providers, array $manualReviewItems): self
    {
        return new self(
            success: true,
            errorMessage: null,
            providers: $providers,
            manualReviewItems: $manualReviewItems,
            configAppExists: true,
        );
    }

    public static function noConfigApp(): self
    {
        return new self(
            success: true,
            errorMessage: null,
            providers: [],
            manualReviewItems: [],
            configAppExists: false,
        );
    }

    public static function failure(string $message): self
    {
        return new self(
            success: false,
            errorMessage: $message,
            providers: [],
            manualReviewItems: [],
            configAppExists: false,
        );
    }
}
