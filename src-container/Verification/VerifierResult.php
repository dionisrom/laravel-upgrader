<?php

declare(strict_types=1);

namespace AppContainer\Verification;

final class VerifierResult
{
    /**
     * @param list<VerificationIssue> $issues
     */
    public function __construct(
        public readonly bool   $passed,
        public readonly string $verifierName,
        public readonly int    $issueCount,
        public readonly array  $issues,
        public readonly float  $durationSeconds,
    ) {}
}
