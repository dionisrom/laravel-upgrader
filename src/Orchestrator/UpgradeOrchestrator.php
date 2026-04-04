<?php

declare(strict_types=1);

namespace App\Orchestrator;

use App\Workspace\WorkspaceManager;
use Ramsey\Uuid\Uuid;

final class UpgradeOrchestrator
{
    public function __construct(
        private readonly HopPlanner $hopPlanner,
        private readonly DockerRunner $dockerRunner,
        private readonly WorkspaceManager $workspaceManager,
        private readonly EventStreamer $streamer,
        private readonly ?CheckpointManagerInterface $checkpoints = null,
    ) {}

    /**
     * Executes the full upgrade pipeline for $repoPath from $fromVersion to
     * $toVersion.
     *
     * The original repository is left unmodified until every hop has passed
     * verification. Write-back happens only after all hops succeed.
     *
     * @throws OrchestratorException on planning errors, hop failures, or
     *                               verification failures
     */
    public function run(
        string $repoPath,
        string $fromVersion,
        string $toVersion,
        ?UpgradeOptions $options = null,
    ): OrchestratorResult {
        $options ??= new UpgradeOptions();
        $runId = Uuid::uuid4()->toString();

        try {
            $sequence = $this->hopPlanner->plan($fromVersion, $toVersion);
        } catch (InvalidHopException $e) {
            throw new OrchestratorException(
                sprintf('Invalid hop plan: %s', $e->getMessage()),
                0,
                $e,
            );
        }

        // Create a content-addressed, isolated copy of the source repository.
        // The original repo is never touched until writeBack() at the very end.
        $workspace = $this->workspaceManager->createWorkspace($repoPath, $toVersion);

        /** @var list<array<string, mixed>> $allEvents */
        $allEvents = [];

        // EventCollector is added once and reset between hops so we can detect
        // per-hop verification state without coupling the orchestrator to specific
        // consumer implementations.
        $collector = new EventCollector();
        $this->streamer->addConsumer($collector);

        $anyHopRan = false;
        $sharedComposerCacheDir = $options->extraComposerCacheDir;

        foreach ($sequence->hops as $hop) {
            $hopKey = sprintf('%s->%s', $hop->fromVersion, $hop->toVersion);

            if ($this->checkpoints?->isCompleted($hop) === true) {
                $this->streamer->dispatch([
                    'event' => 'hop_skipped',
                    'hop'   => $hopKey,
                    'ts'    => time(),
                ]);
                continue;
            }

            // Each hop writes transformed output to its own temp directory, which
            // becomes the input workspace for the next hop.
            $outputPath = sys_get_temp_dir()
                . DIRECTORY_SEPARATOR . 'upgrader-out'
                . DIRECTORY_SEPARATOR . hash('sha256', $runId . $hopKey);

            if (!is_dir($outputPath) && !mkdir($outputPath, 0700, true)) {
                throw new OrchestratorException(sprintf(
                    'Failed to create output directory for hop %s: %s',
                    $hopKey,
                    $outputPath,
                ));
            }

            $this->stageWorkspace($workspace, $outputPath);

            $requiresCachePrimer = $this->requiresComposerCachePrimerForHop($hop, $outputPath);
            $cacheNeedsPriming = false;

            $collector->reset();

            if ($options->dryRun) {
                $this->streamer->dispatch([
                    'event' => 'hop_dry_run',
                    'hop'   => $hopKey,
                    'ts'    => time(),
                ]);

                /** @var list<array<string, mixed>> $hopEvents */
                $hopEvents = $collector->getEvents();
                $allEvents = array_values(array_merge($allEvents, $hopEvents));
                continue;
            }

            if ($requiresCachePrimer) {
                if ($sharedComposerCacheDir === null) {
                    $sharedComposerCacheDir = $this->resolveHostComposerCacheDir();
                }

                if ($sharedComposerCacheDir === null) {
                    $sharedComposerCacheDir = $this->createSharedComposerCacheDir($runId);
                }

                $cacheNeedsPriming = $this->composerCacheNeedsPriming($sharedComposerCacheDir);

                if ($cacheNeedsPriming && ($hop->type === 'lumen' || $sharedComposerCacheDir !== null)) {
                    $this->dockerRunner->primeComposerCache($hop, $outputPath, $sharedComposerCacheDir);
                }
            }

            $hopOptions = $sharedComposerCacheDir === null
                ? $options
                : new UpgradeOptions(
                    skipPhpstan: $options->skipPhpstan,
                    withArtisanVerify: $options->withArtisanVerify,
                    reportFormats: $options->reportFormats,
                    dryRun: $options->dryRun,
                    repoLabel: $options->repoLabel,
                    extraComposerCacheDir: $sharedComposerCacheDir,
                    skipDependencyUpgrader: false,
                );

            if ($requiresCachePrimer && $hop->type !== 'lumen' && $cacheNeedsPriming) {
                $this->dockerRunner->runDependencyPreStage($hop, $outputPath, $this->streamer, $hopOptions);

                $hopOptions = new UpgradeOptions(
                    skipPhpstan: $hopOptions->skipPhpstan,
                    withArtisanVerify: $hopOptions->withArtisanVerify,
                    reportFormats: $hopOptions->reportFormats,
                    dryRun: $hopOptions->dryRun,
                    repoLabel: $hopOptions->repoLabel,
                    extraComposerCacheDir: $hopOptions->extraComposerCacheDir,
                    skipDependencyUpgrader: true,
                );
            }

            try {
                $this->dockerRunner->run($hop, $workspace, $outputPath, $this->streamer, $hopOptions);
            } catch (HopFailureException $e) {
                throw new OrchestratorException(
                    sprintf(
                        'Hop %s failed with exit code %d. Last stderr: %s',
                        $hopKey,
                        $e->getExitCode(),
                        implode(' | ', $e->getLastStderrLines()),
                    ),
                    0,
                    $e,
                );
            }

            if (!$collector->isVerificationPassed()) {
                throw new OrchestratorException(sprintf(
                    'Verification failed for hop %s: no pipeline_complete event with passed=true was received.',
                    $hopKey,
                ));
            }

            /** @var list<array<string, mixed>> $hopEvents */
            $hopEvents = $collector->getEvents();
            $allEvents = array_values(array_merge($allEvents, $hopEvents));

            $this->checkpoints?->markCompleted($hop);

            // The output of this hop becomes the input workspace for the next hop.
            $workspace = $outputPath;
            $anyHopRan = true;
        }

        // Write-back to the original repository only after all hops succeed.
        if ($anyHopRan) {
            $this->workspaceManager->writeBack($workspace, $repoPath);
        }

        return new OrchestratorResult(
            success: true,
            runId: $runId,
            hops: $sequence->hops,
            events: $allEvents,
        );
    }

