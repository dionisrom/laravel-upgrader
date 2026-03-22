<?php

declare(strict_types=1);

namespace App\Orchestrator;

use Symfony\Component\Process\Process;

final class DockerRunner
{
    /**
     * Optional factory for creating Process instances; used in testing to inject
     * fake processes without needing a running Docker daemon.
     *
     * @var (\Closure(list<string>): Process)|null
     */
    private readonly ?\Closure $processFactory;

    /**
     * @param (\Closure(list<string>): Process)|null $processFactory
     */
    public function __construct(
        private readonly string $dockerBin = 'docker',
        private readonly int $timeoutSeconds = 3600,
        ?\Closure $processFactory = null,
    ) {
        $this->processFactory = $processFactory;
    }

    /**
     * Builds the docker run command array for a given hop.
     *
     * @return list<string>
     */
    public function buildCommand(Hop $hop, string $workspacePath, string $outputPath): array
    {
        return [
            $this->dockerBin,
            'run', '--rm',
            '--network=none',
            '-v', "{$workspacePath}:/repo:rw",
            '-v', "{$outputPath}:/output:rw",
            '--env', "UPGRADER_HOP_FROM={$hop->fromVersion}",
            '--env', "UPGRADER_HOP_TO={$hop->toVersion}",
            '--env', 'UPGRADER_WORKSPACE=/repo',
            $hop->dockerImage,
        ];
    }

    /**
     * Runs the Docker container for a single hop, streaming JSON-ND events to
     * the provided EventStreamer in real time.
     *
     * @throws HopFailureException when the container exits with a non-zero code
     */
    public function run(
        Hop $hop,
        string $workspacePath,
        string $outputPath,
        EventStreamer $streamer,
    ): void {
        $command = $this->buildCommand($hop, $workspacePath, $outputPath);

        $process = $this->processFactory !== null
            ? ($this->processFactory)($command)
            : new Process($command);

        $process->setTimeout($this->timeoutSeconds);
        // Do not inherit host environment — container env is set via --env flags above.
        $process->setEnv([]);
        $process->setInput(null);

        /** @var list<string> $stderrLines */
        $stderrLines = [];
        $stdoutBuffer = '';

        $process->start();

        foreach ($process as $type => $chunk) {
            if ($type === Process::ERR) {
                foreach (explode("\n", $chunk) as $errLine) {
                    $trimmed = trim($errLine);
                    if ($trimmed !== '') {
                        $stderrLines[] = $trimmed;
                    }
                }
            } else {
                $stdoutBuffer .= $chunk;
                $stdoutBuffer = $this->flushLines($stdoutBuffer, $streamer);
            }
        }

        // Flush any partial line remaining in the buffer after the process exits.
        $remaining = trim($stdoutBuffer);
        if ($remaining !== '') {
            $this->dispatchLine($remaining, $streamer);
        }

        if (!empty($stderrLines)) {
            $streamer->dispatchStderrLines($stderrLines);
        }

        $exitCode = $process->getExitCode() ?? 1;

        if ($exitCode !== 0) {
            /** @var list<string> $lastLines */
            $lastLines = array_values(array_slice($stderrLines, -5));
            throw new HopFailureException(
                exitCode: $exitCode,
                lastStderrLines: $lastLines,
            );
        }
    }

    /**
     * Parses all complete newline-terminated lines from the buffer, dispatches
     * each as a JSON-ND event, and returns any remaining partial line.
     */
    private function flushLines(string $buffer, EventStreamer $streamer): string
    {
        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = trim(substr($buffer, 0, $pos));
            $buffer = substr($buffer, $pos + 1);

            if ($line !== '') {
                $this->dispatchLine($line, $streamer);
            }
        }

        return $buffer;
    }

    /**
     * Attempts to decode a single stdout line as JSON; dispatches the event or
     * a warning if the line is not valid JSON.
     */
    private function dispatchLine(string $line, EventStreamer $streamer): void
    {
        /** @var mixed $decoded */
        $decoded = json_decode($line, true);

        if (!is_array($decoded)) {
            $streamer->dispatchWarning("Non-JSON stdout line: {$line}");
            return;
        }

        /** @var array<string, mixed> $decoded */
        $streamer->dispatch($decoded);
    }
}
