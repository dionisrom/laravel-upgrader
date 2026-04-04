<?php

declare(strict_types=1);

namespace AppContainer\Lumen;

/**
 * Detects whether Eloquent is opt-in enabled in a Lumen bootstrap/app.php
 * and verifies the database config is present (TRD §10.4 / F-08).
 *
 * Emits `lumen_feature_disabled` when `$app->withEloquent()` is absent,
 * since the migrated Laravel app will have Eloquent enabled by default.
 */
final class EloquentBootstrapDetector
{
    public function __construct(
        private readonly BootstrapMethodCallDetector $methodCallDetector = new BootstrapMethodCallDetector(),
    ) {}

    public function detect(string $workspacePath): EloquentDetectionResult
    {
        $bootstrapFile = $workspacePath . '/bootstrap/app.php';
        if (!file_exists($bootstrapFile)) {
            $result = EloquentDetectionResult::disabled();
            $this->emitResult($result, $workspacePath);
            return $result;
        }

        $code = (string) file_get_contents($bootstrapFile);
        $eloquentEnabled = $this->methodCallDetector->hasMethodCall($code, 'withEloquent');

        if (!$eloquentEnabled) {
            $result = EloquentDetectionResult::disabled();
            $this->emitEvent('lumen_feature_disabled', [
                'feature'   => 'eloquent',
                'workspace' => $workspacePath,
                'description' => '$app->withEloquent() was NOT called. '
                    . 'The migrated Laravel app enables Eloquent by default — verify database config.',
            ]);
            $this->emitResult($result, $workspacePath);
            return $result;
        }

        $dbConfigExists = file_exists($workspacePath . '/config/database.php');
        $result = EloquentDetectionResult::enabled($dbConfigExists);

        if (!$dbConfigExists) {
            $this->emitEvent('lumen_manual_review', [
                'category'    => 'eloquent',
                'file'        => $workspacePath . '/bootstrap/app.php',
                'line'        => 0,
                'description' => 'withEloquent() detected but config/database.php not found. '
                    . 'Ensure database config is migrated.',
                'severity'    => 'warning',
                'suggestion'  => 'Run InlineConfigExtractor to extract database config or manually create config/database.php.',
            ]);
        }

        $this->emitResult($result, $workspacePath);
        return $result;
    }

    private function emitResult(EloquentDetectionResult $result, string $workspacePath): void
    {
        $this->emitEvent('lumen_eloquent_detection', [
            'workspace'             => $workspacePath,
            'eloquent_enabled'      => $result->eloquentEnabled,
            'db_config_exists'      => $result->databaseConfigExists,
            'warning'               => $result->warning,
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
