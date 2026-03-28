<?php

declare(strict_types=1);

namespace Tests\Unit\Rector;

use AppContainer\Rector\RectorConfigBuilder;
use PHPUnit\Framework\TestCase;

final class RectorConfigBuilderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/rector-config-builder-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $outputFile = $this->tmpDir . '/rector.php';
        if (file_exists($outputFile)) {
            unlink($outputFile);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    public function test_build_generates_valid_config_with_skip_paths(): void
    {
        $builder = new RectorConfigBuilder();
        $outputPath = $this->tmpDir . '/rector.php';

        $builder->build('/workspace', [], $outputPath);

        $content = file_get_contents($outputPath);

        // All skip paths should be in a single skip() call
        self::assertSame(1, substr_count($content, '$rectorConfig->skip('));

        // Critical: .upgrader-state must be skipped (TRD-RECTOR-004)
        self::assertStringContainsString('.upgrader-state', $content);
        self::assertStringContainsString('vendor', $content);
    }

    public function test_build_includes_rule_classes(): void
    {
        $builder = new RectorConfigBuilder();
        $outputPath = $this->tmpDir . '/rector.php';

        $builder->build('/workspace', ['App\\Rules\\MyRule'], $outputPath);

        $content = file_get_contents($outputPath);

        self::assertStringContainsString('\\App\\Rules\\MyRule::class', $content);
    }

    public function test_build_includes_sets(): void
    {
        $builder = new RectorConfigBuilder();
        $outputPath = $this->tmpDir . '/rector.php';

        $builder->build('/workspace', [], $outputPath, [
            'RectorLaravel\\Set\\LaravelSetList::LARAVEL_90',
        ]);

        $content = file_get_contents($outputPath);

        self::assertStringContainsString('$rectorConfig->sets(', $content);
        self::assertStringContainsString('\\RectorLaravel\\Set\\LaravelSetList::LARAVEL_90', $content);
        // Must NOT be a string literal — should be a constant reference
        self::assertStringNotContainsString("'\\RectorLaravel\\Set\\LaravelSetList::LARAVEL_90'", $content);
    }

    public function test_build_generates_syntactically_valid_php(): void
    {
        $builder = new RectorConfigBuilder();
        $outputPath = $this->tmpDir . '/rector.php';

        $builder->build('/workspace', ['Some\\Rule'], $outputPath);

        // php -l check
        $output = [];
        $exitCode = 0;
        exec('php -l ' . escapeshellarg($outputPath) . ' 2>&1', $output, $exitCode);

        self::assertSame(0, $exitCode, 'Generated config has syntax errors: ' . implode("\n", $output));
    }
}
