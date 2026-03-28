<?php

declare(strict_types=1);

namespace AppContainer\SlimSkeleton;

final class KernelMigrationResult
{
    /**
     * @param string[]                       $appendedGlobalMiddleware
     * @param array<string, string[]>        $middlewareGroupDeltas  group name → appended entries
     * @param array<string, string>          $middlewareAliases      alias → FQCN (non-default only)
     * @param string[]                       $middlewarePriority
     * @param string|null                    $trustProxiesAt         comma-separated proxies or '*'
     * @param int|null                       $trustProxiesHeaders    bitfield constant
     * @param SlimSkeletonManualReviewItem[] $manualReviewItems
     * @param string[]                       $backupFiles
     */
    public function __construct(
        public readonly bool $success,
        public readonly string|null $errorMessage,
        public readonly array $appendedGlobalMiddleware,
        public readonly array $middlewareGroupDeltas,
        public readonly array $middlewareAliases,
        public readonly array $middlewarePriority,
        public readonly string|null $trustProxiesAt,
        public readonly int|null $trustProxiesHeaders,
        public readonly array $manualReviewItems,
        public readonly array $backupFiles,
        public readonly bool $kernelFileExists,
    ) {}

    /**
     * @param string[]                       $appendedGlobalMiddleware
     * @param array<string, string[]>        $middlewareGroupDeltas
     * @param array<string, string>          $middlewareAliases
     * @param string[]                       $middlewarePriority
     * @param SlimSkeletonManualReviewItem[] $manualReviewItems
     * @param string[]                       $backupFiles
     */
    public static function success(
        array $appendedGlobalMiddleware,
        array $middlewareGroupDeltas,
        array $middlewareAliases,
        array $middlewarePriority,
        string|null $trustProxiesAt,
        int|null $trustProxiesHeaders,
        array $manualReviewItems,
        array $backupFiles,
    ): self {
        return new self(
            success: true,
            errorMessage: null,
            appendedGlobalMiddleware: $appendedGlobalMiddleware,
            middlewareGroupDeltas: $middlewareGroupDeltas,
            middlewareAliases: $middlewareAliases,
            middlewarePriority: $middlewarePriority,
            trustProxiesAt: $trustProxiesAt,
            trustProxiesHeaders: $trustProxiesHeaders,
            manualReviewItems: $manualReviewItems,
            backupFiles: $backupFiles,
            kernelFileExists: true,
        );
    }

    public static function noKernelFile(): self
    {
        return new self(
            success: true,
            errorMessage: null,
            appendedGlobalMiddleware: [],
            middlewareGroupDeltas: [],
            middlewareAliases: [],
            middlewarePriority: [],
            trustProxiesAt: null,
            trustProxiesHeaders: null,
            manualReviewItems: [],
            backupFiles: [],
            kernelFileExists: false,
        );
    }

    public static function failure(string $message): self
    {
        return new self(
            success: false,
            errorMessage: $message,
            appendedGlobalMiddleware: [],
            middlewareGroupDeltas: [],
            middlewareAliases: [],
            middlewarePriority: [],
            trustProxiesAt: null,
            trustProxiesHeaders: null,
            manualReviewItems: [],
            backupFiles: [],
            kernelFileExists: false,
        );
    }
}
