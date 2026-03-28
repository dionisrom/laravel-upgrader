<?php

declare(strict_types=1);

namespace AppContainer\Config;

final class EnvMigrationResult
{
    /**
     * @param array<string, string> $renamedKeys  old key → new key
     * @param string[]              $addedKeys
     */
    public function __construct(
        public readonly bool $success,
        public readonly array $renamedKeys,
        public readonly array $addedKeys,
        public readonly string|null $errorMessage,
    ) {}
}
