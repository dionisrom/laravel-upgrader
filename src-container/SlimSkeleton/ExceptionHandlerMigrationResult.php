<?php

declare(strict_types=1);

namespace AppContainer\SlimSkeleton;

final class ExceptionHandlerMigrationResult
{
    /**
     * @param string[]                       $dontReport
     * @param string[]                       $dontFlash
     * @param string[]                       $reportClosures    PHP code strings for each report() branch
     * @param string[]                       $renderClosures    PHP code strings for each render() branch
     * @param SlimSkeletonManualReviewItem[] $manualReviewItems
     * @param string[]                       $backupFiles
     */
    public function __construct(
        public readonly bool $success,
        public readonly string|null $errorMessage,
        public readonly array $dontReport,
        public readonly array $dontFlash,
        public readonly array $reportClosures,
        public readonly array $renderClosures,
        public readonly array $manualReviewItems,
        public readonly array $backupFiles,
        public readonly bool $handlerFileExists,
    ) {}

    /**
     * @param string[]                       $dontReport
     * @param string[]                       $dontFlash
     * @param string[]                       $reportClosures
     * @param string[]                       $renderClosures
     * @param SlimSkeletonManualReviewItem[] $manualReviewItems
     * @param string[]                       $backupFiles
     */
    public static function success(
        array $dontReport,
        array $dontFlash,
        array $reportClosures,
        array $renderClosures,
        array $manualReviewItems,
        array $backupFiles,
    ): self {
        return new self(
            success: true,
            errorMessage: null,
            dontReport: $dontReport,
            dontFlash: $dontFlash,
            reportClosures: $reportClosures,
            renderClosures: $renderClosures,
            manualReviewItems: $manualReviewItems,
            backupFiles: $backupFiles,
            handlerFileExists: true,
        );
    }

    public static function noHandlerFile(): self
    {
        return new self(
            success: true,
            errorMessage: null,
            dontReport: [],
            dontFlash: [],
            reportClosures: [],
            renderClosures: [],
            manualReviewItems: [],
            backupFiles: [],
            handlerFileExists: false,
        );
    }

    public static function failure(string $message): self
    {
        return new self(
            success: false,
            errorMessage: $message,
            dontReport: [],
            dontFlash: [],
            reportClosures: [],
            renderClosures: [],
            manualReviewItems: [],
            backupFiles: [],
            handlerFileExists: false,
        );
    }
}
