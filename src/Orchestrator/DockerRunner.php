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
     * The host MUST copy the input workspace into $outputPath before calling
     * this method. The container receives $outputPath as /repo and transforms
     * files in-place there; on exit the populated $outputPath becomes the next
     * hop's input workspace. $workspacePath is the original pre-hop snapshot
     * retained by the host for report diffing and is NOT mounted.
     *
     * @return list<string>
     */
    public function buildCommand(Hop $hop, string $workspacePath, string $outputPath, ?UpgradeOptions $options = null): array
    {
        $cmd = [
            $this->dockerBin,
            'run', '--rm',
            '--network=none',
            '-v', "{$outputPath}:/repo:rw",
            '--env', "UPGRADER_HOP_FROM={$hop->fromVersion}",
            '--env', "UPGRADER_HOP_TO={$hop->toVersion}",
            '--env', 'UPGRADER_WORKSPACE=/repo',
        ];

        if ($options?->extraComposerCacheDir !== null) {
            $cmd[] = '-v';
            $cmd[] = "{$options->extraComposerCacheDir}:/composer-cache:rw";
            $cmd[] = '--env';
            $cmd[] = 'UPGRADER_EXTRA_COMPOSER_CACHE_DIR=/composer-cache';
        }

        if ($options?->skipDependencyUpgrader) {
            $cmd[] = '--env';
            $cmd[] = 'UPGRADER_SKIP_DEPENDENCY_UPGRADER=1';
        }

        if ($options?->skipPhpstan) {
            $cmd[] = '--env';
            $cmd[] = 'UPGRADER_SKIP_PHPSTAN=1';
        }

        if ($options?->withArtisanVerify) {
            $cmd[] = '--env';
            $cmd[] = 'UPGRADER_WITH_ARTISAN_VERIFY=1';
        }

        $cmd[] = $hop->dockerImage;

        return $cmd;
    }

    /**
     * Builds the separate network-enabled Docker command used to warm a
     * Composer cache for private VCS repositories before the isolated hop runs.
     *
     * @return list<string>
     */
    public function buildPrimerCommand(Hop $hop, string $workspacePath, string $composerCacheDir): array
    {
        return [
            $this->dockerBin,
            'run', '--rm',
            '-v', "{$workspacePath}:/repo:rw",
            '-v', "{$composerCacheDir}:/composer-cache:rw",
            '--env', 'UPGRADER_WORKSPACE=/repo',
            '--env', 'COMPOSER_CACHE_DIR=/composer-cache',
            '--entrypoint', 'php',
            $hop->dockerImage,
            '/upgrader/src/Composer/RepositoryCachePrimer.php',
            '/repo',
        ];
    }

    /**
     * Builds the separate network-enabled Docker command used to resolve
     * dependencies before the isolated hop container runs.
     *
     * @return list<string>
     */
    public function buildDependencyPreStageCommand(Hop $hop, string $workspacePath, ?UpgradeOptions $options = null): array
    {
        $command = [
            $this->dockerBin,
            'run', '--rm',
            '-v', "{$workspacePath}:/repo:rw",
            '--env', 'UPGRADER_WORKSPACE=/repo',
        ];

        if ($options?->extraComposerCacheDir !== null) {
            $command[] = '-v';
            $command[] = "{$options->extraComposerCacheDir}:/composer-cache:rw";
            $command[] = '--env';
            $command[] = 'UPGRADER_EXTRA_COMPOSER_CACHE_DIR=/composer-cache';
        }

        $command[] = '--entrypoint';
        $command[] = 'php';
        $command[] = $hop->dockerImage;
        $command[] = '/upgrader/src/Composer/DependencyUpgrader.php';
        $command[] = '/repo';
        $command[] = sprintf('--framework-target=^%s.0', $hop->toVersion);
        $command[] = '--compatibility=/upgrader/docs/package-compatibility.json';

        return $command;
    }

    /**
     * Warm a persistent Composer cache in a separate container invocation that
     * is allowed to use the network for private VCS repositories.
     */
    public function primeComposerCache(Hop $hop, string $workspacePath, string $composerCacheDir): void
    {
        $command = $this->buildPrimerCommand($hop, $workspacePath, $composerCacheDir);

        $process = $this->processFactory !== null
            ? ($this->processFactory)($command)
            : new Process($command);

        $process->setTimeout($this->timeoutSeconds);
        $process->setEnv([]);
        $process->setInput(null);
        $process->run();

        if ($process->isSuccessful()) {
            return;
        }

        $errorOutput = trim($process->getErrorOutput());
        if ($errorOutput === '') {
            $errorOutput = trim($process->getOutput());
        }

        throw new OrchestratorException(sprintf(
            'Composer cache primer failed for hop %s->%s: %s',
            $hop->fromVersion,
            $hop->toVersion,
            $errorOutput !== '' ? $errorOutput : 'unknown error',
        ));
    }

    public function runDependencyPreStage(
        Hop $hop,
        string $workspacePath,
        EventStreamer $streamer,
        ?UpgradeOptions $options = null,
    ): void {
        $command = $this->buildDependencyPreStageCommand($hop, $workspacePath, $options);

        $process = $this->processFactory !== null
            ? ($this->processFactory)($command)
            : new Process($command);

        $process->setTimeout($this->timeoutSeconds);
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

        $remaining = trim($stdoutBuffer);
        if ($remaining !== '') {
            $this->dispatchLine($remaining, $streamer);
        }

        if (!empty($stderrLines)) {
            $streamer->dispatchStderrLines($stderrLines);
        }

        $exitCode = $process->getExitCode() ?? 1;
        if ($exitCode !== 0) {
            throw new OrchestratorException(sprintf(
                'Dependency pre-stage failed for hop %s->%s: %s',
                $hop->fromVersion,
                $hop->toVersion,
                implode(' | ', array_slice($stderrLines, -5)),
            ));
        }
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
        ?UpgradeOptions $options = null,
    ): void {
        $command = $this->buildCommand($hop, $workspacePath, $outputPath, $options);

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
        $streamer->dispatch($this->normalizeEvent($decoded));
    }

    /**
     * Normalise container-side events that still use a type/data envelope.
     *
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function normalizeEvent(array $event): array
    {
        if (isset($event['event']) && is_string($event['event'])) {
            return $event;
        }

        $type = $event['type'] ?? null;
        if (!is_string($type) || $type === '') {
            return $event;
        }

        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        $normalizedEvent = str_replace('.', '_', $type);

        if ($normalizedEvent === 'manual_review_required' && isset($data['file']) && !isset($data['files'])) {
            $data['files'] = [(string) $data['file']];
        }

        if ($normalizedEvent === 'manual_review_required' && isset($data['detail']) && !isset($data['reason'])) {
            $data['reason'] = (string) $data['detail'];
        }

        if ($normalizedEvent === 'manual_review_required' && isset($data['pattern']) && !isset($data['id'])) {
            $data['id'] = (string) $data['pattern'];
        }

        return array_merge($event, $data, ['event' => $normalizedEvent]);
    }
}
