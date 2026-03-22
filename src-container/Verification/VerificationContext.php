<?php

declare(strict_types=1);

namespace AppContainer\Verification;

final class VerificationContext
{
    public function __construct(
        public readonly string  $workspacePath,
        public readonly string  $phpBin             = 'php',
        public readonly string  $composerBin        = 'composer',
        public readonly string  $phpstanBin         = 'vendor/bin/phpstan',
        public readonly bool    $withArtisanVerify  = false,
        public readonly bool    $skipPhpStan        = false,
        public readonly ?string $baselinePath       = null,
    ) {}
}