    private function stageWorkspace(string $sourcePath, string $outputPath): void
    {
        $realSourcePath = realpath($sourcePath);
        if ($realSourcePath === false || !is_dir($realSourcePath)) {
            throw new OrchestratorException(sprintf(
                'Workspace staging source does not exist: %s',
                $sourcePath,
            ));
        }

        /** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $iterator */
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($realSourcePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $relativePath = substr((string) $item->getRealPath(), strlen($realSourcePath) + 1);
            $targetPath = $outputPath . \DIRECTORY_SEPARATOR . $relativePath;

            if ($item->isDir()) {
                if (!is_dir($targetPath) && !mkdir($targetPath, 0700, true)) {
                    throw new OrchestratorException(sprintf(
                        'Failed to create staged workspace directory: %s',
                        $targetPath,
                    ));
                }

                continue;
            }

            if (!copy((string) $item->getRealPath(), $targetPath)) {
                throw new OrchestratorException(sprintf(
                    'Failed to stage workspace file: %s',
                    $item->getRealPath(),
                ));
            }
        }
    }

    private function requiresComposerCachePrimerForHop(Hop $hop, string $workspacePath): bool
    {
        if ($hop->type === 'lumen') {
            return is_file($workspacePath . DIRECTORY_SEPARATOR . 'composer.json');
        }

        return $this->requiresComposerCachePrimer($workspacePath);
    }

