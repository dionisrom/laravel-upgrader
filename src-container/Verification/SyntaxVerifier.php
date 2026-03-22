<?php

declare(strict_types=1);

namespace AppContainer\Verification;

use Symfony\Component\Process\Process;

final class SyntaxVerifier implements VerifierInterface
{
    private const MAX_CONCURRENT = 8;

    public function verify(string $workspacePath, VerificationContext $ctx): VerifierResult
    {
        $start  = microtime(true);
        $files  = $this->findPhpFiles($workspacePath);
        $issues = $this->checkSyntaxParallel($files, $ctx->phpBin);

        return new VerifierResult(
            passed:          count($issues) === 0,
            verifierName:    'SyntaxVerifier',
            issueCount:      count($issues),
            issues:          $issues,
            durationSeconds: microtime(true) - $start,
        );
    }

    /**
     * @return list<string>
     */
    private function findPhpFiles(string $workspacePath): array
    {
        $files = [];

        if (!is_dir($workspacePath)) {
            return $files;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($workspacePath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
                continue;
            }

            $path = $fileInfo->getPathname();

            if (str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
                continue;
            }

            $files[] = $path;
        }

        return $files;
    }

    /**
     * @param  list<string>          $files
     * @return list<VerificationIssue>
     */
    private function checkSyntaxParallel(array $files, string $phpBin): array
    {
        $issues  = [];
        /** @var array<int, array{process: Process, file: string}> $running */
        $running = [];
        $queue   = array_values($files);
        $idx     = 0;
        $total   = count($queue);

        while ($idx < $total || count($running) > 0) {
            while (count($running) < self::MAX_CONCURRENT && $idx < $total) {
                $file    = $queue[$idx++];
                $process = new Process([$phpBin, '-l', $file]);
                $process->start();
                $running[] = ['process' => $process, 'file' => $file];
            }

            foreach ($running as $key => $item) {
                $process = $item['process'];
                $file    = $item['file'];

                if (!$process->isRunning()) {
                    unset($running[$key]);

                    if ($process->getExitCode() !== 0) {
                        $output = $process->getOutput() . $process->getErrorOutput();
                        $line   = 0;

                        if (preg_match('/on line (\d+)/', $output, $m)) {
                            $line = (int) $m[1];
                        }

                        $issues[] = new VerificationIssue(
                            file:     $file,
                            line:     $line,
                            message:  trim($output),
                            severity: 'error',
                        );
                    }
                }
            }

            if (count($running) > 0) {
                usleep(5_000);
            }
        }

        return $issues;
    }
}
