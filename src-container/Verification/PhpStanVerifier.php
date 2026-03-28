<?php

declare(strict_types=1);

namespace AppContainer\Verification;

use AppContainer\EventEmitter;
use Symfony\Component\Process\Process;

final class PhpStanVerifier implements VerifierInterface
{
    /** @var callable(list<string>, string): Process */
    private $processFactory;

    /**
     * @param callable(list<string>, string): Process|null $processFactory
     */
    public function __construct(
        ?callable $processFactory = null,
        private readonly ?EventEmitter $emitter = null,
    ) {
        $this->processFactory = $processFactory ?? static function (array $cmd, string $cwd): Process {
            return new Process($cmd, $cwd);
        };
    }

    public function verify(string $workspacePath, VerificationContext $ctx): VerifierResult
    {
        $start = microtime(true);

        if ($ctx->skipPhpStan) {
            return new VerifierResult(
                passed:          true,
                verifierName:    'PhpStanVerifier',
                issueCount:      0,
                issues:          [],
                durationSeconds: microtime(true) - $start,
            );
        }

        $baselinePath = $ctx->baselinePath ?? ($workspacePath . '/.upgrader-state/phpstan-baseline.json');
        $errorCount   = $this->runPhpStan($workspacePath, $ctx);

        if (!file_exists($baselinePath)) {
            $dir = dirname($baselinePath);

            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($baselinePath, json_encode(['error_count' => $errorCount]));

            return new VerifierResult(
                passed:          true,
                verifierName:    'PhpStanVerifier',
                issueCount:      0,
                issues:          [],
                durationSeconds: microtime(true) - $start,
            );
        }

        $baseline = json_decode((string) file_get_contents($baselinePath), true);
        $preCount = (int) ($baseline['error_count'] ?? 0);

        if ($errorCount > $preCount) {
            $this->emitter?->emit('phpstan_regression', [
                'pre_error_count'  => $preCount,
                'post_error_count' => $errorCount,
            ]);

            $issue = new VerificationIssue(
                file:     '',
                line:     0,
                message:  "PHPStan regression: error count increased from {$preCount} to {$errorCount}",
                severity: 'error',
            );

            return new VerifierResult(
                passed:          false,
                verifierName:    'PhpStanVerifier',
                issueCount:      1,
                issues:          [$issue],
                durationSeconds: microtime(true) - $start,
            );
        }

        return new VerifierResult(
            passed:          true,
            verifierName:    'PhpStanVerifier',
            issueCount:      0,
            issues:          [],
            durationSeconds: microtime(true) - $start,
        );
    }

    private function runPhpStan(string $workspacePath, VerificationContext $ctx): int
    {
        $cmd = [
            $ctx->phpstanBin, 'analyse', $workspacePath,
            '--level=3', '--no-progress', '--error-format=json', '--parallel',
            '--memory-limit=1G',
        ];

        $process = ($this->processFactory)($cmd, $workspacePath);
        $process->run();

        $output = $process->getOutput();
        $json   = json_decode($output, true);

        if (!is_array($json) || !isset($json['totals'])) {
            return 0;
        }

        return (int) ($json['totals']['file_errors'] ?? 0)
            + (int) ($json['totals']['other_errors'] ?? 0);
    }
}
