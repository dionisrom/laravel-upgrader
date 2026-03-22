<?php

declare(strict_types=1);

namespace AppContainer\Lumen;

final readonly class ProvidersMigrationResult
{
    /**
     * @param string[] $migratedProviders  fully-qualified class names registered
     * @param LumenManualReviewItem[] $manualReviewItems
     */
    public function __construct(
        public readonly bool $success,
        public readonly int $migratedCount,
        public readonly array $migratedProviders,
        public readonly array $manualReviewItems,
        public readonly string|null $errorMessage,
    ) {}

    /**
     * @param string[] $providers
     * @param LumenManualReviewItem[] $manualReviewItems
     */
    public static function success(array $providers, array $manualReviewItems = []): self
    {
        return new self(
            success: true,
            migratedCount: count($providers),
            migratedProviders: $providers,
            manualReviewItems: $manualReviewItems,
            errorMessage: null,
        );
    }

    public static function failure(string $message): self
    {
        return new self(
            success: false,
            migratedCount: 0,
            migratedProviders: [],
            manualReviewItems: [],
            errorMessage: $message,
        );
    }
}
