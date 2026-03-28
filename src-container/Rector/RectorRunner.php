<?php

declare(strict_types=1);

namespace AppContainer\Rector;

use Symfony\Component\Process\Process;

final class RectorRunner
{
    public function run(string $workspacePath, string $configPath): RectorResult
    {
        $this->emitEvent('rector_started', [
            'workspace' => $workspacePath,
            'config' => $configPath,
        ]);

        $process = new Process([
            PHP_BINARY,
            'vendor/bin/rector',
            'process',
            $workspacePath,
            '--config=' . $configPath,
            '--dry-run',
            '--output-format=json',
            '--no-progress-bar',
            '--no-diffs',
        ]);

        $process->setTimeout(600);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->emitEvent('rector_error', [
                'exit_code' => $process->getExitCode(),
                'stderr' => $process->getErrorOutput(),
            ]);

            throw RectorExecutionException::fromProcessFailure(
                (int) $process->getExitCode(),
                $process->getErrorOutput(),
            );
        }

        $result = RectorResult::fromJson($process->getOutput());

        $this->emitEvent('rector_completed', [
            'changed_files' => $result->changedFileCount(),
            'errors' => array_map(
                static fn (RectorError $e): array => [
                    'file' => $e->file,
                    'message' => $e->message,
                    'line' => $e->line,
                ],
                $result->errors,
            ),
        ]);

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function emitEvent(string $type, array $data): void
    {
        echo json_encode(['type' => $type, 'data' => $data], JSON_UNESCAPED_SLASHES) . "\n";
    }
}
