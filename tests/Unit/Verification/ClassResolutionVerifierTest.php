<?php

declare(strict_types=1);

namespace Tests\Unit\Verification;

use AppContainer\Verification\ClassResolutionVerifier;
use AppContainer\Verification\VerificationContext;
use PHPUnit\Framework\TestCase;

final class ClassResolutionVerifierTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/class_resolution_test_' . uniqid();
        mkdir($this->tmpDir . '/app', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    public function testPassesWhenAppDirectoryIsAbsent(): void
    {
        $dir = sys_get_temp_dir() . '/class_resolution_no_app_' . uniqid();
        mkdir($dir, 0755, true);

        try {
            $ctx    = new VerificationContext(workspacePath: $dir);
            $result = (new ClassResolutionVerifier())->verify($dir, $ctx);

            self::assertTrue($result->passed);
            self::assertSame(0, $result->issueCount);
        } finally {
            rmdir($dir);
        }
    }

    public function testPassesWhenAllUsedClassesExist(): void
    {
        // PHPUnit\Framework\TestCase is guaranteed to exist
        $code = '<?php' . PHP_EOL . 'use PHPUnit\Framework\TestCase;';

        // ClassResolutionVerifier only scans `use App\...` statements, so this should pass
        file_put_contents($this->tmpDir . '/app/SomeFile.php', $code);

        $ctx    = new VerificationContext(workspacePath: $this->tmpDir);
        $result = (new ClassResolutionVerifier())->verify($this->tmpDir, $ctx);

        self::assertTrue($result->passed);
    }

    public function testDetectsUnresolvableAppNamespacedClass(): void
    {
        $code = '<?php' . PHP_EOL . 'use App\Services\NonExistentServiceXyz123;';
        file_put_contents($this->tmpDir . '/app/UsesGhost.php', $code);

        $ctx    = new VerificationContext(workspacePath: $this->tmpDir);
        $result = (new ClassResolutionVerifier())->verify($this->tmpDir, $ctx);

        self::assertFalse($result->passed);
        self::assertSame(1, $result->issueCount);
        self::assertStringContainsString('App\\Services\\NonExistentServiceXyz123', $result->issues[0]->message);
        self::assertSame('error', $result->issues[0]->severity);
    }

    public function testIgnoresNonAppNamespacedUseStatements(): void
    {
        // Vendor namespaces should not trigger issues
        $code = '<?php' . PHP_EOL . 'use Symfony\Component\Process\Process;';
        file_put_contents($this->tmpDir . '/app/VendorUse.php', $code);

        $ctx    = new VerificationContext(workspacePath: $this->tmpDir);
        $result = (new ClassResolutionVerifier())->verify($this->tmpDir, $ctx);

        self::assertTrue($result->passed);
        self::assertSame(0, $result->issueCount);
    }

    public function testReportsMultipleIssuesForMultipleUnresolvableClasses(): void
    {
        $code = implode(PHP_EOL, [
            '<?php',
            'use App\Models\GhostModelAlpha999;',
            'use App\Models\GhostModelBeta999;',
        ]);

        file_put_contents($this->tmpDir . '/app/MultiGhost.php', $code);

        $ctx    = new VerificationContext(workspacePath: $this->tmpDir);
        $result = (new ClassResolutionVerifier())->verify($this->tmpDir, $ctx);

        self::assertFalse($result->passed);
        self::assertSame(2, $result->issueCount);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
