<?php

declare(strict_types=1);

namespace AppContainer\Lumen;

final class ConfigExtractionResult
{
    /**
     * @param string[] $copiedConfigs   config names copied from Lumen config/ dir
     * @param string[] $stubbedConfigs  config names for which a stub was generated (not found in source)
     * @param LumenManualReviewItem[] $manualReviewItems
     */
    public function __construct(
        public readonly bool $success,
        public readonly array $copiedConfigs,
        public readonly array $stubbedConfigs,
        public readonly array $manualReviewItems,
        public readonly string|null $errorMessage,
    ) {}

    /**
     * @param string[] $copied
     * @param string[] $stubbed
     * @param LumenManualReviewItem[] $manualReviewItems
     */
    public static function success(array $copied, array $stubbed, array $manualReviewItems = []): self
    {
        return new self(
            success: true,
            copiedConfigs: $copied,
            stubbedConfigs: $stubbed,
            manualReviewItems: $manualReviewItems,
            errorMessage: null,
        );
    }

    public static function failure(string $message): self
    {
        return new self(
            success: false,
            copiedConfigs: [],
            stubbedConfigs: [],
            manualReviewItems: [],
            errorMessage: $message,
        );
    }
}
