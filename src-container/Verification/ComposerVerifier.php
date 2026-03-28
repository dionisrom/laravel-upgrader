<?php

declare(strict_types=1);

namespace AppContainer\Verification;

use Symfony\Component\Process\Process;

final class ComposerVerifier implements VerifierInterface
{
    /** @var callable(list<string>, string): Process */
    private $processFactory;

    /**
     * @param callable(list<string>, string): Process|null $processFactory
     */
    public function __construct(?callable $processFactory = null)
    {
        $this->processFactory = $processFactory ?? static function (array $cmd, string $cwd): Process {
            return new Process($cmd, $cwd);
        };
    }

    public function verify(string $workspacePath, VerificationContext $ctx): VerifierResult
    {
        $start = microtime(true);

        $validateProcess = ($this->processFactory)(
            [$ctx->composerBin, 'validate', '--no-check-publish', '--quiet'],
            $workspacePath,
        );
        $validateProcess->run();

        if ($validateProcess->getExitCode() !== 0) {
            $message = trim($validateProcess->getErrorOutput() ?: $validateProcess->getOutput());
            $issue   = new VerificationIssue(
                file:     $workspacePath . '/composer.json',
                line:     0,
                message:  $message !== '' ? $message : 'composer validate failed',
                severity: 'error',
            );

            return new VerifierResult(
                passed:          false,
                verifierName:    'ComposerVerifier',
                issueCount:      1,
                issues:          [$issue],
                durationSeconds: microtime(true) - $start,
            );
        }

        $installProcess = ($this->processFactory)(
            [$ctx->composerBin, 'install', '--no-interaction', '--no-ansi', '--no-audit'],
            $workspacePath,
        );
        $installProcess->run();

        if ($installProcess->getExitCode() !== 0) {
            $message = trim($installProcess->getErrorOutput() ?: $installProcess->getOutput());
            $issue   = new VerificationIssue(
                file:     $workspacePath . '/composer.json',
                line:     0,
                message:  $message !== '' ? $message : 'composer install failed',
                severity: 'error',
            );

            return new VerifierResult(
                passed:          false,
                verifierName:    'ComposerVerifier',
                issueCount:      1,
                issues:          [$issue],
                durationSeconds: microtime(true) - $start,
            );
        }

        return new VerifierResult(
            passed:          true,
            verifierName:    'ComposerVerifier',
            issueCount:      0,
            issues:          [],
            durationSeconds: microtime(true) - $start,
        );
    }
}
