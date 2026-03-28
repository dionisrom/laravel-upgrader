<?php

declare(strict_types=1);

namespace AppContainer\Lumen;

use AppContainer\Detector\FrameworkDetector;
use AppContainer\Detector\Exception\DetectionException;
use AppContainer\Lumen\Exception\LumenDetectionException;

/**
 * Detects whether a workspace is a Lumen application.
 *
 * Delegates to FrameworkDetector for dual-condition logic (TRD §10.1):
 *  - BOTH conditions met  → 'lumen'           (definitive)
 *  - ONE condition only   → 'lumen_ambiguous'  (emits warning event)
 *  - Neither condition    → 'laravel'
 */
final class LumenDetector
{
    public function __construct(
        private readonly FrameworkDetector $frameworkDetector,
    ) {}

    /**
     * @throws LumenDetectionException
     */
    public function detect(string $workspacePath): LumenDetectionResult
    {
        try {
            $framework = $this->frameworkDetector->detect($workspacePath);
        } catch (DetectionException $e) {
            throw new LumenDetectionException(
                "Lumen detection failed: {$e->getMessage()}",
                0,
                $e,
            );
        }

        $result = match ($framework) {
            'lumen' => LumenDetectionResult::definitive($workspacePath),
            'lumen_ambiguous' => $this->buildAmbiguousResult($workspacePath),
            default => LumenDetectionResult::notLumen($workspacePath),
        };

        $this->emitDetectionEvent($result);

        return $result;
    }

    private function buildAmbiguousResult(string $workspacePath): LumenDetectionResult
    {
        $composerFile = $workspacePath . '/composer.json';
        $hasPackage = false;
        if (file_exists($composerFile)) {
            $contents = (string) file_get_contents($composerFile);
            /** @var array{require?: array<string, string>, require-dev?: array<string, string>} $composer */
            $composer = json_decode($contents, true);
            $hasPackage = is_array($composer) && FrameworkDetector::composerDeclaresLumen($composer);
        }

        $bootstrapFile = $workspacePath . '/bootstrap/app.php';
        $hasBootstrap = file_exists($bootstrapFile)
            && str_contains((string) file_get_contents($bootstrapFile), 'new Laravel\Lumen\Application');

        return LumenDetectionResult::ambiguous($workspacePath, $hasPackage, $hasBootstrap);
    }

    private function emitDetectionEvent(LumenDetectionResult $result): void
    {
        $event = [
            'event'      => 'lumen_detection',
            'framework'  => $result->framework,
            'workspace'  => $result->workspacePath,
            'has_package' => $result->hasLumenPackage,
            'has_bootstrap' => $result->hasLumenBootstrap,
            'ts'         => time(),
        ];

        echo json_encode($event) . "\n";
    }
}
