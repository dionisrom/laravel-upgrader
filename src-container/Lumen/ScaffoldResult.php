<?php

declare(strict_types=1);

namespace AppContainer\Lumen;

final class ScaffoldResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $scaffoldPath,
        public readonly string|null $originalAppBootstrap,
        public readonly string|null $errorMessage,
    ) {}

    public static function success(string $scaffoldPath, string $originalAppBootstrap): self
    {
        return new self(
            success: true,
            scaffoldPath: $scaffoldPath,
            originalAppBootstrap: $originalAppBootstrap,
            errorMessage: null,
        );
    }

    public static function failure(string $scaffoldPath, string $message): self
    {
        return new self(
            success: false,
            scaffoldPath: $scaffoldPath,
            originalAppBootstrap: null,
            errorMessage: $message,
        );
    }
}
