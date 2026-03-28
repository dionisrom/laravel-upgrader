<?php

declare(strict_types=1);

namespace AppContainer\SlimSkeleton;

final class ConsoleKernelMigrationResult
{
    /**
     * @param string[]                       $scheduleStatements  PHP code lines extracted from schedule()
     * @param string[]                       $commandClasses      FQCN list from $commands property
     * @param SlimSkeletonManualReviewItem[] $manualReviewItems
     * @param string[]                       $backupFiles
     */
    public function __construct(
        public readonly bool $success,
        public readonly string|null $errorMessage,
        public readonly array $scheduleStatements,
        public readonly array $commandClasses,
        public readonly array $manualReviewItems,
        public readonly array $backupFiles,
        public readonly bool $consoleKernelExists,
    ) {}

    /**
     * @param string[]                       $scheduleStatements
     * @param string[]                       $commandClasses
     * @param SlimSkeletonManualReviewItem[] $manualReviewItems
     * @param string[]                       $backupFiles
     */
    public static function success(
        array $scheduleStatements,
        array $commandClasses,
        array $manualReviewItems,
        array $backupFiles,
    ): self {
        return new self(
            success: true,
            errorMessage: null,
            scheduleStatements: $scheduleStatements,
            commandClasses: $commandClasses,
            manualReviewItems: $manualReviewItems,
            backupFiles: $backupFiles,
            consoleKernelExists: true,
        );
    }

    public static function noConsoleKernel(): self
    {
        return new self(
            success: true,
            errorMessage: null,
            scheduleStatements: [],
            commandClasses: [],
            manualReviewItems: [],
            backupFiles: [],
            consoleKernelExists: false,
        );
    }

    public static function failure(string $message): self
    {
        return new self(
            success: false,
            errorMessage: $message,
            scheduleStatements: [],
            commandClasses: [],
            manualReviewItems: [],
            backupFiles: [],
            consoleKernelExists: false,
        );
    }
}
