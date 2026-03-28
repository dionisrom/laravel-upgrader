<?php

declare(strict_types=1);

namespace Tests\Unit\Composer;

use App\Composer\FrameworkDetector;
use PHPUnit\Framework\TestCase;

final class FrameworkDetectorTest extends TestCase
{
    private FrameworkDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new FrameworkDetector();
    }

    public function testDetectsLaravelWhenNoLumenMarkersExist(): void
    {
        $workspace = $this->createWorkspace(
            composerJson: ['require' => ['laravel/framework' => '^8.0']],
            bootstrapContents: '<?php return null;' . "\n",
        );

        self::assertSame('laravel', $this->detector->detect($workspace));
    }

    public function testDetectsLumenWhenBothMarkersExist(): void
    {
        $workspace = $this->createWorkspace(
            composerJson: ['require' => ['laravel/lumen-framework' => '^8.0']],
            bootstrapContents: "<?php\n\$app = new Laravel\\Lumen\\Application(dirname(__DIR__));\nreturn \$app;\n",
        );

        self::assertSame('lumen', $this->detector->detect($workspace));
    }

    public function testDetectsAmbiguousLumenWhenOnlyComposerMarkerExists(): void
    {
        $workspace = $this->createWorkspace(
            composerJson: ['require' => ['laravel/lumen-framework' => '^8.0']],
            bootstrapContents: '<?php return null;' . "\n",
        );

        self::assertSame('lumen_ambiguous', $this->detector->detect($workspace));
    }

    public function testDetectsAmbiguousLumenWhenOnlyBootstrapMarkerExists(): void
    {
        $workspace = $this->createWorkspace(
            composerJson: ['require' => ['laravel/framework' => '^8.0']],
            bootstrapContents: "<?php\n\$app = new Laravel\\Lumen\\Application(dirname(__DIR__));\nreturn \$app;\n",
        );

        self::assertSame('lumen_ambiguous', $this->detector->detect($workspace));
    }

    /**
     * @param array<string, mixed> $composerJson
     */
    private function createWorkspace(array $composerJson, string $bootstrapContents): string
    {
        $workspace = sys_get_temp_dir() . '/upgrader-framework-' . uniqid('', true);
        mkdir($workspace, 0755, true);
        mkdir($workspace . '/bootstrap', 0755, true);

        file_put_contents($workspace . '/composer.json', json_encode($composerJson, JSON_THROW_ON_ERROR));
        file_put_contents($workspace . '/bootstrap/app.php', $bootstrapContents);

        return $workspace;
    }
}