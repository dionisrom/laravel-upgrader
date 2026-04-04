<?php

declare(strict_types=1);

namespace AppContainer\Lumen;

/**
 * Detects `$app->withFacades()` and `$app->withEloquent()` calls in
 * `bootstrap/app.php` and emits appropriate JSON-ND events (TRD §10.4).
 *
 * When facades/eloquent are ABSENT from Lumen's bootstrap, they will be
 * silently available in the migrated Laravel app (Laravel enables them by
 * default). This mismatch must be flagged so developers verify behaviour.
 *
 * Emits:
 *   - `lumen_feature_disabled`  when withFacades() or withEloquent() is absent
 *   - `lumen_facade_bootstrap_detected` summary event always
 */
final class FacadeBootstrapMigrator
{
    public function __construct(
        private readonly BootstrapMethodCallDetector $methodCallDetector = new BootstrapMethodCallDetector(),
    ) {}

    public function migrate(string $workspacePath): FacadeBootstrapResult
    {
        $bootstrapFile = $workspacePath . '/bootstrap/app.php';
        if (!file_exists($bootstrapFile)) {
            $result = FacadeBootstrapResult::fromDetection(false, false);
            $this->emitSummary($result);
            return $result;
        }

        $code = (string) file_get_contents($bootstrapFile);

        $facadesFound  = $this->methodCallDetector->hasMethodCall($code, 'withFacades');
        $eloquentFound = $this->methodCallDetector->hasMethodCall($code, 'withEloquent');

        if (!$facadesFound) {
            $this->emitEvent('lumen_feature_disabled', [
                'feature'     => 'facades',
                'workspace'   => $workspacePath,
                'description' => '$app->withFacades() was NOT called — facades were disabled in Lumen '
                    . 'but WILL be available in the migrated Laravel app. Verify this is intended.',
            ]);
        }

        // Note: eloquent detection is handled by EloquentBootstrapDetector
        // to avoid duplicate lumen_feature_disabled events.

        $result = FacadeBootstrapResult::fromDetection($facadesFound, $eloquentFound);
        $this->emitSummary($result);

        return $result;
    }

    private function emitSummary(FacadeBootstrapResult $result): void
    {
        $this->emitEvent('lumen_facade_bootstrap_detected', [
            'facades_enabled'  => $result->facadesEnabled,
            'eloquent_enabled' => $result->eloquentEnabled,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function emitEvent(string $event, array $data): void
    {
        echo json_encode(['event' => $event, 'ts' => time()] + $data) . "\n";
    }
}
