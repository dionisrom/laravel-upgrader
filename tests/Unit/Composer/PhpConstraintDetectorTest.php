<?php

declare(strict_types=1);

namespace Tests\Unit\Composer;

use App\Composer\PhpConstraintDetector;
use PHPUnit\Framework\TestCase;

final class PhpConstraintDetectorTest extends TestCase
{
    private PhpConstraintDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new PhpConstraintDetector();
    }

    public function testDetectReadsPhpConstraintFromComposerJson(): void
    {
        $workspace = $this->createComposerWorkspace('^8.3');

        self::assertSame('^8.3', $this->detector->detect($workspace));
    }

    public function testDetectReturnsNullWhenComposerJsonMissing(): void
    {
        self::assertNull($this->detector->detect(sys_get_temp_dir() . '/missing-composer-' . uniqid()));
    }

    public function testSelectSupportedPhpBaseChoosesCompatibleVariant(): void
    {
        self::assertSame('8.3', $this->detector->selectSupportedPhpBase('^8.3', ['8.1', '8.2', '8.3']));
        self::assertSame('8.2', $this->detector->selectSupportedPhpBase('^8.2 || ^8.3', ['8.1', '8.2', '8.3']));
        self::assertSame('8.1', $this->detector->selectSupportedPhpBase('>=8.1 <8.4', ['8.1', '8.2', '8.3']));
    }

    public function testSelectSupportedPhpBaseReturnsNullWhenNoCandidateMatches(): void
    {
        self::assertNull($this->detector->selectSupportedPhpBase('^8.4', ['8.1', '8.2', '8.3']));
    }

    private function createComposerWorkspace(string $phpConstraint): string
    {
        $dir = sys_get_temp_dir() . '/upgrader-php-constraint-' . uniqid();
        mkdir($dir, 0755, true);

        file_put_contents($dir . '/composer.json', json_encode([
            'require' => [
                'php' => $phpConstraint,
            ],
        ], JSON_THROW_ON_ERROR));

        return $dir;
    }
}