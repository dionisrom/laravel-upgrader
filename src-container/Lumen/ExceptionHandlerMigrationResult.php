<?php

declare(strict_types=1);

namespace AppContainer\Lumen;

final class ExceptionHandlerMigrationResult
{
    /**
     * @param string[] $mappedMethods    Lumen handler methods successfully mapped
     * @param LumenManualReviewItem[] $manualReviewItems
     */
    public function __construct(
        public readonly bool $success,
        public readonly array $mappedMethods,
        public readonly array $manualReviewItems,
        public readonly string|null $errorMessage,
    ) {}

    /**
     * @param string[] $mappedMethods
     * @param LumenManualReviewItem[] $manualReviewItems
     */
    public static function success(array $mappedMethods, array $manualReviewItems = []): self
    {
        return new self(
            success: true,
            mappedMethods: $mappedMethods,
            manualReviewItems: $manualReviewItems,
            errorMessage: null,
        );
    }

    public static function failure(string $message): self
    {
        return new self(
            success: false,
            mappedMethods: [],
            manualReviewItems: [],
            errorMessage: $message,
        );
    }
}
