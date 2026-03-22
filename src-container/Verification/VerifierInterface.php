<?php

declare(strict_types=1);

namespace AppContainer\Verification;

interface VerifierInterface
{
    public function verify(string $workspacePath, VerificationContext $ctx): VerifierResult;
}
