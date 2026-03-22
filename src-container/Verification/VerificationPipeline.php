<?php

declare(strict_types=1);

namespace AppContainer\Verification;

use AppContainer\EventEmitter;

final class VerificationPipeline
{
    /**
     * @param list<VerifierInterface> $verifiers
     */
    public function __construct(
        private readonly array         $verifiers,
        private readonly ?EventEmitter $emitter = null,
    ) {}

    /**
     * Run verifiers in order, stopping on the first failure.
     *
     * @return list<VerifierResult>
     */
    public function run(string $workspacePath, VerificationContext $ctx): array
    {
        $results = [];

        foreach ($this->verifiers as $verifier) {
            $result    = $verifier->verify($workspacePath, $ctx);
            $results[] = $result;

            $this->emitter?->emit('verification_result', [
                'verifier'         => $result->verifierName,
                'passed'           => $result->passed,
                'issue_count'      => $result->issueCount,
                'duration_seconds' => $result->durationSeconds,
            ]);

            if (!$result->passed) {
                break;
            }
        }

        return $results;
    }

    /**
     * Returns true only if all results passed.
     *
     * @param list<VerifierResult> $results
     */
    public function passed(array $results): bool
    {
        foreach ($results as $result) {
            if (!$result->passed) {
                return false;
            }
        }

        return true;
    }
}