    private function requiresComposerCachePrimer(string $workspacePath): bool
    {
        $composerJsonPath = $workspacePath . DIRECTORY_SEPARATOR . 'composer.json';
        if (!is_file($composerJsonPath)) {
            return false;
        }

        $composerJson = file_get_contents($composerJsonPath);
        if ($composerJson === false) {
            return false;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($composerJson, true);
        if (!is_array($decoded)) {
            return false;
        }

        $repositories = $decoded['repositories'] ?? null;
        if (!is_array($repositories)) {
            return false;
        }

        foreach ($repositories as $repository) {
            if (!is_array($repository)) {
                continue;
            }

            $type = $repository['type'] ?? null;
            $url = $repository['url'] ?? null;

            if (!is_string($type) || !is_string($url)) {
                continue;
            }

            if (!in_array($type, ['git', 'vcs'], true)) {
                continue;
            }

            if (!$this->isLocalComposerRepositoryUrl($url)) {
                return true;
            }
        }

        return false;
    }

    private function isLocalComposerRepositoryUrl(string $url): bool
    {
        $normalizedUrl = str_replace('\\', '/', $url);

        return str_starts_with($normalizedUrl, 'file://')
            || str_starts_with($normalizedUrl, '/')
            || str_starts_with($normalizedUrl, './')
            || str_starts_with($normalizedUrl, '../')
            || (bool) preg_match('/^[A-Za-z]:\//', $normalizedUrl);
    }

    private function createSharedComposerCacheDir(string $runId): string
    {
        $cacheDir = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'upgrader-composer-cache'
            . DIRECTORY_SEPARATOR . hash('sha256', $runId);

        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0700, true)) {
            throw new OrchestratorException(sprintf(
                'Failed to create Composer cache directory: %s',
                $cacheDir,
            ));
        }

        return $cacheDir;
    }

    private function resolveHostComposerCacheDir(): ?string
    {
        $candidates = [];

        $envComposerCacheDir = getenv('COMPOSER_CACHE_DIR');
        if (is_string($envComposerCacheDir) && trim($envComposerCacheDir) !== '') {
            $candidates[] = $envComposerCacheDir;
        }

        $localAppData = getenv('LOCALAPPDATA');
        if (is_string($localAppData) && trim($localAppData) !== '') {
            $candidates[] = $localAppData . DIRECTORY_SEPARATOR . 'Composer';
        }

        $appData = getenv('APPDATA');
        if (is_string($appData) && trim($appData) !== '') {
            $candidates[] = $appData . DIRECTORY_SEPARATOR . 'Composer';
        }

        $home = getenv('HOME');
        if (is_string($home) && trim($home) !== '') {
            $candidates[] = rtrim($home, '/\\') . DIRECTORY_SEPARATOR . '.composer';
            $candidates[] = rtrim($home, '/\\') . DIRECTORY_SEPARATOR . '.composer' . DIRECTORY_SEPARATOR . 'cache';
            $candidates[] = rtrim($home, '/\\') . DIRECTORY_SEPARATOR . '.cache' . DIRECTORY_SEPARATOR . 'composer';
        }

        foreach (array_unique($candidates) as $candidate) {
            if (!is_string($candidate) || $candidate === '' || !is_dir($candidate)) {
                continue;
            }

            if (is_dir($candidate . DIRECTORY_SEPARATOR . 'vcs')
                || is_dir($candidate . DIRECTORY_SEPARATOR . 'repo')
                || is_dir($candidate . DIRECTORY_SEPARATOR . 'files')) {
                return $candidate;
            }
        }

        return null;
    }

    private function composerCacheNeedsPriming(?string $cacheDir): bool
    {
        if ($cacheDir === null || !is_dir($cacheDir)) {
            return true;
        }

        foreach (['vcs', 'repo', 'files'] as $subdirectory) {
            $path = $cacheDir . DIRECTORY_SEPARATOR . $subdirectory;
            if (!is_dir($path)) {
                continue;
            }

            $entries = scandir($path);
            if ($entries !== false && count($entries) > 2) {
                return false;
            }
        }

        return true;
    }
}
