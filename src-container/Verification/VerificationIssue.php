<?php

declare(strict_types=1);

namespace AppContainer\Verification;

final class VerificationIssue
{
    /**
     * @param string $severity 'error' | 'warning'
     */
    public function __construct(
        public readonly string $file,
        public readonly int    $line,
        public readonly string $message,
        public readonly string $severity,
    ) {}
}
